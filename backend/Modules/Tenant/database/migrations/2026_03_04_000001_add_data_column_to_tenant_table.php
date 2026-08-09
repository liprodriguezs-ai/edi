<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Stancl BaseTenant uses HasDataColumn (VirtualColumn) which requires a 'data' JSON column.
     */
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            if (Schema::hasColumn('tenant', 'data')) {
                return;
            }
            $table->json('data')->nullable()->after('tenancy_db_name')->comment('JSON bag used by Stancl BaseTenant HasDataColumn (VirtualColumn) for extra tenant attributes.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn('data');
        });
    }
};
