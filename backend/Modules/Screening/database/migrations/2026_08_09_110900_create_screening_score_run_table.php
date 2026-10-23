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
     * screening_score_run is the immutable history of every engine
     * execution over a screening (design D7/D8/D9): authoritative runs
     * update the header, non-authoritative (simulation) runs never do. Rows
     * are never updated or deleted.
     */
    public function up(): void
    {
        Schema::create('screening_score_run', function (Blueprint $table) {
            $table->comment('Immutable history of every engine execution over a screening');
            BaseModel::columns($table, true);
            $table->uuid('screening_id')->index()->comment('FK to screening');
            $table->uuid('instrument_version_id')->index()->comment('Version used for this run; equals the screening version when is_authoritative (D8)');
            $table->boolean('is_authoritative')->comment('True when this run updated the screening header; false for a what-if simulation');
            $table->string('trigger')->comment("Origin of the run: 'initial'|'rescore'|'simulation'");
            $table->text('reason')->nullable()->comment('Mandatory reason for rescore/simulation runs; NULL for the initial score');
            $table->string('engine_version', 20)->comment('ScreeningScoreService::ENGINE_VERSION that produced this run (D10)');
            $table->char('rules_fingerprint', 64)->comment("This run's instrument_version.content_hash at run time (D9)");
            $table->jsonb('input_payload')->comment('Natural-key serialized ScoringInput used for this run');
            $table->jsonb('result_payload')->comment('ScoringResult::toArray() verbatim (REQ-SCO-009)');
            $table->uuid('result_color_id')->comment('Computed global color of this run');
        });

        DB::statement(
            'CREATE INDEX screening_score_run_screening_created_idx ON screening_score_run (screening_id, created_at)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS screening_score_run_screening_created_idx');
        Schema::dropIfExists('screening_score_run');
    }
};
