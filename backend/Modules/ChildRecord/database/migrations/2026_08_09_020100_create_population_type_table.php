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
     * CENTRAL catalog (D4): fixed UUIDs, seeded once by CatalogSeeder. The
     * Secretaría de Salud export branches on this dimension.
     */
    public function up(): void
    {
        Schema::create('population_type', function (Blueprint $table) {
            $table->comment('Central population-type catalog (Secretaría de Salud export branches on it)');
            BaseModel::columns($table, false);
            $table->string('key')->unique()->comment("Machine-readable slug used in code ('urban'/'rural'/'indigenous').");
            $table->string('name')->comment('Human-readable name shown in the UI (Urbana/Rural/Indígena).');
            $table->string('description')->nullable()->comment('Optional free-text description.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('population_type');
    }
};
