<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Laravel\Cashier\Invoice;
use Laravel\Cashier\Subscription;
use Laravel\Cashier\SubscriptionBuilder;
use Mockery;
use Modules\SubscriptionPlan\Exceptions\CheckoutUnavailableException;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\MultiItemSubscriptionService;
use RuntimeException;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * Network-free coverage for MultiItemSubscriptionService. Stripe and Cashier are
 * fully mocked. Every subscription line carries quantity=1 (fixed plan×module
 * price), and resolveStripePriceIds reads plan_module_price. The per-module
 * mutators (addAddon/removeAddon) and the no-op updateUnits were deleted in the
 * 2026-07 collapse.
 */
final class MultiItemSubscriptionServiceTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private const BASE_PRICE_ID = 'price_base_test';

    private const ADDON_PRICE_ID = 'price_pkg_test';

    private BillableModule $baseModule;

    private BillingPackage $plan;

    protected function tearDown(): void
    {
        $this->cleanBillingTables();

        Mockery::close();

        parent::tearDown();
    }

    public function test_build_checkout_sets_quantity_one_per_line_and_returns_url(): void
    {
        $this->seedMatrix();
        $tenant = $this->makeTenant();

        $builder = Mockery::mock(SubscriptionBuilder::class);
        $builder->shouldReceive('withMetadata')->once()->andReturnSelf();
        // trialUntil(end of the 30th day), never trialDays() — see the rationale in
        // MultiItemSubscriptionService: trialDays anchors to session-creation time,
        // landing a hair under 30 days so Stripe advertises "29 days free".
        $builder->shouldReceive('trialUntil')
            ->once()
            ->with(Mockery::on(fn ($until): bool => $until->equalTo(now()->addDays(30)->endOfDay())))
            ->andReturnSelf();
        $builder->shouldReceive('quantity')->once()->with(1, self::BASE_PRICE_ID)->andReturnSelf();
        $builder->shouldReceive('quantity')->once()->with(1, self::ADDON_PRICE_ID)->andReturnSelf();
        $builder->shouldReceive('checkout')->once()->andReturn((object) ['url' => 'https://stripe.test/checkout/cs_123']);

        $owner = Mockery::mock(User::class);
        $owner->shouldReceive('newSubscription')
            ->once()
            ->with('default', [self::BASE_PRICE_ID, self::ADDON_PRICE_ID])
            ->andReturn($builder);

        $url = app(MultiItemSubscriptionService::class)
            ->buildCheckout($owner, $tenant, 'esencial', ['base', 'package'], 'month', 10);

        $this->assertSame('https://stripe.test/checkout/cs_123', $url);
    }

    public function test_build_checkout_also_writes_the_metadata_at_session_level(): void
    {
        // F-10 belt-and-braces: withMetadata() only reaches
        // subscription_data.metadata, so the Checkout SESSION must carry its own copy
        // — otherwise checkout.session.completed arrives with metadata: [] and the
        // paying cluster cannot be resolved.
        $this->seedMatrix();
        $tenant = $this->makeTenant();

        $captured = null;

        $builder = Mockery::mock(SubscriptionBuilder::class);
        $builder->shouldReceive('withMetadata')->once()->andReturnSelf();
        // trialUntil(end of the 30th day), never trialDays() — see the rationale in
        // MultiItemSubscriptionService: trialDays anchors to session-creation time,
        // landing a hair under 30 days so Stripe advertises "29 days free".
        $builder->shouldReceive('trialUntil')
            ->once()
            ->with(Mockery::on(fn ($until): bool => $until->equalTo(now()->addDays(30)->endOfDay())))
            ->andReturnSelf();
        $builder->shouldReceive('quantity')->andReturnSelf();
        $builder->shouldReceive('checkout')
            ->once()
            ->andReturnUsing(function (array $options) use (&$captured): object {
                $captured = $options;

                return (object) ['url' => 'https://stripe.test/checkout/cs_meta'];
            });

        $owner = Mockery::mock(User::class);
        $owner->shouldReceive('newSubscription')->once()->andReturn($builder);

        app(MultiItemSubscriptionService::class)
            ->buildCheckout($owner, $tenant, 'esencial', ['base'], 'month', 10);

        $this->assertSame($tenant->getKey(), $captured['metadata']['tenant_id'] ?? null);
        $this->assertSame('esencial', $captured['metadata']['plan_key'] ?? null);
        $this->assertSame('month', $captured['metadata']['interval'] ?? null);
        $this->assertArrayNotHasKey('ga_client_id', $captured['metadata'], 'Stripe rejects null metadata values');
    }

    public function test_build_checkout_refuses_a_hidden_plan(): void
    {
        // F-17: the owner checkout must resolve PUBLIC plans only. A hidden
        // (negotiated) plan key is otherwise self-servable at a preferential price.
        $this->seedMatrix();
        $this->plan->update(['is_public' => false]);

        $tenant = $this->makeTenant();

        $owner = Mockery::mock(User::class);
        $owner->shouldNotReceive('newSubscription');

        $this->expectException(InvalidArgumentException::class);

        app(MultiItemSubscriptionService::class)
            ->buildCheckout($owner, $tenant, 'esencial', ['base'], 'month');
    }

    public function test_build_checkout_without_modules_uses_every_priced_module_of_the_plan(): void
    {
        $this->seedMatrix();
        $tenant = $this->makeTenant();

        $builder = Mockery::mock(SubscriptionBuilder::class);
        $builder->shouldReceive('withMetadata')->once()->andReturnSelf();
        // trialUntil(end of the 30th day), never trialDays() — see the rationale in
        // MultiItemSubscriptionService: trialDays anchors to session-creation time,
        // landing a hair under 30 days so Stripe advertises "29 days free".
        $builder->shouldReceive('trialUntil')
            ->once()
            ->with(Mockery::on(fn ($until): bool => $until->equalTo(now()->addDays(30)->endOfDay())))
            ->andReturnSelf();
        $builder->shouldReceive('quantity')->once()->with(1, self::BASE_PRICE_ID)->andReturnSelf();
        $builder->shouldReceive('quantity')->once()->with(1, self::ADDON_PRICE_ID)->andReturnSelf();
        $builder->shouldReceive('checkout')->once()->andReturn((object) ['url' => 'https://stripe.test/checkout/cs_789']);

        $owner = Mockery::mock(User::class);
        $owner->shouldReceive('newSubscription')
            ->once()
            ->with('default', [self::BASE_PRICE_ID, self::ADDON_PRICE_ID])
            ->andReturn($builder);

        $url = app(MultiItemSubscriptionService::class)
            ->buildCheckout($owner, $tenant, 'esencial', [], 'month', 10);

        $this->assertSame('https://stripe.test/checkout/cs_789', $url);
    }

    public function test_build_checkout_throws_when_no_module_has_a_billable_price(): void
    {
        $this->seedMatrix();
        // Strip the synced Stripe price → resolveStripePriceIds is empty.
        PlanModulePrice::query()->update(['stripe_price_id' => null]);

        $tenant = $this->makeTenant();

        $owner = Mockery::mock(User::class);
        $owner->shouldNotReceive('newSubscription');

        $this->expectException(CheckoutUnavailableException::class);

        app(MultiItemSubscriptionService::class)
            ->buildCheckout($owner, $tenant, 'esencial', ['base'], 'month');
    }

    public function test_build_checkout_skips_modules_without_a_synced_price(): void
    {
        $this->seedMatrix();
        // amenities has an active (free) price row but no synced Stripe price.
        $amenities = $this->makeBillableModule('amenities', 'Amenidades');
        $this->makePlanModulePrice($this->plan, $amenities, 'month', 0, ['is_free' => true]);

        $tenant = $this->makeTenant();

        $builder = Mockery::mock(SubscriptionBuilder::class);
        $builder->shouldReceive('withMetadata')->once()->andReturnSelf();
        // trialUntil(end of the 30th day), never trialDays() — see the rationale in
        // MultiItemSubscriptionService: trialDays anchors to session-creation time,
        // landing a hair under 30 days so Stripe advertises "29 days free".
        $builder->shouldReceive('trialUntil')
            ->once()
            ->with(Mockery::on(fn ($until): bool => $until->equalTo(now()->addDays(30)->endOfDay())))
            ->andReturnSelf();
        $builder->shouldReceive('quantity')->once()->with(1, self::BASE_PRICE_ID)->andReturnSelf();
        $builder->shouldReceive('checkout')->once()->andReturn((object) ['url' => 'https://stripe.test/checkout/cs_456']);

        $owner = Mockery::mock(User::class);
        $owner->shouldReceive('newSubscription')
            ->once()
            ->with('default', [self::BASE_PRICE_ID])
            ->andReturn($builder);

        $url = app(MultiItemSubscriptionService::class)
            ->buildCheckout($owner, $tenant, 'esencial', ['base', 'amenities'], 'month', 5);

        $this->assertSame('https://stripe.test/checkout/cs_456', $url);
    }

    public function test_build_checkout_grants_no_trial_when_the_tenant_already_had_an_entitlement(): void
    {
        // D1 — a cancel-and-rebuy (or any prior grant) is charged immediately.
        $this->seedMatrix();
        $tenant = $this->makeTenant();

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $this->baseModule->id,
            'billing_package_id' => $this->plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'expires_at' => now()->subDay(),
        ]);

        $builder = Mockery::mock(SubscriptionBuilder::class);
        $builder->shouldReceive('withMetadata')->once()->andReturnSelf();
        $builder->shouldNotReceive('trialDays');
        $builder->shouldReceive('quantity')->once()->with(1, self::BASE_PRICE_ID)->andReturnSelf();
        $builder->shouldReceive('checkout')->once()->andReturn((object) ['url' => 'https://stripe.test/checkout/cs_no_trial']);

        $owner = Mockery::mock(User::class);
        $owner->shouldReceive('newSubscription')->once()->andReturn($builder);

        $url = app(MultiItemSubscriptionService::class)
            ->buildCheckout($owner, $tenant, 'esencial', ['base'], 'month');

        $this->assertSame('https://stripe.test/checkout/cs_no_trial', $url);
    }

    public function test_build_checkout_grants_no_trial_when_the_prior_entitlement_was_soft_deleted(): void
    {
        // D1 — reconcileSubscriptionEntitlements soft-deletes removed items;
        // withTrashed() must still see that history and refuse a second trial.
        $this->seedMatrix();
        $tenant = $this->makeTenant();

        $entitlement = TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $this->baseModule->id,
            'billing_package_id' => $this->plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'expires_at' => now()->addDay(),
        ]);
        $entitlement->delete();

        $builder = Mockery::mock(SubscriptionBuilder::class);
        $builder->shouldReceive('withMetadata')->once()->andReturnSelf();
        $builder->shouldNotReceive('trialDays');
        $builder->shouldReceive('quantity')->once()->with(1, self::BASE_PRICE_ID)->andReturnSelf();
        $builder->shouldReceive('checkout')->once()->andReturn((object) ['url' => 'https://stripe.test/checkout/cs_soft_deleted']);

        $owner = Mockery::mock(User::class);
        $owner->shouldReceive('newSubscription')->once()->andReturn($builder);

        app(MultiItemSubscriptionService::class)
            ->buildCheckout($owner, $tenant, 'esencial', ['base'], 'month');

        $this->addToAssertionCount(1);
    }

    public function test_change_interval_swaps_all_lines_at_quantity_one_and_lets_stripe_date_the_proration(): void
    {
        // The swap carries only the prices (no proration_date option): Stripe dates
        // the proration at now, closing the client-controlled-proration hole (plan 055).
        $subscription = Mockery::mock(Subscription::class);
        $subscription->shouldReceive('hasIncompletePayment')->once()->andReturnFalse();
        $subscription->shouldReceive('swap')->once()->with([
            self::BASE_PRICE_ID => ['quantity' => 1],
            self::ADDON_PRICE_ID => ['quantity' => 1],
        ]);

        app(MultiItemSubscriptionService::class)->changeInterval(
            $subscription,
            [self::BASE_PRICE_ID, self::ADDON_PRICE_ID],
        );

        $this->addToAssertionCount(1);
    }

    public function test_change_interval_is_blocked_by_incomplete_payment(): void
    {
        $subscription = Mockery::mock(Subscription::class);
        $subscription->shouldReceive('hasIncompletePayment')->once()->andReturnTrue();
        $subscription->shouldNotReceive('swap');

        $this->expectException(RuntimeException::class);

        app(MultiItemSubscriptionService::class)->changeInterval($subscription, [self::BASE_PRICE_ID]);
    }

    public function test_preview_change_returns_signed_proration_and_authoritative_recurring_total(): void
    {
        $this->seedMatrix();

        $creditLine = Mockery::mock();
        $creditLine->shouldReceive('isProration')->andReturnTrue();
        $creditLine->shouldReceive('asStripeInvoiceLineItem')->andReturn((object) ['amount' => -5000]);

        $chargeLine = Mockery::mock();
        $chargeLine->shouldReceive('isProration')->andReturnTrue();
        $chargeLine->shouldReceive('asStripeInvoiceLineItem')->andReturn((object) ['amount' => 8000]);

        $recurringLine = Mockery::mock();
        $recurringLine->shouldReceive('isProration')->andReturnFalse();

        $invoice = Mockery::mock(Invoice::class);
        $invoice->shouldReceive('subscriptionProrationDate')->andReturn(1750000000);
        $invoice->shouldReceive('invoiceLineItems')->andReturn([$creditLine, $chargeLine, $recurringLine]);

        $subscription = Mockery::mock(Subscription::class);
        $subscription->shouldReceive('previewInvoice')->once()->with([
            self::BASE_PRICE_ID => ['quantity' => 1],
            self::ADDON_PRICE_ID => ['quantity' => 1],
        ])->andReturn($invoice);

        $result = app(MultiItemSubscriptionService::class)
            ->previewChange($subscription, 'esencial', ['base', 'package'], 'month');

        // -5000 + 8000 = 3000 (signed proration, a net charge).
        $this->assertSame(3000, $result['proration_amount']);
        $this->assertSame(1750000000, $result['proration_date']);
        // Fixed matrix: base (95000) + package (12000) = 107000.
        $this->assertSame(107000, $result['new_recurring_total']);
        $this->assertSame('MXN', $result['currency']);
        $this->assertNotNull($result['effective_at']);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function seedMatrix(): void
    {
        $this->plan = $this->makePlan('esencial');
        $this->baseModule = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $package = $this->makeBillableModule('package', 'Paquetería');

        $this->makePlanModulePrice($this->plan, $this->baseModule, 'month', 95000, [
            'stripe_price_id' => self::BASE_PRICE_ID,
            'stripe_product_id' => 'prod_base_test',
        ]);
        $this->makePlanModulePrice($this->plan, $package, 'month', 12000, [
            'stripe_price_id' => self::ADDON_PRICE_ID,
            'stripe_product_id' => 'prod_pkg_test',
        ]);
    }
}
