<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * S8: consumer-adds-column pattern (mirrors Projects'
     * add_community_project_id_to_charge_table) — AmenityReservations owns this
     * migration so Collections stays untouched. Set when a charge was created
     * by a confirmed reservation earmark payment (AmenityChargeService).
     */
    public function up(): void
    {
        Schema::table('charge', function (Blueprint $table) {
            $table->uuid('amenity_reservation_id')->nullable()->index()->after('community_project_id')->comment('FK to amenity_reservation; set when the charge was created by a confirmed reservation earmark payment.');
            $table->foreign('amenity_reservation_id')
                ->references('id')
                ->on('amenity_reservation')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('charge', function (Blueprint $table) {
            $table->dropForeign(['amenity_reservation_id']);
            $table->dropColumn('amenity_reservation_id');
        });
    }
};
