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
     * screening_area_result is engine output only (REQ-SCR-003): one row per
     * trace area, pooled counts and the applied thresholds it was scored
     * against (design D7).
     */
    public function up(): void
    {
        Schema::create('screening_area_result', function (Blueprint $table) {
            $table->comment('Per-area engine result with pooled counts and applied thresholds');
            BaseModel::columns($table, true);
            $table->uuid('screening_id')->index()->comment('FK to screening');
            $table->uuid('development_area_id')->index()->comment('FK to development_area (semaphore area)');
            $table->uuid('result_color_id')->comment('Engine-computed color for this area');
            $table->smallInteger('red_count')->comment('Pooled red answers (own + alternate)');
            $table->smallInteger('yellow_count')->comment('Pooled yellow answers (own + alternate)');
            $table->smallInteger('green_count')->comment('Pooled green answers (own + alternate)');
            $table->smallInteger('answered_own_count')->comment('Number of own-group items answered');
            $table->smallInteger('answered_alternate_count')->comment('Number of alternate-group items answered');
            $table->smallInteger('red_threshold')->comment('area_scoring_rule red_threshold applied');
            $table->smallInteger('green_threshold')->nullable()->comment('area_scoring_rule green_threshold applied; NULL when the rule has none');
            $table->boolean('allows_yellow')->comment('area_scoring_rule allows_yellow flag applied');
        });

        DB::statement(
            'CREATE UNIQUE INDEX screening_area_result_area_unique ON screening_area_result (screening_id, development_area_id) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS screening_area_result_area_unique');
        Schema::dropIfExists('screening_area_result');
    }
};
