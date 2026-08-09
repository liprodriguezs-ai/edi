<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * D6 — billing:verify-catalog is a read-only guardrail: it must fail (exit 1)
 * exactly when an ACTIVE price row of an active + public plan has no synced
 * Stripe id, and pass (exit 0) otherwise. A price-less contact-sales plan is
 * never a failure — it carries zero price rows by design.
 */
final class VerifyBillingCatalogCommandTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        $this->cleanBillingTables();

        parent::tearDown();
    }

    public function test_exits_zero_when_every_active_public_plan_is_fully_synced(): void
    {
        [$plan, $base] = $this->makePlanWithBase();

        PlanModulePrice::query()
            ->where('billing_package_id', $plan->id)
            ->where('billable_module_id', $base->id)
            ->update(['stripe_price_id' => 'price_synced', 'stripe_product_id' => 'prod_synced']);

        $exitCode = Artisan::call('billing:verify-catalog');

        $this->assertSame(0, $exitCode);
    }

    public function test_exits_one_when_an_active_public_plan_has_an_unsynced_price(): void
    {
        // makePlanWithBase() seeds month + year price rows with no stripe ids.
        $this->makePlanWithBase();

        $exitCode = Artisan::call('billing:verify-catalog');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('missing a Stripe id', Artisan::output());
    }

    public function test_a_price_less_contact_sales_plan_is_not_a_failure(): void
    {
        $this->makePlan('a_medida'); // no price rows at all

        $exitCode = Artisan::call('billing:verify-catalog');

        $this->assertSame(0, $exitCode);
    }

    public function test_ignores_an_unsynced_price_on_a_non_public_plan(): void
    {
        // A hidden preferential plan is not self-serve purchasable, so an unsynced
        // price there is not the deploy-outage this guardrail protects against.
        [$plan] = $this->makePlanWithBase('preferencial_x');
        $plan->update(['is_public' => false]);

        $exitCode = Artisan::call('billing:verify-catalog');

        $this->assertSame(0, $exitCode);
    }

    public function test_ignores_an_unsynced_price_on_a_retired_plan(): void
    {
        [$plan] = $this->makePlanWithBase();
        $plan->update(['is_active' => false]);

        $exitCode = Artisan::call('billing:verify-catalog');

        $this->assertSame(0, $exitCode);
    }
}
