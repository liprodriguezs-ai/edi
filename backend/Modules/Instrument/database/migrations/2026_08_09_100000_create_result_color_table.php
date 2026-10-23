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
     * CENTRAL support catalog (D2/D3), NOT versioned: green/yellow/red is
     * vocabulary shared by every instrument edition. Fixed UUIDs, seeded once
     * by CatalogSeeder. Will be reused by Screening (screening.result_color_id).
     */
    public function up(): void
    {
        Schema::create('result_color', function (Blueprint $table) {
            $table->comment('Central semaphore color catalog (green/yellow/red); shared vocabulary across every instrument edition, not versioned');
            BaseModel::columns($table, false);
            $table->string('key')->unique()->comment("Machine-readable slug ('green'/'yellow'/'red').");
            $table->string('name')->comment('Human-readable name shown in the UI (Verde/Amarillo/Rojo).');
            $table->smallInteger('sort_order')->comment('Display order (green, yellow, red).');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('result_color');
    }
};
