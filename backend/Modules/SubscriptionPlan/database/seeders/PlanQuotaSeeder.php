<?php

namespace Modules\SubscriptionPlan\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\SubscriptionPlan\Models\PlanQuota;

/**
 * Ships the three v1 resources at `limit_value = -1` (unlimited) in `trial`
 * scope, global (no `billing_package_id`) — the machinery fully built, OFF
 * (REQ-QC-05).
 *
 * `firstOrCreate` ONLY (D7) — no update path exists here on purpose. `db:seed`
 * reruns on every deploy (start.sh:419); an operator-tuned `limit_value` MUST
 * survive every rerun. Do NOT add an update/upsert path to this seeder.
 */
class PlanQuotaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (PlanQuota::RESOURCES as $resourceKey) {
            PlanQuota::firstOrCreate(
                [
                    'billing_package_id' => null,
                    'scope' => PlanQuota::SCOPE_TRIAL,
                    'resource_key' => $resourceKey,
                ],
                [
                    'limit_value' => PlanQuota::UNLIMITED,
                ],
            );
        }
    }
}
