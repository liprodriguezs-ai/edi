<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Modules\SubscriptionPlan\Services\BillingPackageStripeService;
use Modules\SubscriptionPlan\Services\PlanPricingService;
use Stripe\Collection;
use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * PARITY (billing v2): the amount PlanPricingService::quoteByPlan() shows the
 * customer MUST equal what Stripe will charge for that subscription — both are
 * driven from the SAME plan_module_price.unit_amount integers (quantity=1 per
 * module line, fixed price).
 *
 * This test syncs the matrix to a Stripe double, captures each cell's synced
 * unit_amount, and asserts the sum equals the quote total, for BOTH intervals.
 *
 * Network-free: the Stripe SDK client is a stateful Mockery double.
 */
final class BillingQuoteStripeParityTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /** Captured params from every mocked prices->create() call, keyed by lookup_key. */
    private array $createdPricePayloads = [];

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeStripe();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->cleanBillingTables();
        parent::tearDown();
    }

    public function test_quote_total_equals_sum_of_synced_stripe_unit_amounts(): void
    {
        $this->seedMatrix();
        app(BillingPackageStripeService::class)->syncToStripe();

        foreach (['month', 'year'] as $interval) {
            $quote = app(PlanPricingService::class)->quoteByPlan('esencial', ['base', 'package'], $interval);

            $stripeTotal = ($this->createdPricePayloads["billing_esencial_base_{$interval}"]['unit_amount'] ?? 0)
                + ($this->createdPricePayloads["billing_esencial_package_{$interval}"]['unit_amount'] ?? 0);

            $this->assertSame(
                $quote['total'],
                $stripeTotal,
                "{$interval}: quote total ({$quote['total']}) != Stripe charge ({$stripeTotal})",
            );
        }
    }

    public function test_base_only_quote_equals_synced_base_price(): void
    {
        $this->seedMatrix();
        app(BillingPackageStripeService::class)->syncToStripe();

        foreach ([95000 => 'month', (int) round(95000 * 12 * 0.95) => 'year'] as $expected => $interval) {
            $quote = app(PlanPricingService::class)->quoteByPlan('esencial', ['base'], $interval);

            $this->assertSame($expected, $quote['total']);
            $this->assertSame(
                $quote['total'],
                $this->createdPricePayloads["billing_esencial_base_{$interval}"]['unit_amount'] ?? null,
            );
        }
    }

    // =========================================================================
    // Fixtures + Stripe double
    // =========================================================================

    private function seedMatrix(): void
    {
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $package = $this->makeBillableModule('package', 'Paquetería');

        $this->makePlanModulePrice($plan, $base, 'month', 95000);
        $this->makePlanModulePrice($plan, $base, 'year', (int) round(95000 * 12 * 0.95));
        $this->makePlanModulePrice($plan, $package, 'month', 12000);
        $this->makePlanModulePrice($plan, $package, 'year', (int) round(12000 * 12 * 0.95));
    }

    private function fakeStripe(): void
    {
        $products = Mockery::mock();
        $products->shouldReceive('create')->andReturnUsing(function (array $params): Product {
            return Product::constructFrom(['id' => 'prod_test_'.(++$this->counter)] + $params);
        });

        $prices = Mockery::mock();
        $prices->shouldReceive('create')->andReturnUsing(function (array $params): Price {
            $this->createdPricePayloads[$params['lookup_key']] = $params;

            return Price::constructFrom([
                'id' => 'price_test_'.(++$this->counter),
                'lookup_key' => $params['lookup_key'],
                'product' => $params['product'],
                'unit_amount' => $params['unit_amount'] ?? null,
            ]);
        });
        $prices->shouldReceive('all')->andReturnUsing(
            fn (): Collection => Collection::constructFrom(['data' => []]),
        );

        $client = Mockery::mock(StripeClient::class);
        $client->shouldReceive('getService')->with('products')->andReturn($products);
        $client->shouldReceive('getService')->with('prices')->andReturn($prices);

        $this->app->bind(StripeClient::class, fn () => $client);
    }
}
