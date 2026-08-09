<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Anchored billing periods: charge_batch.period_start becomes the idempotency key,
     * replacing the COALESCE-based (year, month, cycle) tuple. Additive and nullable —
     * no existing row is touched. NULL marks legacy calendar-grid batches and templates.
     */
    public function up(): void
    {
        Schema::table('charge_batch', function (Blueprint $table) {
            $table->date('period_start')
                ->nullable()
                ->after('cycle')
                ->comment('Anchored period start date; NULL on legacy calendar-grid batches and templates');
        });

        DB::statement('DROP INDEX IF EXISTS charge_batch_period_unique');
        DB::statement(
            'CREATE UNIQUE INDEX charge_batch_period_start_unique ON "charge_batch" '
            .'(tenant_id, charge_policy_id, period_start) '
            .'WHERE deleted_at IS NULL AND period_start IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS charge_batch_period_start_unique');
        DB::statement(
            'CREATE UNIQUE INDEX charge_batch_period_unique ON "charge_batch" '
            .'(tenant_id, charge_policy_id, year, month, COALESCE(cycle, -1)) '
            .'WHERE deleted_at IS NULL AND month IS NOT NULL'
        );

        Schema::table('charge_batch', function (Blueprint $table) {
            $table->dropColumn('period_start');
        });
    }
};
