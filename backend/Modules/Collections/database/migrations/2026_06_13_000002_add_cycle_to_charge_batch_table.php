<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charge_batch', function (Blueprint $table) {
            $table->smallInteger('cycle')
                ->nullable()
                ->after('month')
                ->comment('Sub-period discriminator within a month for sub-monthly periodicities: WEEKLY=ISO week number (1-53), BIWEEKLY=1 (day-15 run)|2 (end-of-month run). NULL for monthly/bimonthly/yearly/eventual.');
        });

        // Replace the monthly-only unique with one that also isolates by cycle.
        //
        // CRITICAL: Postgres NULL is distinct in unique indexes — two rows with cycle=NULL
        // (monthly batches) would BOTH be allowed under a bare `cycle` unique, breaking
        // the existing monthly double-charge guard. Fix: COALESCE(cycle, -1) collapses all
        // monthly NULLs to the sentinel -1 so they still collide as before.
        // weekly/biweekly rows carry distinct positive cycle values and are correctly isolated.
        DB::statement('DROP INDEX IF EXISTS charge_batch_monthly_unique');
        DB::statement(
            'CREATE UNIQUE INDEX charge_batch_period_unique ON "charge_batch" '
            .'(tenant_id, charge_policy_id, year, month, COALESCE(cycle, -1)) '
            .'WHERE deleted_at IS NULL AND month IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS charge_batch_period_unique');
        DB::statement(
            'CREATE UNIQUE INDEX charge_batch_monthly_unique ON "charge_batch" '
            .'(tenant_id, charge_policy_id, year, month) '
            .'WHERE deleted_at IS NULL AND month IS NOT NULL'
        );

        Schema::table('charge_batch', function (Blueprint $table) {
            $table->dropColumn('cycle');
        });
    }
};
