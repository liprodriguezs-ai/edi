<?php

namespace Modules\SubscriptionPlan\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Modules\SubscriptionPlan\Services\BillingPackageStripeService;
use Modules\SubscriptionPlan\Services\PlanPricingService;

/**
 * billing:sync-stripe — push the billing v2 plan×module matrix (plan_module_price)
 * into Stripe Products (one per billable_module) and Prices (one per
 * plan×module×interval cell).
 *
 * Idempotent: prices are matched by deterministic lookup keys, so re-running
 * never duplicates. --dry-run reports the intended lookup keys WITHOUT touching
 * Stripe. --plan limits the run to a single plan key.
 */
class SyncBillingStripeCommand extends Command
{
    protected $signature = 'billing:sync-stripe {--plan= : Limit the sync to a single plan key} {--dry-run : Report what would be synced without calling Stripe}';

    protected $description = 'Sync the billing v2 plan×module matrix to Stripe Products/Prices (idempotent).';

    public function handle(BillingPackageStripeService $service): int
    {
        $planKey = $this->option('plan') ?: null;

        if ($this->option('dry-run')) {
            return $this->runDryRun($service, $planKey);
        }

        return $this->runSync($service, $planKey);
    }

    private function runDryRun(BillingPackageStripeService $service, ?string $planKey): int
    {
        $plan = $service->plan($planKey);

        if ($plan === []) {
            $this->warn('No active plan×module prices found.');

            return self::SUCCESS;
        }

        $this->info('billing:sync-stripe --dry-run (no Stripe calls)');

        foreach ($plan as $row) {
            $keys = implode(', ', $row['lookup_keys']);
            $this->line("  [sync] module={$row['module']} → {$keys}");
        }

        return self::SUCCESS;
    }

    private function runSync(BillingPackageStripeService $service, ?string $planKey): int
    {
        try {
            $results = $service->syncToStripe($planKey);
        } catch (\Throwable $e) {
            $this->error('Stripe sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($results === []) {
            $this->warn('No active plan×module prices found.');

            return self::SUCCESS;
        }

        foreach ($results as $row) {
            $this->info("  [module={$row['module']}] product={$row['product_id']}");

            foreach ($row['prices'] as $price) {
                $verb = $price['created'] ? 'created' : 'reused';
                $this->line("    - {$price['plan']}/{$price['interval']}: {$price['lookup_key']} → {$price['price_id']} ({$verb})");
            }
        }

        // The public catalog (is_purchasable, prices) is cached for CATALOG_CACHE_TTL
        // seconds; without this bust, a sync that just fixed a plan's Stripe ids would
        // still read the STALE not-purchasable catalog until the TTL expired.
        Cache::forget(PlanPricingService::PLAN_CATALOG_CACHE_KEY);

        $this->info('billing:sync-stripe completed.');

        return self::SUCCESS;
    }
}
