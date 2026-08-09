<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BM2 — race-safe dedupe for MANUAL subscription payments.
 *
 * SubscriptionPaymentService::recordManual guards duplicates with an in-transaction
 * check-then-insert, which is not safe against a concurrent double-submit (two
 * Octane workers can both pass the SELECT before either INSERTs). This additive
 * partial unique index is the DB-level backstop: a manual (transfer/cash) row is
 * unique per (tenant_id, billable_module_id, amount, paid_at) among live rows, so a
 * racing duplicate is rejected by the constraint instead of duplicating the ledger
 * and stacking the entitlement period twice.
 *
 * Partial on manual methods only (stripe rows already dedupe on stripe_invoice_id)
 * and on non-soft-deleted rows (a deleted payment must not block a fresh one).
 */
return new class extends Migration
{
    private const INDEX = 'subscription_payment_manual_dedupe_unique';

    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX.' ON subscription_payment '
            ."(tenant_id, billable_module_id, amount, paid_at) WHERE method IN ('transfer', 'cash') AND deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
