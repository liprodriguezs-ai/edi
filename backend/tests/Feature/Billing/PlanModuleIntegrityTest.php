<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\SubscriptionPlan\Models\PlanModule;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\BillingPackageStripeService;
use Modules\SubscriptionPlan\Services\EntitlementService;
use Modules\SubscriptionPlan\Services\PlanPricingService;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * ADR-1 (design.md) drift + parity coverage for the plan_module ↔
 * plan_module_price split: there is no FK between them (a lookup, not a
 * relation), so this is the ONE place that pins what happens when they
 * disagree, and proves they agree on well-formed data.
 *
 * Drift: an ACTIVE plan_module_price row whose pair has NO plan_module row.
 * Every flag-reading path MUST resolve it to a defined, non-crashing result
 * (never an inferred "is free") — pinned across the three consumers:
 * PlanPricingService (quote/catalog), BillingPackageStripeService (sync),
 * EntitlementService (trial guard (a)).
 *
 * Parity: on data created through BillingMatrixFixtures (which always keeps
 * the pair's plan_module + plan_module_price rows in lockstep), plan_module
 * row existence and active price-row existence agree for every pair, and
 * trial accept/reject matches a price-row oracle.
 *
 * Central-connection writes accumulate the fixed-key catalog tables across
 * tests, so — like every other central-touching billing test — this runs
 * transaction-less ($connectionsToTransact = []) and resets the billing
 * tables in tearDown via cleanBillingTables().
 */
final class PlanModuleIntegrityTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        $this->cleanBillingTables();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // =========================================================================
    // Drift: active price row, no plan_module row
    // =========================================================================

    public function test_quote_reports_not_free_when_plan_module_row_is_missing(): void
    {
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', isBase: true);

        // Bypass the fixture helper (which always upserts a matching
        // plan_module row) to simulate drift.
        PlanModulePrice::create([
            'billing_package_id' => $plan->id,
            'billable_module_id' => $base->id,
            'interval' => 'month',
            'unit_amount' => 95000,
            'currency' => 'MXN',
            'is_active' => true,
        ]);

        $this->assertFalse(
            PlanModule::query()
                ->where('billing_package_id', $plan->id)
                ->where('billable_module_id', $base->id)
                ->exists(),
            'precondition: no plan_module row exists for this pair',
        );

        $quote = app(PlanPricingService::class)->quoteByPlan('esencial', ['base'], 'month');

        $this->assertFalse($quote['lines'][0]['is_free'], 'a missing plan_module row must never be read as free');
        $this->assertSame(95000, $quote['total']);
    }

    public function test_plan_catalog_reports_not_free_when_plan_module_row_is_missing(): void
    {
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', isBase: true);

        PlanModulePrice::create([
            'billing_package_id' => $plan->id,
            'billable_module_id' => $base->id,
            'interval' => 'month',
            'unit_amount' => 95000,
            'currency' => 'MXN',
            'is_active' => true,
        ]);

        $catalog = app(PlanPricingService::class)->planCatalog(includeHidden: true);
        $entry = collect($catalog['plans'])->firstWhere('key', 'esencial');

        $this->assertNotNull($entry);
        $baseModuleEntry = collect($entry['modules'])->firstWhere('module_key', 'base');

        $this->assertNotNull($baseModuleEntry);
        $this->assertFalse($baseModuleEntry['prices']['month']['is_free']);
        $this->assertSame(95000, $baseModuleEntry['prices']['month']['unit_amount']);
    }

    public function test_stripe_sync_plan_excludes_and_logs_when_plan_module_row_is_missing(): void
    {
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', isBase: true);

        PlanModulePrice::create([
            'billing_package_id' => $plan->id,
            'billable_module_id' => $base->id,
            'interval' => 'month',
            'unit_amount' => 95000,
            'currency' => 'MXN',
            'is_active' => true,
        ]);

        Log::spy();

        // plan() is the network-free dry-run projection — no StripeClient mock needed.
        $planned = app(BillingPackageStripeService::class)->plan();

        $this->assertSame([], $planned, 'a price row with no plan_module row must never be planned for sync');
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_start_trial_rejects_when_plan_module_row_is_missing_even_with_an_active_price_row(): void
    {
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', isBase: true);

        PlanModulePrice::create([
            'billing_package_id' => $plan->id,
            'billable_module_id' => $base->id,
            'interval' => 'month',
            'unit_amount' => 95000,
            'currency' => 'MXN',
            'is_active' => true,
        ]);

        $tenant = $this->makeTenant(['billing_package_id' => $plan->id]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Este módulo no está disponible en tu plan.');

        app(EntitlementService::class)->startModuleTrial($tenant, 'base');
    }

    // =========================================================================
    // Parity: well-formed data (created via the fixture invariant)
    // =========================================================================

    public function test_plan_module_existence_matches_active_price_row_existence_on_well_formed_data(): void
    {
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', isBase: true);
        $this->makePlanModulePrice($plan, $base, 'month', 95000);
        $this->makePlanModulePrice($plan, $base, 'year', 1083000);

        $addon = $this->makeBillableModule('addon', 'Add-on');
        $this->makePlanModulePrice($plan, $addon, 'month', 12000);
        $this->makePlanModulePrice($plan, $addon, 'year', 136800, ['is_free' => true]);

        $pairs = PlanModulePrice::query()
            ->select('billing_package_id', 'billable_module_id')
            ->distinct()
            ->get();

        $this->assertNotEmpty($pairs);

        foreach ($pairs as $pair) {
            $hasPlanModule = PlanModule::query()
                ->where('billing_package_id', $pair->billing_package_id)
                ->where('billable_module_id', $pair->billable_module_id)
                ->exists();

            $hasActivePrice = PlanModulePrice::query()
                ->where('billing_package_id', $pair->billing_package_id)
                ->where('billable_module_id', $pair->billable_module_id)
                ->where('is_active', true)
                ->exists();

            $this->assertSame(
                $hasActivePrice,
                $hasPlanModule,
                'plan_module existence must match active price-row existence for well-formed fixture data',
            );
        }
    }

    public function test_start_trial_accept_reject_matches_the_price_row_oracle(): void
    {
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', isBase: true);
        $this->makePlanModulePrice($plan, $base, 'month', 95000);

        $offered = $this->makeBillableModule('offered', 'Offered add-on');
        $this->makePlanModulePrice($plan, $offered, 'month', 5000);

        // No price row at all for (plan, not_offered) — the price-row oracle
        // says "not offered", and makeBillableModule() never touches plan_module.
        $this->makeBillableModule('not_offered', 'Unoffered add-on');

        $tenant = $this->makeTenant(['billing_package_id' => $plan->id]);
        $this->grantModule($tenant, $base, $plan, TenantEntitlement::SOURCE_MANUAL);

        $service = app(EntitlementService::class);

        // Oracle: an active price row exists for (plan, offered) → trial succeeds.
        $entitlement = $service->startModuleTrial($tenant, 'offered');
        $this->assertSame(TenantEntitlement::SOURCE_TRIAL, $entitlement->source);

        // Oracle: no price row for (plan, not_offered) → trial rejected.
        $this->expectException(InvalidArgumentException::class);
        $service->startModuleTrial($tenant, 'not_offered');
    }
}
