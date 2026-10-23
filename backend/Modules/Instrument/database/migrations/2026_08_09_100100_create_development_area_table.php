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
     * CENTRAL support catalog (D2/D3), NOT versioned: MG means "motriz gruesa"
     * in every edition. scoring_kind selects how the future engine aggregates
     * the area (semaphore vs plain counter, D12); is_development_axis flags
     * the 5 areas whose alternates are seeded (MG/MF/LE/SO/CO).
     */
    public function up(): void
    {
        Schema::create('development_area', function (Blueprint $table) {
            $table->comment('Central development-area catalog (MG/MF/LE/SO/CO/EN/ALA/ALE); shared vocabulary across every instrument edition, not versioned');
            BaseModel::columns($table, false);
            $table->string('code')->unique()->comment('Verbatim area code as used in item codes and rule conditions (MG/MF/LE/SO/CO/EN/ALA/ALE).');
            $table->string('name')->comment('Human-readable name shown in the UI (Motriz gruesa, Motriz fina, ...).');
            $table->string('scoring_kind')->comment("How the future engine aggregates this area: 'semaphore' (red/yellow/green via area_scoring_rule) or 'counter' (plain tally, e.g. ALE).");
            $table->boolean('is_development_axis')->comment('True for MG/MF/LE/SO/CO: the areas whose alternate age groups are seeded.');
            $table->smallInteger('sort_order')->comment('Display order.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('development_area');
    }
};
