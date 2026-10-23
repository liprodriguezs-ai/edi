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
     * CENTRAL catalog (D4): fixed UUIDs, seeded once by CatalogSeeder. Areas of
     * development stimulated in the core activity of a session (sensorial,
     * cognitiva, socioafectiva, lenguaje, motora).
     */
    public function up(): void
    {
        Schema::create('stimulation_area', function (Blueprint $table) {
            $table->comment('Central catalog of stimulation areas worked in the core activity of a session (fixed UUIDs)');
            BaseModel::columns($table, false);
            $table->string('key')->unique()->comment("Machine-readable slug ('sensory'/'cognitive'/'socio_affective'/'language'/'motor').");
            $table->string('name')->comment('Human-readable area name shown in the UI.');
            $table->string('description')->nullable()->comment('Optional free-text description.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stimulation_area');
    }
};
