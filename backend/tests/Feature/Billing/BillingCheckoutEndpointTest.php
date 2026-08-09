<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Subscription;
use Mockery;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\StripeClient;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\Feature\E2E\ClusterScenario;
use Tests\TestCase;

/**
 * Feature coverage for the authenticated multi-item checkout endpoint (billing
 * v2, per-module): POST /api/billing/checkout.
 *
 * Body: {plan_key, modules?[] (must include base when sent), interval, units?}.
 * Every module becomes one Stripe line at quantity=1 (fixed plan×module price).
 * Network-free: the Stripe SDK client is a Mockery double. This locks the HTTP
 * seam — owner gate (403), already-subscribed (409), no_billable_price (422),
 * contact_sales (422), missing base (422 validation), happy-path url (200).
 */
final class BillingCheckoutEndpointTest extends TestCase
{
    use BillingMatrixFixtures, ClusterScenario {
        ClusterScenario::makeTenant insteadof BillingMatrixFixtures;
    }
    use RefreshDatabase;

    private const BASE_PRICE_ID = 'price_base_checkout';

    private const ADDON_PRICE_ID = 'price_pkg_checkout';

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /** @var array<string, mixed> */
    private array $scn;

    private Tenant $tenant;

    private User $owner;

    private string $ownerEmail;

    private BillableModule $baseModule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scn = $this->buildScenario();
        $this->tenant = $this->scn['tenant'];

        $this->ownerEmail = 'owner.'.$this->scn['emails']['laura'];
        $this->owner = $this->makeAppUser(
            $this->tenant,
            'Owner Encinos',
            $this->ownerEmail,
            Role::SLUG_ACCOUNT_OWNER,
        );

