<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Stancl tenancy persists the generated database name via setInternal('db_name'), which writes to tenancy_db_name.
     */
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->string('tenancy_db_name')->nullable()->after('name')->comment('Internal database name persisted by Stancl Tenancy via setInternal(\'db_name\').');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn('tenancy_db_name');
        });
    }
};
