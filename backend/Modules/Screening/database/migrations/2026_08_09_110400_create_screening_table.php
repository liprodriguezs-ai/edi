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
     * screening is the header of an applied EDI test (design §3, D2/D3/D9):
     * frozen instrument_version pointer, both age-group FKs (chronological
     * and post-corrected-age substitution), and the server-computed result.
     * Every result field is written exclusively by services, never by a
     * write endpoint (REQ-SCR-003).
     */
    public function up(): void
    {
        Schema::create('screening', function (Blueprint $table) {
            $table->comment('Applied EDI screening: header, frozen version pointer, server-computed result');
            BaseModel::columns($table, true);
            $table->uuid('visit_id')->index()->comment('FK to visit (one screening per visit, D17)');
            $table->uuid('child_id')->index()->comment('FK to child (denormalized from visit for history queries)');
            $table->uuid('instrument_version_id')->index()->comment('Hard pointer to the version whose rules scored this test; immutable after create');
            $table->uuid('age_group_id')->index()->comment('Chronological age group (FK to age_group)');
            $table->uuid('applied_age_group_id')->index()->comment('Group actually applied after corrected-age substitution');
            $table->smallInteger('chronological_age_days')->comment('Chronological age in days at visit_date');
            $table->smallInteger('corrected_age_days')->nullable()->comment('Corrected age in days (prematurity); NULL when not applicable');
            $table->integer('weight_grams')->nullable()->comment('Somatometry at test: weight in grams (minor units)');
            $table->smallInteger('height_mm')->nullable()->comment('Somatometry at test: height/length in millimeters');
            $table->smallInteger('head_circumference_mm')->nullable()->comment('Somatometry at test: head circumference in millimeters; feeds the EN auto-derived item');
            $table->uuid('screening_status_id')->index()->comment('FK to central screening_status (FSM D15)');
            $table->uuid('result_color_id')->nullable()->index()->comment('Global semaphore; engine output only, never client-writable');
            $table->uuid('global_result_message_id')->nullable()->index()->comment('FK to global_result_message shown for the result');
            $table->uuid('situation_id')->nullable()->index()->comment('FK to central screening_situation (D16)');
            $table->smallInteger('alert_sign_count')->nullable()->comment('Alert signs (ALE) counter from the trace');
            $table->smallInteger('risk_factor_count')->nullable()->comment('Risk factors (FRB) counter from the trace');
            $table->uuid('previous_screening_id')->nullable()->index()->comment('Frozen previous screening at first score (D16)');
            $table->uuid('previous_result_color_id')->nullable()->comment('Frozen previous global color; situation input across rescores');
            $table->string('engine_version', 20)->nullable()->comment('ScoringEngine consumer version that produced the header result (D10)');
            $table->char('rules_fingerprint', 64)->nullable()->comment('instrument_version.content_hash at score time (D9)');
            $table->timestamp('scored_at')->nullable()->comment('First authoritative score moment');
            $table->timestamp('completed_at')->nullable()->comment('Freeze moment');
            $table->text('invalidation_reason')->nullable()->comment('Mandatory reason captured on invalidate');
            $table->text('notes')->nullable()->comment('Clinical/administrative notes of the application');
        });

        DB::statement(
            'CREATE UNIQUE INDEX screening_visit_unique ON screening (visit_id) '
            .'WHERE deleted_at IS NULL'
        );

        DB::statement(
            'CREATE INDEX screening_tenant_child_idx ON screening (tenant_id, child_id)'
        );

        DB::statement(
            'CREATE INDEX screening_tenant_status_idx ON screening (tenant_id, screening_status_id)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS screening_visit_unique');
        DB::statement('DROP INDEX IF EXISTS screening_tenant_child_idx');
        DB::statement('DROP INDEX IF EXISTS screening_tenant_status_idx');
        Schema::dropIfExists('screening');
    }
};
