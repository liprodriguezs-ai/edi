<?php

namespace Modules\SubscriptionPlan\Console\Commands;

use Illuminate\Console\Command;
use Modules\SubscriptionPlan\Models\PlanModulePrice;

/**
 * billing:verify-catalog — read-only operational guardrail (D6).
 *
 * Fails loudly (exit 1) when an ACTIVE price row of an active + public plan has no
 * synced Stripe price id — the exact drift that used to be caused silently by
 * PlanModuleMatrixSeeder nulling stripe_price_id on every re-seed (D3, now fixed).
 * A price-less contact-sales plan (a_medida) is NOT a failure — it has zero price
 * rows by design, so it never matches this query.
 *
 * Intended as a deploy-time / cron smoke check: run it right after
 * `php artisan db:seed` or `billing:sync-stripe` and alert on non-zero exit.
 */
class VerifyBillingCatalogCommand extends Command
{
    protected $signature = 'billing:verify-catalog';

    protected $description = 'Fail (exit 1) when an active plan has a billable price row with no synced Stripe id.';

    public function handle(): int
    {
        $brokenRows = PlanModulePrice::query()
            ->where('is_active', true)
            ->whereNull('stripe_price_id')
            ->whereHas('plan', fn ($query) => $query->where('is_active', true)->where('is_public', true))
            ->with(['plan:id,key,name', 'billable_module:id,key,name'])
            ->get();

        if ($brokenRows->isEmpty()) {
            $this->info('billing:verify-catalog — every active, public plan is fully Stripe-synced.');

            return self::SUCCESS;
        }

        $this->error("billing:verify-catalog — {$brokenRows->count()} price row(s) missing a Stripe id:");

        foreach ($brokenRows as $row) {
            $planLabel = $row->plan?->key ?? $row->billing_package_id;
            $moduleLabel = $row->billable_module?->key ?? $row->billable_module_id;
            $this->line("  [plan={$planLabel}] module={$moduleLabel} interval={$row->interval} — stripe_price_id is null");
        }

        $this->line('Run `php artisan billing:sync-stripe` to fix, then re-run this command.');

        return self::FAILURE;
    }
}
