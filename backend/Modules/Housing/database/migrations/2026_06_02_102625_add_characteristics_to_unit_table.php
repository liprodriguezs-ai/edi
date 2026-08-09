<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a free-form `characteristics` JSON column to `unit` (§6.5 r5).
 *
 * This is the custom-attributes field for cluster-specific data (e.g. cisterna,
 * garden m2, …), distinct from the predefined unit_feature pivot which stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit', function (Blueprint $table) {
            $table->json('characteristics')->nullable()->after('notes')->comment('Free-form JSON object for cluster-specific unit attributes (e.g. cistern, garden m2)');
        });
    }

    public function down(): void
    {
        Schema::table('unit', function (Blueprint $table) {
            $table->dropColumn('characteristics');
        });
    }
};
