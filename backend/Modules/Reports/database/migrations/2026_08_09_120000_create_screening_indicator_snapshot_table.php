<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * screening_indicator_snapshot materializes the monthly EDI indicators
     * (design §3, REQ-RPT-001/002): one row per (tenant_id, health_center_id
     * NULLABLE, period_start), where a NULL health_center_id is the
     * whole-organization rollup row. Written exclusively by
     * IndicatorSnapshotService::buildPeriod (D14) — no write endpoint exists.
     */
    public function up(): void
    {
        Schema::create('screening_indicator_snapshot', function (Blueprint $table) {
            $table->comment('Materialized screening indicator aggregates per tenant, health center (NULL = whole organization), and calendar month');
            BaseModel::columns($table, true);
            $table->uuid('health_center_id')->nullable()->index()->comment('FK to health_center; NULL = organization-wide rollup row');
            $table->date('period_start')->comment('First day of the aggregated calendar month');
            $table->integer('screenings_applied')->default(0)->comment('Counted set size: non-invalidated screenings with a result in the period');
            $table->integer('screenings_completed')->default(0)->comment('Sub-count with status completed');
            $table->integer('children_screened')->default(0)->comment('Distinct children in the counted set');
            $table->integer('result_green_count')->default(0)->comment('Global semaphore green count');
            $table->integer('result_yellow_count')->default(0)->comment('Global semaphore yellow count');
            $table->integer('result_red_count')->default(0)->comment('Global semaphore red count');
            $table->integer('first_time_count')->default(0)->comment('Screenings whose situation key is first_time_*');
            $table->integer('alert_sign_screening_count')->default(0)->comment('Screenings with alert_sign_count > 0');
            $table->integer('risk_factor_screening_count')->default(0)->comment('Screenings with risk_factor_count > 0');
            $table->integer('confirmatory_pending_count')->default(0)->comment('Red completed screenings without a confirmatory test result');
            $table->jsonb('area_breakdown')->default('{}')->comment('Per development-area color counts: {area_code: {green, yellow, red}}');
            $table->jsonb('situation_breakdown')->default('{}')->comment('Per situation-key counts: {situation_key: count}');
            $table->timestamp('computed_at')->comment('Moment this row was (re)computed by the builder');

            $table->index(['tenant_id', 'period_start']);
        });

        // NULLS NOT DISTINCT (PostgreSQL 16): a NULL health_center_id
        // participates in uniqueness like any other value, so exactly one
        // organization-rollup row can exist per (tenant_id, period_start) —
        // design D2.
        DB::statement(
            'CREATE UNIQUE INDEX screening_indicator_snapshot_grain_unique '
            .'ON screening_indicator_snapshot (tenant_id, health_center_id, period_start) '
            .'NULLS NOT DISTINCT WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS screening_indicator_snapshot_grain_unique');
        Schema::dropIfExists('screening_indicator_snapshot');
    }
};
