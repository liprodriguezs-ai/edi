<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Versioned content (D4): semaphore thresholds for one (group, area).
     * The future engine evaluates the 4-branch contract of design D12:
     * reds>=red_threshold -> RED; elif green_threshold NOT NULL and
     * greens>=green_threshold -> GREEN; elif allows_yellow -> YELLOW; else
     * GREEN. ALE (scoring_kind=counter) has NO rows -- never semaphore-scored.
     */
    public function up(): void
    {
        Schema::create('area_scoring_rule', function (Blueprint $table) {
            $table->comment('Semaphore thresholds for one (group, area); the engine evaluates the 4-branch contract of design D12');
            BaseModel::columns($table, false);
            $table->uuid('instrument_version_id')->index()->comment('Owning version.');
            $table->uuid('age_group_id')->index()->comment('Group this rule applies to.');
            $table->uuid('development_area_id')->index()->comment('Area this rule applies to.');
            $table->smallInteger('item_count')->comment('Real number of items in this (group, area); must match the seeded item bank exactly.');
            $table->smallInteger('red_threshold')->comment('Minimum red-colored answers to resolve RED.');
            $table->smallInteger('green_threshold')->nullable()->comment('Minimum green-colored answers to resolve GREEN; NULL = no green branch (e.g. alternate sets, which have no green options).');
            $table->boolean('allows_yellow')->comment('Whether a YELLOW outcome is reachable for this (group, area).');
            $table->string('source')->comment("Provenance tag: 'legacy_js' (real legacy code) | 'legacy_js_commented' (superseded, unused after design.md §13) | 'extrapolated' (superseded, unused after §13) | 'oracle_confirmed' (design.md §13 empirical resolution) | 'manual'.");
            $table->string('notes')->nullable()->comment('Free-text provenance note.');
        });

        DB::statement(
            'CREATE UNIQUE INDEX area_scoring_rule_group_area_unique ON area_scoring_rule (age_group_id, development_area_id) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS area_scoring_rule_group_area_unique');
        Schema::dropIfExists('area_scoring_rule');
    }
};