        $this->seedMatrix();
    }

    protected function tearDown(): void
    {
        DB::table('subscriptions')->delete();
        $this->cleanBillingTables();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Mockery::close();

        parent::tearDown();
    }

    public function test_checkout_returns_url_for_owner(): void
    {
        $this->fakeStripeCheckout('https://checkout.stripe.com/c/pay/cs_billing_123');

        $response = $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/billing/checkout', [
                'plan_key' => 'esencial',
                'modules' => ['base', 'package'],
                'interval' => 'month',
                'units' => 10,
            ]);

        $response->assertStatus(200);
        $this->assertSame(
            'https://checkout.stripe.com/c/pay/cs_billing_123',
            $response->json('url'),
        );
    }

    public function test_checkout_without_the_modules_array_uses_the_whole_plan(): void
    {
        $this->fakeStripeCheckout('https://checkout.stripe.com/c/pay/cs_billing_default');

        $response = $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/billing/checkout', [
                'plan_key' => 'esencial',
                'interval' => 'month',
                'units' => 10,
            ]);

        $response->assertStatus(200);
        $this->assertSame(
            'https://checkout.stripe.com/c/pay/cs_billing_default',
            $response->json('url'),
        );
    }

    public function test_checkout_against_a_contact_sales_plan_returns_422_contact_sales(): void
    {
        // A PUBLIC, ACTIVE plan with no price row is the consultative tier.
        $this->makePlan('a_medida', [
            'sort_order' => 15,
            'suggested_unit_min' => 301,
            'suggested_unit_max' => null,
        ]);

        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/billing/checkout', [
                'plan_key' => 'a_medida',
                'interval' => 'month',
                'units' => 500,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'contact_sales');
    }

    public function test_checkout_without_billable_price_returns_422_with_code(): void
    {
        // The base module cell has no synced Stripe price → resolveStripePriceIds is
        // empty → service throws no_billable_price.
        PlanModulePrice::query()
            ->where('billable_module_id', $this->baseModule->id)
            ->update(['stripe_price_id' => null]);

        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/billing/checkout', [
                'plan_key' => 'esencial',
                'modules' => ['base'],
                'interval' => 'month',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_billable_price');
    }

    public function test_checkout_conflicts_with_code_when_owner_already_subscribed(): void
    {
        Subscription::create([
            'user_id' => $this->owner->id,
            'type' => 'default',
            'stripe_id' => 'sub_billing_existing_123',
            'stripe_status' => 'active',
            'stripe_price' => self::BASE_PRICE_ID,
            'quantity' => 1,
        ]);

        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/billing/checkout', [
                'plan_key' => 'esencial',
                'modules' => ['base', 'package'],
                'interval' => 'month',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_subscribed');
    }

    public function test_checkout_conflicts_when_stripe_has_an_active_subscription_without_a_local_row(): void
    {
        // F-16 (double charge, reproduced live for MX$900 on a MX$450 plan): the
        // `already_subscribed` guard read ONLY the local `subscriptions` table, which
        // is populated by webhooks. If the webhook never landed (F-10) or is late,
        // the owner who already paid has no local row and can buy a SECOND
        // subscription. Stripe is the source of truth and must be consulted.
        $this->fakeStripeCheckout('https://checkout.stripe.com/c/pay/should_not_be_reached');
        $this->fakeStripeActiveSubscriptions([
            (object) ['id' => 'sub_live_in_stripe', 'status' => 'active'],
        ]);
        $this->giveOwnerAStripeCustomer();

        $this->assertSame(0, DB::table('subscriptions')->where('user_id', $this->owner->id)->count());

        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/billing/checkout', [
                'plan_key' => 'esencial',
                'interval' => 'month',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_subscribed');
    }

    public function test_checkout_is_serialized_per_owner_so_concurrent_calls_cannot_open_two_sessions(): void
    {
        // F-16 (aggravating half): the guard was read-then-act with no lock — five
        // concurrent calls returned five 200s. The owner lock makes the
        // check + session creation atomic; a caller that cannot take it is told to
        // retry instead of opening a second Checkout.
        $this->fakeStripeCheckout('https://checkout.stripe.com/c/pay/cs_locked');

        $token = $this->login($this->ownerEmail);

        // Same accessor the controller uses: the CENTRAL cache store, not the
        // tenant-scoped Cache facade (Stancl swaps the manager during a tenant
        // request, so a facade lock would live in a different store).
        $lock = app('cache.store')->lock('billing:checkout:'.$this->owner->id, 30);
        $this->assertTrue($lock->get(), 'the test must hold the lock a concurrent request would take');

        try {
            $this->as($token, $this->tenant)
                ->postJson('/api/billing/checkout', [
                    'plan_key' => 'esencial',
                    'interval' => 'month',
                ])
                ->assertStatus(409)
                ->assertJsonPath('code', 'checkout_in_progress');
        } finally {
            $lock->forceRelease();
        }
    }

    public function test_checkout_rejects_a_hidden_plan(): void
    {
        // F-17: `esencial` is turned into a hidden (negotiated) plan. Guessing its
        // key must NOT buy the same band at a preferential price.
        BillingPackage::query()->where('key', 'esencial')->update(['is_public' => false]);

        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/billing/checkout', [
                'plan_key' => 'esencial',
                'interval' => 'month',
            ])
            ->assertStatus(422);
    }

    public function test_checkout_without_base_module_is_rejected(): void
    {
        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/billing/checkout', [
                'plan_key' => 'esencial',
                'modules' => ['package'],
                'interval' => 'month',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('modules');
    }

    public function test_checkout_is_forbidden_for_non_owner(): void
    {
        $this->as($this->login($this->scn['emails']['laura']), $this->tenant)
            ->postJson('/api/billing/checkout', [
                'plan_key' => 'esencial',
                'modules' => ['base'],
                'interval' => 'month',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'not_account_owner');
    }

    /**
     * Swap the Stripe SDK client for a double returning a fake customer + checkout
     * session (with a URL), so checkout runs without network.
     */
    private function fakeStripeCheckout(string $url): void
    {
        $customer = Customer::constructFrom(['id' => 'cus_billing_123']);
        $session = Session::constructFrom(['id' => 'cs_billing_123', 'url' => $url]);

        $sessions = Mockery::mock();
        $sessions->shouldReceive('create')->andReturn($session);

        $checkout = Mockery::mock();
        $checkout->sessions = $sessions;

        $customers = Mockery::mock();
        $customers->shouldReceive('create')->andReturn($customer);
        $customers->shouldReceive('retrieve')->andReturn($customer);

        $client = Mockery::mock(StripeClient::class);
        $client->shouldReceive('getService')->with('customers')->andReturn($customers);
        $client->shouldReceive('getService')->with('checkout')->andReturn($checkout);

        $this->app->bind(StripeClient::class, fn () => $client);
    }

    /**
     * Give the owner a Stripe customer id so the controller's live Stripe check has
     * something to look up (Cashier only writes it on the first charge).
     */
    private function giveOwnerAStripeCustomer(string $customerId = 'cus_billing_owner'): void
    {
        $this->owner->forceFill(['stripe_id' => $customerId])->save();
    }

    /**
     * Make the Stripe SDK double answer `subscriptions->all()` with the given rows,
     * simulating what Stripe knows about the customer regardless of the local table.
     *
     * @param  array<int, object>  $subscriptions
     */
    private function fakeStripeActiveSubscriptions(array $subscriptions): void
    {
        $service = Mockery::mock();
        $service->shouldReceive('all')->andReturn((object) ['data' => $subscriptions]);

        $client = $this->app->make(StripeClient::class);
        $client->shouldReceive('getService')->with('subscriptions')->andReturn($service);
    }

    private function seedMatrix(): void
    {
        $plan = $this->makePlan('esencial');
        $this->baseModule = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $package = $this->makeBillableModule('package', 'Paquetería');

        $this->makePlanModulePrice($plan, $this->baseModule, 'month', 95000, [
            'stripe_price_id' => self::BASE_PRICE_ID,
            'stripe_product_id' => 'prod_base_checkout',
        ]);
        $this->makePlanModulePrice($plan, $package, 'month', 12000, [
            'stripe_price_id' => self::ADDON_PRICE_ID,
            'stripe_product_id' => 'prod_pkg_checkout',
        ]);
    }
}
