<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * CENTRAL catalog (D4): fixed UUIDs, seeded once by CatalogSeeder. Screening
     * rules and the CURP sex-character cross-check branch on these rows, so they
     * are read-only for tenant roles.
     */
    public function up(): void
    {
        Schema::create('sex', function (Blueprint $table) {
            $table->comment('Central sex catalog for children (fixed UUIDs; screening rules and official exports branch on it)');
            BaseModel::columns($table, false);
            $table->string('key')->unique()->comment("Machine-readable slug used in code ('male'/'female').");
            $table->string('name')->comment('Human-readable name shown in the UI (Masculino/Femenino).');
            $table->string('description')->nullable()->comment('Optional free-text description.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sex');
    }
};
