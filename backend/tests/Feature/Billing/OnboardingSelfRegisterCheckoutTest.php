<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Listeners\StripeWebhookListener;
use Database\Seeders\ResetPermissionTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Events\WebhookReceived;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\System\Models\System;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Events\TenantCreated;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\StripeClient;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * Onboarding flow #1 — AUTO-REGISTER of a brand-new organization, end to end (billing v2):
 *
 *   POST /api/auth/register  (public, creates owner + tenant + base-module TRIAL)
 *     -> POST /api/billing/checkout {plan_key, modules, interval} (Stripe faked)
 *       -> checkout.session.completed webhook (per-module entitlements + ACTIVO)
 *         -> GET /api/auth/me reports genuine paid access (is_blocked=false)
 *
 * The webhook maps subscription item stripe_price_id → plan_module_price cell →
 * (billable_module, plan). Network-free; transaction-less central writes reset in
 * tearDown.
 */
final class OnboardingSelfRegisterCheckoutTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    private const BASE_PRICE_ID = 'price_base_selfreg';

    private const ADDON_PRICE_ID = 'price_addon_selfreg';

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        // register() assigns the account.owner role and reads permissions via roles,
        // so the real role/permission matrix must exist (RefreshDatabase does not run
        // app seeders). This is the production ResetPermissionTableSeeder.
        Artisan::call('db:seed', [
            '--class' => ResetPermissionTableSeeder::class,
            '--force' => true,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Event::fake([TenantCreated::class]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->cleanBillingTables();
        DB::table('user_tenant')->delete();
        DB::table('subscriptions')->delete();
        DB::table('tenant')->delete();
        DB::table('user')->delete();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        \Mockery::close();

        parent::tearDown();
    }

    // =========================================================================
    // HAPPY PATH — register -> checkout -> webhook -> paid access
    // =========================================================================

    public function test_self_register_to_paid_access_full_loop(): void
    {
        [$plan, $base, $addon, $baseSystem, $addonSystem] = $this->seedMatrixWithAccess();

        $email = 'owner.'.Str::lower(Str::random(8)).'@encinos.mx';
        $register = $this->postRegister($email)->assertStatus(200);

        $token = $register->json('data.access_token');
        $tenantId = $register->json('data.tenant.id');
        $this->assertNotEmpty($token);
        $this->assertNotEmpty($tenantId);
        $this->assertTrue($register->json('data.subscription.is_owner'));

        // ── Owner initiates checkout (Stripe faked) → receives a Checkout URL ──
        $this->fakeStripeCheckout('https://checkout.stripe.com/c/pay/cs_selfreg_1');

        $checkout = $this->authed($token, $tenantId)->postJson('/api/billing/checkout', [
            'plan_key' => $plan->key,
            'modules' => ['base', 'package'],
            'interval' => 'month',
            'units' => 10,
        ]);

        $checkout->assertStatus(200);
        $this->assertSame(
            'https://checkout.stripe.com/c/pay/cs_selfreg_1',
            $checkout->json('url'),
        );

        // ── Stripe confirms payment → webhook projects entitlements + activates ──
        $periodEnd = now()->addMonth();

        $this->dispatchWebhook('checkout.session.completed', [
            'object' => [
                'id' => 'cs_selfreg_1',
                'payment_status' => 'paid',
                'subscription' => $this->subscriptionPayload('sub_selfreg_1', [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, $periodEnd->timestamp),
                    $this->itemPayload('si_addon', self::ADDON_PRICE_ID, 10, $periodEnd->timestamp),
                ]),
                'metadata' => ['tenant_id' => $tenantId, 'plan_key' => $plan->key, 'interval' => 'month'],
            ],
        ]);

        // Entitlements (source=stripe) for both contracted modules.
        $baseEntitlement = TenantEntitlement::query()
            ->where('tenant_id', $tenantId)
            ->where('billable_module_id', $base->id)
            ->first();
        $this->assertNotNull($baseEntitlement);
        $this->assertSame(TenantEntitlement::SOURCE_STRIPE, $baseEntitlement->source);
        $this->assertSame($plan->id, $baseEntitlement->billing_package_id);
        $this->assertSame('sub_selfreg_1', $baseEntitlement->stripe_subscription_id);
        $this->assertSame($periodEnd->timestamp, $baseEntitlement->expires_at->timestamp);

        $this->assertTrue(
            TenantEntitlement::where('tenant_id', $tenantId)->where('billable_module_id', $addon->id)->exists(),
            'add-on module entitlement must be granted',
        );

        // Access projection + status flip.
        $this->assertTrue($this->systemAttached($tenantId, $baseSystem));
        $this->assertTrue($this->systemAttached($tenantId, $addonSystem));
        $this->assertSame(Tenant::STATUS_ACTIVO, Tenant::find($tenantId)->status);

        // ── /me now reports GENUINE paid access ──
        $me = $this->authed($token, $tenantId)->getJson('/api/auth/me')->assertStatus(200);
        $this->assertFalse($me->json('data.subscription.is_blocked'));
    }

    // =========================================================================
    // NO LOCAL TRIAL — registration grants zero entitlements and is born blocked
    // =========================================================================

    public function test_self_register_grants_no_local_trial_and_stays_blocked_until_checkout(): void
    {
        // A base module + plan must exist so checkout (later in the flow) can
        // resolve one, but registration itself must not touch it.
        $this->seedMatrixWithAccess();

        $email = 'trial.'.Str::lower(Str::random(8)).'@encinos.mx';
        $register = $this->postRegister($email)->assertStatus(200);

        $token = $register->json('data.access_token');
        $tenantId = $register->json('data.tenant.id');
        $this->assertNotEmpty($token);
        $this->assertNotEmpty($tenantId);

        // Registration grants ZERO entitlements — the trial now lives in Stripe
        // Checkout (source=stripe), granted only once the owner pays with a card.
        $this->assertSame(
            0,
            TenantEntitlement::query()->where('tenant_id', $tenantId)->count(),
            'register must not grant any local entitlement',
        );

        $tenant = Tenant::find($tenantId);
        $this->assertSame(Tenant::STATUS_PENDIENTE_PAGO, $tenant->status);

        // COHERENCE: register RESPONSE reports the SAME state /me does — a fresh
        // organization that never chose a plan, blocked.
        $this->assertSame('none', $register->json('data.subscription.status'));
        $this->assertTrue(
            $register->json('data.subscription.is_blocked'),
            'a freshly registered organization is blocked until it picks a plan and pays',
        );

        $me = $this->authed($token, $tenantId)->getJson('/api/auth/me')->assertStatus(200);
        $this->assertSame('none', $me->json('data.subscription.status'));
        $this->assertTrue($me->json('data.subscription.is_blocked'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function postRegister(string $email): TestResponse
    {
        $this->resetGuards();

        return $this->postJson('/api/auth/register', [
            'name' => 'Owner '.Str::random(5),
            'organization_name' => 'Organization '.Str::random(5),
            'email' => $email,
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ]);
    }

    private function authed(string $token, string $tenantId): static
    {
        $this->resetGuards();
        $this->withToken($token)->withHeader('X-Tenant-Id', $tenantId);

        return $this;
    }

    private function resetGuards(): void
    {
        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }

    /**
     * Seed a plan + base & package billable modules (each wired to a system) + the
     * plan_module_price cells carrying the Stripe price ids the webhook maps.
     *
     * @return array{0: BillingPackage, 1: BillableModule, 2: BillableModule, 3: System, 4: System}
     */
    private function seedMatrixWithAccess(): array
    {
        $baseSystem = System::factory()->create();
        $addonSystem = System::factory()->create();
        $baseModuleRow = $this->makeModuleIn($baseSystem, 'Cobros');
        $addonModuleRow = $this->makeModuleIn($addonSystem, 'Paquetería');

        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [$baseModuleRow], isBase: true);
        $addon = $this->makeBillableModule('package', 'Paquetería', [$addonModuleRow]);

        $this->makePlanModulePrice($plan, $base, 'month', 95000, [
            'stripe_price_id' => self::BASE_PRICE_ID,
            'stripe_product_id' => 'prod_base_selfreg',
        ]);
        $this->makePlanModulePrice($plan, $addon, 'month', 12000, [
            'stripe_price_id' => self::ADDON_PRICE_ID,
            'stripe_product_id' => 'prod_addon_selfreg',
        ]);

        return [$plan, $base, $addon, $baseSystem, $addonSystem];
    }

    private function fakeStripeCheckout(string $url): void
    {
        $customer = Customer::constructFrom(['id' => 'cus_selfreg_1']);
        $session = Session::constructFrom(['id' => 'cs_selfreg_1', 'url' => $url]);

        $sessions = \Mockery::mock();
        $sessions->shouldReceive('create')->andReturn($session);

        $checkout = \Mockery::mock();
        $checkout->sessions = $sessions;

        $customers = \Mockery::mock();
        $customers->shouldReceive('create')->andReturn($customer);
        $customers->shouldReceive('retrieve')->andReturn($customer);

        $client = \Mockery::mock(StripeClient::class);
        $client->shouldReceive('getService')->with('customers')->andReturn($customers);
        $client->shouldReceive('getService')->with('checkout')->andReturn($checkout);

        $this->app->bind(StripeClient::class, fn () => $client);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function dispatchWebhook(string $type, array $data): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        app(StripeWebhookListener::class)->handle(
            new WebhookReceived([
                'id' => 'evt_'.Str::lower(Str::random(16)),
                'type' => $type,
                'data' => $data,
            ]),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function subscriptionPayload(string $id, array $items): array
    {
        return [
            'id' => $id,
            'items' => ['data' => $items],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemPayload(string $itemId, string $priceId, int $quantity, int $currentPeriodEnd): array
    {
        return [
            'id' => $itemId,
            'quantity' => $quantity,
            'current_period_end' => $currentPeriodEnd,
            'price' => [
                'id' => $priceId,
                'recurring' => ['interval' => 'month'],
            ],
        ];
    }

    private function systemAttached(string $tenantId, System $system): bool
    {
        return DB::table('system_tenant')
            ->where('tenant_id', $tenantId)
            ->where('system_id', $system->id)
            ->where('is_addon', false)
            ->exists();
    }
}
