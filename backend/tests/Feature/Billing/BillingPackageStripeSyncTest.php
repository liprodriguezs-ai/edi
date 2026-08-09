<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\PlanModule;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Services\BillingPackageStripeService;
use Modules\SubscriptionPlan\Services\PlanPricingService;
use Stripe\Collection;
use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * Network-free coverage for BillingPackageStripeService (billing v2 matrix sync):
 * the StripeClient is a stateful Mockery double, so the sync logic runs without
 * ever calling Stripe.
 *
 * The sync now walks plan_module_price cells: one Stripe Product per
 * billable_module, one simple unit_amount Price per (plan × module × interval)
 * cell (NO tiers), lookup key billing_{planKey}_{moduleKey}_{interval}. Free
 * rows (is_free on the pair's plan_module row) are "price pending" →
 * skipped; a price row with NO matching plan_module row is ALSO skipped and
 * logged (never silently synced as if it were paid).
 */
final class BillingPackageStripeSyncTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /** Captured params from every mocked products->create() call. */
    private array $createdProducts = [];

    /** Captured params from every mocked products->update() call, keyed by product id. */
    private array $updatedProducts = [];

    /** Captured params from every mocked prices->create() call. */
    private array $createdPrices = [];

    /** Stateful fake Stripe price store, keyed by lookup_key. */
    private array $priceStore = [];

    private int $productCounter = 0;

    private int $priceCounter = 0;

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

    public function test_matrix_cell_creates_simple_unit_amount_price(): void
    {
        $this->seedMatrix();

        app(BillingPackageStripeService::class)->syncToStripe();

        $monthPayload = $this->payloadForLookupKey('billing_esencial_base_month');
        $this->assertNotNull($monthPayload, 'base month price was not created');

        $this->assertSame(95000, $monthPayload['unit_amount']);
        $this->assertArrayNotHasKey('billing_scheme', $monthPayload);
        $this->assertArrayNotHasKey('tiers', $monthPayload);
        $this->assertSame('mxn', $monthPayload['currency']);
        $this->assertSame('month', $monthPayload['recurring']['interval']);
        $this->assertSame(1, $monthPayload['recurring']['interval_count']);

        $yearPayload = $this->payloadForLookupKey('billing_esencial_base_year');
        $this->assertNotNull($yearPayload);
        $this->assertSame((int) round(95000 * 12 * 0.95), $yearPayload['unit_amount']);
    }

    public function test_one_product_per_billable_module(): void
    {
        $this->seedMatrix();

        app(BillingPackageStripeService::class)->syncToStripe();

        // base + package = 2 products; amenities (free-only) → none.
        $this->assertCount(2, $this->createdProducts);
    }

    public function test_free_price_row_is_skipped(): void
    {
        $this->seedMatrix();

        app(BillingPackageStripeService::class)->syncToStripe();

        $this->assertNull($this->payloadForLookupKey('billing_esencial_amenities_month'));
        $this->assertNull($this->payloadForLookupKey('billing_esencial_amenities_year'));
    }

    public function test_sync_is_idempotent_and_does_not_duplicate_on_second_run(): void
    {
        $this->seedMatrix();

        $service = app(BillingPackageStripeService::class);
        $service->syncToStripe();

        // base (month+year) + package (month+year) = 4 prices, 2 products.
        $this->assertCount(2, $this->createdProducts);
        $this->assertCount(4, $this->createdPrices);

        $service->syncToStripe();

        $this->assertCount(2, $this->createdProducts, 'second run must not create new Products');
        $this->assertCount(4, $this->createdPrices, 'second run must not create new Prices');
    }

    public function test_sync_republishes_the_product_copy_on_an_existing_product(): void
    {
        // F-19: name/description used to be written only at CREATE time, so the copy
        // shown on the hosted Checkout page could never be corrected — Stripe kept
        // the pre-collapse text. A re-sync must push the current copy.
        $this->seedMatrix();

        $service = app(BillingPackageStripeService::class);
        $service->syncToStripe();

        $base = BillableModule::where('key', 'base')->firstOrFail();
        $base->update(['description' => 'Todo incluido: cobranza, avisos, accesos, paquetería y amenidades.']);

        $this->updatedProducts = [];
        $service->syncToStripe();

        $descriptions = array_column($this->updatedProducts, 'description');

        $this->assertContains(
            'Todo incluido: cobranza, avisos, accesos, paquetería y amenidades.',
            $descriptions,
            'the sync must push the current product copy to Stripe',
        );
    }

    public function test_sync_persists_stripe_ids_on_plan_module_price_rows(): void
    {
        $this->seedMatrix();

        app(BillingPackageStripeService::class)->syncToStripe();

        foreach ($this->nonFreePriceRows() as $price) {
            $this->assertNotNull($price->stripe_price_id, "cell {$price->interval} missing stripe_price_id");
            $this->assertNotNull($price->stripe_product_id, "cell {$price->interval} missing stripe_product_id");
            $this->assertStringStartsWith('price_', $price->stripe_price_id);
            $this->assertStringStartsWith('prod_', $price->stripe_product_id);
        }
    }

    public function test_price_row_without_plan_module_row_is_excluded_and_logged(): void
    {
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);

        // Bypass the fixture helper (which always upserts a matching
        // plan_module row) to simulate drift: an active price row with NO
        // plan_module row for its pair.
        PlanModulePrice::create([
            'billing_package_id' => $plan->id,
            'billable_module_id' => $base->id,
            'interval' => 'month',
            'unit_amount' => 95000,
            'currency' => 'MXN',
            'is_active' => true,
        ]);

        Log::spy();

        $result = app(BillingPackageStripeService::class)->syncToStripe();

        $this->assertSame([], $result, 'a price row with no plan_module row must never sync');
        $this->assertNull($this->payloadForLookupKey('billing_esencial_base_month'));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Stripe sync skipped: no plan_module row for price row'
                && $context['plan'] === $plan->key
                && $context['module'] === $base->key
                && $context['interval'] === 'month');
    }

    public function test_sync_command_busts_the_plan_catalog_cache(): void
    {
        // D4 — a synced plan flips is_purchasable, but the catalog TTL (5 min)
        // would keep serving the stale not-purchasable response without this bust.
        $this->seedMatrix();

        Cache::put(PlanPricingService::PLAN_CATALOG_CACHE_KEY, ['stale' => true], now()->addMinutes(5));

        Artisan::call('billing:sync-stripe');

        $this->assertNull(Cache::get(PlanPricingService::PLAN_CATALOG_CACHE_KEY));
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function seedMatrix(): void
    {
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $package = $this->makeBillableModule('package', 'Paquetería');
        $amenities = $this->makeBillableModule('amenities', 'Amenidades');

        $this->makePlanModulePrice($plan, $base, 'month', 95000);
        $this->makePlanModulePrice($plan, $base, 'year', (int) round(95000 * 12 * 0.95));
        $this->makePlanModulePrice($plan, $package, 'month', 12000);
        $this->makePlanModulePrice($plan, $package, 'year', (int) round(12000 * 12 * 0.95));
        // Free (price pending) rows → must be skipped by the sync.
        $this->makePlanModulePrice($plan, $amenities, 'month', 0, ['is_free' => true]);
        $this->makePlanModulePrice($plan, $amenities, 'year', 0, ['is_free' => true]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, PlanModulePrice>
     */
    private function nonFreePriceRows(): \Illuminate\Database\Eloquent\Collection
    {
        $flagsByPair = PlanModule::query()
            ->get()
            ->keyBy(fn (PlanModule $flag): string => $flag->billing_package_id.'|'.$flag->billable_module_id);

        return PlanModulePrice::all()->filter(
            fn (PlanModulePrice $price): bool => ! ($flagsByPair->get($price->billing_package_id.'|'.$price->billable_module_id)?->is_free ?? false)
        )->values();
    }

    // =========================================================================
    // Stripe double
    // =========================================================================

    private function fakeStripe(): void
    {
        $products = Mockery::mock();
        $products->shouldReceive('update')->andReturnUsing(function (string $id, array $params): Product {
            $this->updatedProducts[$id] = $params;

            return Product::constructFrom(['id' => $id] + $params);
        });
        $products->shouldReceive('create')->andReturnUsing(function (array $params): Product {
            $this->createdProducts[] = $params;
            $id = 'prod_test_'.(++$this->productCounter);

            return Product::constructFrom(['id' => $id] + $params);
        });

        $prices = Mockery::mock();

        $prices->shouldReceive('create')->andReturnUsing(function (array $params): Price {
            $this->createdPrices[] = $params;
            $id = 'price_test_'.(++$this->priceCounter);

            $stored = Price::constructFrom([
                'id' => $id,
                'lookup_key' => $params['lookup_key'],
                'product' => $params['product'],
                'unit_amount' => $params['unit_amount'] ?? null,
            ]);

            $this->priceStore[$params['lookup_key']] = $stored;

            return $stored;
        });

        $prices->shouldReceive('all')->andReturnUsing(function (array $params): Collection {
            $keys = $params['lookup_keys'] ?? [];
            $matches = [];

            foreach ($keys as $key) {
                if (isset($this->priceStore[$key])) {
                    $matches[] = $this->priceStore[$key];
                }
            }

            return Collection::constructFrom(['data' => $matches]);
        });

        $client = Mockery::mock(StripeClient::class);
        $client->shouldReceive('getService')->with('products')->andReturn($products);
        $client->shouldReceive('getService')->with('prices')->andReturn($prices);

        $this->app->bind(StripeClient::class, fn () => $client);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payloadForLookupKey(string $lookupKey): ?array
    {
        foreach ($this->createdPrices as $params) {
            if (($params['lookup_key'] ?? null) === $lookupKey) {
                return $params;
            }
        }

        return null;
    }
}
