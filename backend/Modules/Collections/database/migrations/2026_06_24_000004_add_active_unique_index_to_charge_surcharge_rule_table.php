<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Anti-ambiguity for the late-fee accrual engine: at most ONE active rule per
     * (policy, from_month) among alive rows. selectSurchargeRule() in
     * ChargeStatusService sorts the policy's rules by from_month desc and takes the
     * first — two active rules sharing the same from_month would make the chosen
     * surcharge non-deterministic (insertion-order dependent). A partial unique index
     * makes the collision impossible at the DB level (soft-deleted / inactive rules
     * are excluded so a replaced rule can coexist with its successor).
     *
     * Mirrors charge_policy_active_unique (same partial-index shape, Postgres + SQLite
     * compatible). charge_policy_id is already globally unique, so the policy column
     * alone scopes the constraint to one tenant — no tenant_id needed in the key.
     */
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX charge_surcharge_rule_active_unique ON "charge_surcharge_rule" '
            .'(charge_policy_id, from_month) '
            .'WHERE deleted_at IS NULL AND is_active = true'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS charge_surcharge_rule_active_unique');
    }
};
