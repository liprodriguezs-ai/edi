<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Late-fee ledger (Slice B — recargos moratorios). Mirrors the discount ledger:
     * the surcharge is accrued in a SEPARATE column and NEVER mutates expected_amount
     * (which is frozen at emission). effective_expected = max(0, expected - discount)
     * + surcharge_amount. surcharge_amount is recomputed daily from scratch (derived,
     * idempotent), so re-running the cron the same day yields the same value.
     *
     * tax_amount is reserved for a future IVA release; it is NOT computed in this
     * release and stays inert at 0.
     */
    public function up(): void
    {
        Schema::table('charge', function (Blueprint $table) {
            $table->bigInteger('surcharge_amount')->default(0)->after('discounted_by_user_id')
                ->comment('Minor units accrued late fee; recomputed daily, NEVER mutates expected_amount.');
            $table->date('surcharge_recomputed_at')->nullable()->after('surcharge_amount')
                ->comment('Date the surcharge_amount was last recomputed by the daily accrual cron.');
            $table->bigInteger('tax_amount')->default(0)->after('surcharge_recomputed_at')
                ->comment('Reserved for future IVA. NOT computed in this release.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('charge', function (Blueprint $table) {
            $table->dropColumn(['surcharge_amount', 'surcharge_recomputed_at', 'tax_amount']);
        });
    }
};
