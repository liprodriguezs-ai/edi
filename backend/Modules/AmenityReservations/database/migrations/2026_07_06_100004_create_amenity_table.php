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
     */
    public function up(): void
    {
        Schema::create('amenity', function (Blueprint $table) {
            $table->comment('Reservable cluster amenity (court, event hall, pool, etc.) with its booking rules, cost/deposit, and gallery.');
            BaseModel::columns($table, true);

            $table->string('name')->comment('Amenity name shown to residents (e.g. "Salón de eventos").');
            $table->text('description')->nullable()->comment('Detailed description shown in the resident catalog.');
            $table->text('restrictions')->nullable()->comment('Free-text usage restrictions (e.g. max guests, no alcohol).');
            $table->integer('capacity')->comment('Maximum concurrent occupancy/attendees allowed.');
            $table->json('available_weekdays')->comment('JSON array of weekdays (0=Sunday..6=Saturday) the amenity can be booked.');
            $table->text('rules_text')->nullable()->comment('Rules the resident must accept before reserving.');
            $table->boolean('out_of_service')->default(false)->comment('When true, the amenity cannot be reserved regardless of availability.');
            $table->string('out_of_service_reason')->nullable()->comment('Reason shown to residents while out_of_service is true.');

            // Money (minor units) — cost + deposit
            $table->boolean('has_cost')->default(false)->comment('Whether reserving this amenity charges a fee.');
            $table->bigInteger('cost_amount')->nullable()->comment('Minor units. Reservation fee snapshot source; null when has_cost is false.');
            $table->boolean('requires_deposit')->default(false)->comment('Whether a refundable deposit is required to reserve.');
            $table->bigInteger('deposit_amount')->nullable()->comment('Minor units. Refundable deposit amount; null when requires_deposit is false.');
            $table->integer('deposit_due_days')->nullable()->comment('Days after confirmation by which the deposit balance must be paid.');

            $table->uuid('bank_account_id')->nullable()->comment('FK to bank_account; destination account for payments made for this amenity.');
            $table->foreign('bank_account_id')->references('id')->on('bank_account')->nullOnDelete();

            $table->boolean('requires_approval')->default(false)->comment('Whether an admin/board member must approve the reservation before it proceeds.');

            $table->uuid('reservation_mode_id')->comment('FK to reservation_mode (by_day or by_slot).');
            $table->foreign('reservation_mode_id')->references('id')->on('reservation_mode');

            $table->uuid('payment_timing_id')->comment('FK to payment_timing (pay_later or pay_upfront).');
            $table->foreign('payment_timing_id')->references('id')->on('payment_timing');
        });

        // Unique: tenant + name (alive rows only). Plain unique(['tenant_id','name'])
        // would collide with the soft-delete-aware uniqueness check in
        // AmenityCreateRequest/AmenityUpdateRequest (Rule::unique(...)->whereNull('deleted_at')):
        // a request could pass validation after soft-deleting an amenity and
        // reusing its name, then hit a raw SQLSTATE 23505 on INSERT. Mirrors
        // Providers' provider_tenant_name_unique / ChargeConcept's
        // charge_concept_tenant_code_unique partial-index precedent.
        DB::statement(
            'CREATE UNIQUE INDEX amenity_tenant_name_unique ON "amenity" '
            .'(tenant_id, name) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS amenity_tenant_name_unique');
        Schema::dropIfExists('amenity');
    }
};
