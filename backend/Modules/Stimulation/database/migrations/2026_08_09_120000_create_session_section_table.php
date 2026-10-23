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
     * CENTRAL catalog (D4): fixed UUIDs, seeded once by CatalogSeeder. Preserves
     * the official 4-section vocabulary of the stimulation session (integración,
     * preparación, actividad central, relajación) for rendering/export labels.
     * No FK consumer in v1 (design §8 Q3).
     */
    public function up(): void
    {
        Schema::create('session_section', function (Blueprint $table) {
            $table->comment('Central catalog of the 4 official stimulation session sections (fixed UUIDs, rendering/export vocabulary)');
            BaseModel::columns($table, false);
            $table->string('key')->unique()->comment("Machine-readable slug ('integration'/'preparation'/'core'/'relaxation').");
            $table->string('name')->comment('Human-readable section name shown in the UI.');
            $table->smallInteger('sort_order')->comment('Official display order of the section (1-4).');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('session_section');
    }
};
