<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // charge_status table must exist (created earlier in this batch).
        Schema::table('unit', function (Blueprint $table) {
            $table->uuid('charge_status_id')->nullable()->after('unit_status_id')->comment('Aggregate worst-case charge status for the unit; FK to charge_status.');
            $table->foreign('charge_status_id')->references('id')->on('charge_status')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unit', function (Blueprint $table) {
            $table->dropForeign(['charge_status_id']);
            $table->dropColumn('charge_status_id');
        });
    }
};
