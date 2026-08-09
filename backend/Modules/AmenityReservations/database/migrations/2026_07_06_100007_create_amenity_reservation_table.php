<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * S5 (ahead of S6's creation logic): AmenityAvailabilityService must query
     * existing reservations to compute occupancy, so this table lands here
     * even though ReservationService::create()/the FSM (S6/S7) do not exist
     * yet. Money columns are cost/deposit SNAPSHOTS taken at creation time —
     * later edits to amenity.cost_amount/deposit_amount never retroactively
     * change an already-placed reservation.
     */
    public function up(): void
    {
        Schema::create('amenity_reservation', function (Blueprint $table) {
            $table->comment('A resident booking of an amenity for a specific date (and, in by_slot mode, a specific slot). Snapshots cost/deposit at creation time and tracks the FSM/payment lifecycle.');
            BaseModel::columns($table, true);

            $table->uuid('amenity_id')->comment('FK to amenity being reserved.');
            $table->foreign('amenity_id')->references('id')->on('amenity')->cascadeOnDelete();

            $table->uuid('unit_id')->comment('FK to unit; the unit on whose behalf the reservation is made.');
            $table->foreign('unit_id')->references('id')->on('unit')->restrictOnDelete();

            $table->uuid('user_id')->comment('Central User (resident) who created the reservation. Bare UUID, NO FK — matches the project convention for referencing the central User table from tenant-scoped rows (stancl-tenancy skill §9).');

            $table->date('reservation_date')->comment('Calendar date (tenant-local) being reserved.');

            $table->uuid('slot_id')->nullable()->comment('FK to amenity_slot; set only when the amenity operates in by_slot mode. Null for by_day reservations.');
            $table->foreign('slot_id')->references('id')->on('amenity_slot')->nullOnDelete();

            $table->timestamp('start_at')->nullable()->comment('Materialized UTC start instant of the reservation window (date + slot start_time, parsed in tenant timezone). Null for by_day reservations without a specific time window.');
            $table->timestamp('end_at')->nullable()->comment('Materialized UTC end instant of the reservation window. Null for by_day reservations without a specific time window.');

            $table->uuid('reservation_status_id')->comment('FK to the central reservation_status FSM catalog.');
            $table->foreign('reservation_status_id')->references('id')->on('reservation_status');

            $table->boolean('holds_slot')->default(true)->comment('True while this reservation occupies the amenity/date (by_day) or amenity/slot/date (by_slot) — the double-booking predicate (partial unique index + occupancy COUNT) keys on this flag. Set to false on cancel/reject so the date/slot frees up for re-booking.');

            $table->uuid('approved_by')->nullable()->comment('Central User (admin/board member) who approved the reservation. Bare UUID, NO FK.');
            $table->timestamp('approved_at')->nullable()->comment('When the reservation was approved.');
            $table->string('rejected_reason')->nullable()->comment('Free-text reason shown to the resident when the reservation is rejected.');

            $table->uuid('cancelled_by')->nullable()->comment('Central User who cancelled the reservation. Bare UUID, NO FK.');
            $table->timestamp('cancelled_at')->nullable()->comment('When the reservation was cancelled.');
            $table->string('cancellation_reason')->nullable()->comment('Free-text reason for the cancellation.');

            $table->timestamp('rules_accepted_at')->nullable()->comment('When the resident accepted the amenity rules_text at reservation time.');

            // Money (minor units) — snapshots taken at creation time.
            $table->bigInteger('cost_amount')->nullable()->comment('Minor units. Snapshot of amenity.cost_amount at reservation time; null when the amenity has no cost.');
            $table->bigInteger('deposit_amount')->nullable()->comment('Minor units. Snapshot of amenity.deposit_amount at reservation time; null when no deposit is required.');
            $table->bigInteger('amount_outstanding')->default(0)->comment('Minor units. Remaining balance = cost_amount (the deposit is only the minimum first installment ON that cost, never a separate garantía); decremented as earmarked payments are confirmed.');
            $table->date('balance_due_date')->nullable()->comment('Date by which amount_outstanding must be paid (e.g. deposit-only confirmations awaiting the balance).');
            $table->timestamp('payment_deadline')->nullable()->comment('Deadline by which the first earmarked payment must be received before the reservation auto-cancels (pending_payment expiry).');

            $table->index(['tenant_id', 'amenity_id', 'reservation_date'], 'amenity_reservation_amenity_date_idx');
            $table->index(['amenity_id', 'slot_id', 'reservation_date'], 'amenity_reservation_slot_date_idx');
            $table->index('unit_id');
            $table->index('user_id');
            $table->index('reservation_status_id');
        });

        // Double-booking guard for BY_DAY reservations only (slot_id IS NULL):
        // at most ONE row can hold the slot for a given (tenant, amenity, date)
        // at a time. Partial so a soft-deleted or released (holds_slot=false)
        // reservation never blocks a new booking. Mirrors Collections'
        // charge_batch_period_unique / receipt_sequence_tenant_year_unique
        // partial-unique-index style.
        //
        // `slot_id IS NULL` deliberately scopes this index to by_day mode: a
        // by_slot amenity's reservations ALWAYS carry a slot_id, so they never
        // match this predicate and are never limited to "one per amenity per
        // day" — their capacity (≤ N concurrent holds per amenity/slot/date) is
        // NOT expressible as a unique index; it is enforced in the create
        // service (S6) via a row lock + COUNT under transaction, using the
        // amenity_reservation_slot_date_idx above.
        DB::statement(
            'CREATE UNIQUE INDEX amenity_reservation_by_day_unique ON "amenity_reservation" '
            .'(tenant_id, amenity_id, reservation_date) '
            .'WHERE holds_slot AND slot_id IS NULL AND deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS amenity_reservation_by_day_unique');
        Schema::dropIfExists('amenity_reservation');
    }
};
