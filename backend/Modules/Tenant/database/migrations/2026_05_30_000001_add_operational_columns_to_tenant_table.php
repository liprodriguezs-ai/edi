<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->string('timezone')->default('America/Mexico_City')->after('data')->comment('IANA timezone identifier for the cluster (e.g. America/Mexico_City).');
            $table->string('currency', 3)->default('MXN')->after('timezone')->comment('ISO 4217 currency code used by the cluster (e.g. MXN).');
            $table->string('type')->nullable()->after('currency')->comment('Physical layout type of the cluster: horizontal, vertical, or mixed.');
            $table->boolean('uses_ownership_share')->default(false)->after('type')->comment('Whether charges are distributed proportionally by unit ownership_share (indiviso).');
        });
    }

    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'currency', 'type', 'uses_ownership_share']);
        });
    }
};
