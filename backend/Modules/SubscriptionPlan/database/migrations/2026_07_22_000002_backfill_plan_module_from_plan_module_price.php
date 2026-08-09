<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One-time data copy: for every (billing_package_id, billable_module_id)
     * pair in plan_module_price, copies its is_free/trial_days into the new
     * plan_module table before migration 000003 drops those columns from
     * plan_module_price. When both a month and a year row exist for a pair,
     * the month row wins ('month' < 'year' alphabetically — the same
     * deterministic ordering the pre-refactor code already relied on via
     * orderBy('interval')->first()). Single-interval pairs backfill from
     * their one row. Plain DB::table() (query builder, not Eloquent): this
     * same working tree drops is_free/trial_days from PlanModulePrice's
     * $fillable/casts, so reading through the model here would depend on
     * model state this same tree is simultaneously deleting. No chunking: a
     * bounded platform catalog table (dozens of rows at most by
     * construction), not tenant data.
     */
    public function up(): void
    {
        $rows = DB::table('plan_module_price')
            ->orderBy('billing_package_id')
            ->orderBy('billable_module_id')
            ->orderBy('interval') // 'month' < 'year': month wins on conflict
            ->get(['billing_package_id', 'billable_module_id', 'is_free', 'trial_days']);

        $seenPairs = [];
        $now = now();

        foreach ($rows as $row) {
            $pairKey = $row->billing_package_id.'|'.$row->billable_module_id;

            if (isset($seenPairs[$pairKey])) {
                continue;
            }

            $seenPairs[$pairKey] = true;

            DB::table('plan_module')->insert([
                'billing_package_id' => $row->billing_package_id,
                'billable_module_id' => $row->billable_module_id,
                'is_free' => $row->is_free,
                'trial_days' => $row->trial_days,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Removes every row this migration backfilled. Dev-only acceptance: a
     * rollback loses the copied flag VALUES (they are restored only by
     * re-seeding), never the plan_module table/schema itself (migration
     * 000001 owns that).
     */
    public function down(): void
    {
        DB::table('plan_module')->delete();
    }
};
