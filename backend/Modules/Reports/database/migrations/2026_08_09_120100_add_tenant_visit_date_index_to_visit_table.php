<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Additive index only (design D16). The period aggregation
     * (IndicatorAggregationQuery) filters `visit` by tenant + a visit_date
     * RANGE without a child_id predicate; the existing
     * `visit_tenant_child_date_idx (tenant_id, child_id, visit_date DESC)`
     * cannot serve that scan efficiently once child_id sits in the middle of
     * the key. No other column/table of `visit` is touched.
     */
    public function up(): void
    {
        DB::statement(
            'CREATE INDEX IF NOT EXISTS visit_tenant_date_idx ON visit (tenant_id, visit_date)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS visit_tenant_date_idx');
    }
};
