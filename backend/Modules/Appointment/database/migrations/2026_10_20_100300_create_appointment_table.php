<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Status UUIDs that still occupy the practitioner's time. Duplicated from
     * AppointmentStatus on purpose: a migration must not depend on model code
     * that can change after it ran.
     */
    private const OCCUPYING_STATUSES = [
        'ed600001-0000-4000-8000-000000000001', // scheduled
        'ed600001-0000-4000-8000-000000000002', // confirmed
        'ed600001-0000-4000-8000-000000000003', // arrived
    ];

    /**
     * An appointment is the administrative booking; the clinical encounter stays
     * in `visit` and is created only when care starts (visit_id). Every lifecycle
     * field is service-derived, never client-writable.
     */
    public function up(): void
    {
        Schema::create('appointment', function (Blueprint $table) {
            $table->comment('Agenda booking for a child; gives birth to the immutable visit when care starts');
            BaseModel::columns($table, true);
            $table->uuid('child_id')->index()->comment('FK to child being seen');
            $table->uuid('health_center_id')->index()->comment('FK to health_center where the appointment takes place');
            $table->uuid('practitioner_id')->index()->comment('FK to practitioner_profile who attends; required at booking');
            $table->uuid('appointment_type_id')->comment('FK to central appointment_type');
            $table->uuid('appointment_status_id')->index()->comment('FK to central appointment_status; service-derived, never client-writable');
            $table->timestampTz('starts_at')->comment('Booked start instant (UTC)');
            $table->timestampTz('ends_at')->comment('Booked end instant (UTC), exclusive');
            $table->boolean('is_walk_in')->default(false)->comment('True when registered on arrival without a prior booking; walk-ins never block the agenda');
            $table->timestampTz('confirmed_at')->nullable()->comment('Moment the caregiver confirmed attendance');
            $table->timestampTz('arrived_at')->nullable()->comment('Moment front desk marked the child as arrived (waiting-list order)');
            $table->timestampTz('started_at')->nullable()->comment('Moment care started and the visit was created');
            $table->timestampTz('cancelled_at')->nullable()->comment('Moment the appointment was cancelled');
            $table->text('cancellation_reason')->nullable()->comment('Mandatory reason captured on cancel');
            $table->timestampTz('no_show_at')->nullable()->comment('Moment the appointment was marked as no-show');
            $table->uuid('visit_id')->nullable()->comment('FK to the visit created when care started; NULL until fulfilled');
            $table->uuid('follow_up_id')->nullable()->index()->comment('FK to the follow_up this appointment fulfils, when booked from a suggestion');
            $table->text('notes')->nullable()->comment('Front-desk notes (e.g. what to bring)');
        });

        DB::statement(
            'CREATE INDEX appointment_tenant_starts_idx ON appointment (tenant_id, starts_at)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX appointment_visit_unique ON appointment (visit_id) '
            .'WHERE visit_id IS NOT NULL AND deleted_at IS NULL'
        );

        // No double booking: two occupying, non-walk-in appointments of the same
        // practitioner may not overlap. Enforced by the database so concurrent
        // requests cannot race past an application-level check. '[)' lets
        // back-to-back appointments touch.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        $statuses = implode(', ', array_map(fn (string $id): string => "'{$id}'::uuid", self::OCCUPYING_STATUSES));
        DB::statement(
            'ALTER TABLE appointment ADD CONSTRAINT appointment_practitioner_no_overlap '
            ."EXCLUDE USING gist (tenant_id WITH =, practitioner_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) "
            ."WHERE (deleted_at IS NULL AND is_walk_in = false AND appointment_status_id IN ({$statuses}))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE appointment DROP CONSTRAINT IF EXISTS appointment_practitioner_no_overlap');
        DB::statement('DROP INDEX IF EXISTS appointment_visit_unique');
        DB::statement('DROP INDEX IF EXISTS appointment_tenant_starts_idx');
        Schema::dropIfExists('appointment');
    }
};
