<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * S9 — links an admin-imposed surcharge (incident/damage charge) to the
     * standalone OPEN Collections charge it creates on the reservation's
     * unit. CRITICAL: that charge NEVER carries `amenity_reservation_id` —
     * that column belongs exclusively to the reservation's OWN cost/deposit
     * charges (read by AmenityReservationChargeApplicationObserver to sync
     * amount_outstanding). A surcharge is a separate, real open debt; the
     * ONLY link between a reservation and its surcharge charges is this
     * table.
     */
    public function up(): void
    {
        Schema::create('reservation_surcharge', function (Blueprint $table) {
            $table->comment('An admin-imposed surcharge (incident/damage charge) billed against a reservation\'s unit; links the reservation to the standalone Collections charge it created.');
            BaseModel::columns($table, true);

            $table->uuid('reservation_id')->comment('FK to amenity_reservation; the reservation the incident is billed against.');
            $table->foreign('reservation_id')->references('id')->on('amenity_reservation')->cascadeOnDelete();

            $table->uuid('charge_id')->comment('FK to charge; the OPEN standalone charge created on the unit for this surcharge (never carries amenity_reservation_id).');
            $table->foreign('charge_id')->references('id')->on('charge')->restrictOnDelete();

            $table->uuid('charge_concept_id')->comment('FK to charge_concept; the tenant concept the admin selected for this surcharge.');
            $table->foreign('charge_concept_id')->references('id')->on('charge_concept')->restrictOnDelete();

            $table->bigInteger('amount')->comment('Minor units. Snapshot of the surcharge amount charged.');
            $table->string('description')->nullable()->comment('Free-text note from the admin describing the incident.');

            $table->uuid('created_by')->comment('Central User (admin) who created the surcharge. Bare UUID, NO FK — matches the project convention for referencing the central User table from tenant-scoped rows (stancl-tenancy skill §9).');

            $table->index('reservation_id');
            $table->index('charge_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservation_surcharge');
    }
};
