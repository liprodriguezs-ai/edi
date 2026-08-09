<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('country', function (Blueprint $table) {
            BaseModel::columns($table);
            $table->string('name')->comment('Official country name');
            $table->string('code')->nullable()->comment('ISO 3166-1 alpha-2 country code (e.g. MX, US)');
            $table->string('phone_code')->nullable()->comment('International dialing prefix (e.g. +52 for Mexico)');
            $table->string('currency')->nullable()->comment('ISO 4217 currency code used in this country (e.g. MXN)');
            $table->string('currency_symbol')->nullable()->comment('Currency symbol for display (e.g. $, €)');
        });

        Schema::table('user', function (Blueprint $table) {
            $table->foreignUuid('country_id')->nullable()->constrained('country')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropForeign(['country_id']);
            $table->dropColumn('country_id');
        });
        Schema::dropIfExists('country');
    }
};
