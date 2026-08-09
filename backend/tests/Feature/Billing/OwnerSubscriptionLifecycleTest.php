<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Listeners\StripeWebhookListener;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Cashier\Events\WebhookReceived;
use Mockery;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModule;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\MultiItemSubscriptionService;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Tests\Feature\E2E\ClusterScenario;
use Tests\TestCase;

/**
 * OWNER self-serve subscription LIFECYCLE coverage (billing v2, per-module + plan).
 *
 * Covers: base+addon checkout projection (trial → stripe), the multi-item
 * presenter shape, change-interval cascade + availability guard, proration
 * preview, cancel (period-end grace), resume, and past_due grace.
 *
 * The per-module self-serve endpoints (add-addon / remove-addon / change-units)
 * were removed in the 2026-07 collapse; only their absence is asserted here.
 *
 * Network-free: the Stripe SDK client is a Mockery double and webhook transitions
 * are driven by dispatching Cashier's WebhookReceived event with a crafted payload.
 */
final class OwnerSubscriptionLifecycleTest extends TestCase
{
    use ClusterScenario;
    use RefreshDatabase;

    private const BASE_PRICE_ID = 'price_base_lifecycle';

    private const ADDON_PRICE_ID = 'price_addon_lifecycle';

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /** @var array<string, mixed> */
    private array $scn;

    private Tenant $tenant;

    private User $owner;

    private string $ownerEmail;

    private BillingPackage $plan;

    private BillableModule $base;

    private BillableModule $addon;

    /** @var array<string, mixed>|null */
    private ?array $capturedSubscriptionUpdate = null;

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
        DB::table('system_tenant')->delete();
        DB::table('module_tenant')->delete();
        DB::table('tenant_entitlement')->delete();
        DB::table('plan_module_price')->delete();
        DB::table('plan_module')->delete();
        DB::table('billable_module_system_module')->delete();
        DB::table('billable_module')->delete();
        DB::table('billing_package')->delete();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Mockery::close();

        parent::tearDown();
    }

    // =========================================================================
    // 1. Contract base + add-on → webhook → both stripe entitlements, trial gone
    // =========================================================================

    public function test_checkout_base_and_addon_grants_stripe_entitlements_and_replaces_trial(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_PENDIENTE_PAGO]);
        TenantEntitlement::create([
            'tenant_id' => $this->tenant->id,
            'billable_module_id' => $this->base->id,
            'billing_package_id' => $this->plan->id,
            'source' => TenantEntitlement::SOURCE_TRIAL,
            'interval' => 'month',
            'quantity' => 6,
            'expires_at' => now()->addDays(14),
        ]);

        $periodEnd = now()->addMonth();

        $this->dispatchWebhook('checkout.session.completed', [
            'object' => [
                'id' => 'cs_lifecycle_1',
                'payment_status' => 'paid',
                'subscription' => [
                    'id' => 'sub_lifecycle_1',
                    'items' => ['data' => [
                        $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, $periodEnd->timestamp),
                        $this->itemPayload('si_addon', self::ADDON_PRICE_ID, 10, $periodEnd->timestamp),
                    ]],
                ],
                'metadata' => ['tenant_id' => $this->tenant->id, 'plan_key' => 'esencial', 'interval' => 'month'],
            ],
        ]);

        // Base entitlement UPGRADED in place: grant() upserts by (tenant, module),
        // so the trial row's source flips to stripe (the trial is "replaced").
        $baseEnt = $this->entitlementFor($this->base);
        $this->assertNotNull($baseEnt);
        $this->assertSame(TenantEntitlement::SOURCE_STRIPE, $baseEnt->source);
        $this->assertSame(10, $baseEnt->quantity);
        $this->assertSame('sub_lifecycle_1', $baseEnt->stripe_subscription_id);
        $this->assertSame($periodEnd->timestamp, $baseEnt->expires_at->timestamp);

        // Add-on entitlement created, also source=stripe.
        $addonEnt = $this->entitlementFor($this->addon);
        $this->assertNotNull($addonEnt);
        $this->assertSame(TenantEntitlement::SOURCE_STRIPE, $addonEnt->source);
        $this->assertSame('si_addon', $addonEnt->stripe_item_id);

        // No lingering trial entitlement.
        $this->assertSame(
            0,
            TenantEntitlement::where('tenant_id', $this->tenant->id)
                ->where('source', TenantEntitlement::SOURCE_TRIAL)
                ->count(),
            'the trial entitlement must be replaced by a stripe one',
        );

        $this->assertSame(Tenant::STATUS_ACTIVO, Tenant::find($this->tenant->id)->status);
        $state = app(SubscriptionStateService::class)->narrativeFor($this->owner, $this->tenant->fresh());
        $this->assertFalse($state['is_blocked']);
        $this->assertSame('active', $state['status']);
    }

    // =========================================================================
    // 2. Add an add-on via a fresh checkout while ALREADY subscribed → 409
    // =========================================================================

    public function test_add_addon_while_subscribed_is_blocked_by_checkout_409_gap(): void
    {
        Subscription::create([
            'user_id' => $this->owner->id,
            'type' => 'default',
            'stripe_id' => 'sub_already_lifecycle',
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

    // =========================================================================
    // 3. Self-serve multi-item endpoints require an active multi-item subscription
    // =========================================================================

    public function test_self_serve_multi_item_endpoints_require_an_active_multi_item_subscription(): void
    {
        $login = $this->login($this->ownerEmail);

        $this->as($login, $this->tenant)
            ->postJson('/api/config/subscription/change-interval', ['interval' => 'year'])
            ->assertStatus(409);

        $this->as($login, $this->tenant)
            ->postJson('/api/config/subscription/preview', ['change_type' => 'change_interval', 'interval' => 'year'])
            ->assertStatus(409);
    }

    // =========================================================================
    // 7. getSubscription describes the MULTI-ITEM subscription (plan + modules)
    // =========================================================================

    public function test_get_subscription_describes_multi_item_cluster_without_a_legacy_plan(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_ACTIVO]);
        $this->grantStripeEntitlement($this->base, 30, 'sub_mi_get', 'si_base');
        $this->grantStripeEntitlement($this->addon, 30, 'sub_mi_get', 'si_addon');

        $response = $this->as($this->login($this->ownerEmail), $this->tenant)
            ->getJson('/api/config/subscription')
            ->assertStatus(200)
            ->assertJsonPath('data.is_multi_item', true)
            // F-25 — current_plan now describes the live entitlement (any source),
            // and agrees with the Stripe-only multi_item block for a card cluster.
            ->assertJsonPath('data.current_plan.key', 'esencial')
            ->assertJsonPath('data.current_plan.source', 'stripe')
            ->assertJsonPath('data.current_plan.total', 107000)
            ->assertJsonPath('data.multi_item.interval', 'month')
            ->assertJsonPath('data.multi_item.currency', 'MXN')
            ->assertJsonPath('data.multi_item.plan.key', 'esencial');

        $modules = $response->json('data.multi_item.modules');
        $this->assertCount(2, $modules);
        $this->assertEqualsCanonicalizing(['base', 'package'], array_column($modules, 'key'));

        // Server-authoritative amounts (minor units): base 95000 + addon 12000.
        $this->assertSame(107000, $response->json('data.multi_item.total'));

        $base = collect($modules)->firstWhere('key', 'base');
        $this->assertSame(95000, $base['subtotal']);
        $this->assertTrue($base['is_base']);

        // A cluster with no trials still gets an (empty) trials block.
        $this->assertSame([], $response->json('data.trials'));
    }

    // =========================================================================
    // 7b. getSubscription exposes ACTIVE per-module trials (expiry + days_left)
    // =========================================================================

    public function test_get_subscription_exposes_active_module_trials_with_expiry_and_days_left(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_ACTIVO]);

        $trial = $this->grantTrialEntitlement($this->base, now()->addDays(5));
        // Compare against the persisted value (round-tripped through the DB), so a
        // sub-second timestamp rounding difference cannot make the assertion flake.
        $expectedExpiresAt = $trial->fresh()->expires_at->toIso8601String();

        $response = $this->as($this->login($this->ownerEmail), $this->tenant)
            ->getJson('/api/config/subscription')
            ->assertStatus(200);

        $trials = $response->json('data.trials');
        $this->assertCount(1, $trials);

        $this->assertSame('base', $trials[0]['module_key']);
        $this->assertSame('Plan Base', $trials[0]['module_name']);
        $this->assertSame('esencial', $trials[0]['plan_key']);
        $this->assertSame('Esencial', $trials[0]['plan_name']);
        $this->assertSame($expectedExpiresAt, $trials[0]['expires_at']);
        $this->assertSame(5, $trials[0]['days_left']);
    }

    public function test_get_subscription_orders_trials_by_nearest_expiry_and_expires_today_is_zero_days(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_ACTIVO]);

        // Two active trials: the add-on expires later than the base — nearest first.
        $this->grantTrialEntitlement($this->addon, now()->addDays(10));
        $this->grantTrialEntitlement($this->base, now()->endOfDay()); // expires today

        $trials = $this->as($this->login($this->ownerEmail), $this->tenant)
            ->getJson('/api/config/subscription')
            ->assertStatus(200)
            ->json('data.trials');

        $this->assertSame(['base', 'package'], array_column($trials, 'module_key'));
        // Expires today → still active, 0 whole days left.
        $this->assertSame(0, $trials[0]['days_left']);
        $this->assertSame(10, $trials[1]['days_left']);
    }

    public function test_get_subscription_returns_empty_trials_when_owner_has_none(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_ACTIVO]);

        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->getJson('/api/config/subscription')
            ->assertStatus(200)
            ->assertJsonPath('data.trials', []);
    }

    public function test_get_subscription_keeps_paid_stripe_modules_out_of_trials(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_ACTIVO]);

        // Base is PAID (stripe); the add-on is on a trial.
        $this->grantStripeEntitlement($this->base, 30, 'sub_mix', 'si_base');
        $this->grantTrialEntitlement($this->addon, now()->addDays(7));

        $response = $this->as($this->login($this->ownerEmail), $this->tenant)
            ->getJson('/api/config/subscription')
            ->assertStatus(200);

        // The paid base stays in multi_item, absent from trials.
        $this->assertEqualsCanonicalizing(
            ['base'],
            array_column($response->json('data.multi_item.modules'), 'key'),
        );

        $trials = $response->json('data.trials');
        $this->assertSame(['package'], array_column($trials, 'module_key'));
    }

    // =========================================================================
    // 8. Proration preview (self-serve) — delegates to the service
    // =========================================================================

    public function test_preview_change_interval_returns_the_service_proration(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_ACTIVO]);
        $this->seedYearPrices();
        $this->grantStripeEntitlement($this->base, 10, 'sub_prev', 'si_base');
        $this->seedActiveCashierSubscription('sub_prev');

        $service = Mockery::mock(MultiItemSubscriptionService::class);
        $service->shouldReceive('previewChange')
            ->once()
            ->with(
                Mockery::any(),
                'esencial',
                Mockery::on(fn ($keys): bool => in_array('base', $keys, true)),
                'year',
            )
            ->andReturn([
                'proration_amount' => 12345,
                'proration_date' => 1750000000,
                'new_recurring_total' => 107000,
                'currency' => 'MXN',
                'effective_at' => '2025-06-15T00:00:00+00:00',
            ]);
        $this->app->instance(MultiItemSubscriptionService::class, $service);

        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/config/subscription/preview', ['change_type' => 'change_interval', 'interval' => 'year'])
            ->assertStatus(200)
            ->assertJsonPath('data.proration_amount', 12345)
            ->assertJsonPath('data.proration_date', 1750000000)
            ->assertJsonPath('data.new_recurring_total', 107000);
    }

    public function test_preview_rejects_the_retired_addon_change_types(): void
    {
        // F-14: add_addon / remove_addon survived the 2026-07 collapse in the preview
        // validator even though no endpoint can APPLY them — dead surface that
        // contradicts the refactor contract. change_interval is the only change left.
        $this->tenant->update(['status' => Tenant::STATUS_ACTIVO]);
        $this->grantStripeEntitlement($this->base, 10, 'sub_dead', 'si_base');
        $this->seedActiveCashierSubscription('sub_dead');

        $login = $this->login($this->ownerEmail);

        foreach (['add_addon', 'remove_addon'] as $changeType) {
            $this->as($login, $this->tenant)
                ->postJson('/api/config/subscription/preview', [
                    'change_type' => $changeType,
                    'module_key' => 'package',
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('change_type');
        }
    }

    // =========================================================================
    // 9-11. The per-module self-serve endpoints are GONE (2026-07 collapse)
    // =========================================================================

    public function test_per_module_self_serve_endpoints_are_removed(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_ACTIVO]);
        $this->grantStripeEntitlement($this->base, 10, 'sub_gone', 'si_base');
        $this->seedActiveCashierSubscription('sub_gone');

        $login = $this->login($this->ownerEmail);

        foreach ([
            ['/api/config/subscription/add-addon', ['module_key' => 'package']],
            ['/api/config/subscription/remove-addon', ['module_key' => 'package']],
            ['/api/config/subscription/change-units', ['units' => 40]],
        ] as [$route, $payload]) {
            $this->as($login, $this->tenant)
                ->postJson($route, $payload)
                ->assertStatus(404);
        }
    }

    // =========================================================================
    // F-18. Billing MUTATIONS are account-owner only, not merely manage.subscription
    // =========================================================================

    public function test_billing_mutations_are_forbidden_for_a_non_owner_manager(): void
    {
        // F-18: the seeded `administrator` role carries manage.subscription, so a
        // hired manager could open the Stripe portal (change the card, cancel) and
        // switch the cluster's plan — while /billing/checkout already demanded the
        // account owner. The card and the subscription belong to the owner; the
        // conservative reading wins until the product owner says otherwise.
        $this->tenant->update(['status' => Tenant::STATUS_ACTIVO]);
        $this->grantStripeEntitlement($this->base, 10, 'sub_authz', 'si_base');
        $this->seedActiveCashierSubscription('sub_authz');

        // Laura is `administrator` (manage.subscription) but NOT the account owner.
        $token = $this->login($this->scn['emails']['laura']);

        $this->as($token, $this->tenant)
            ->getJson('/api/config/subscription/portal')
            ->assertStatus(403)
            ->assertJsonPath('code', 'not_account_owner');

        foreach ([
            '/api/config/subscription/cancel',
            '/api/config/subscription/resume',
        ] as $route) {
            $this->as($token, $this->tenant)
                ->postJson($route)
                ->assertStatus(403)
                ->assertJsonPath('code', 'not_account_owner');
        }

        $this->as($token, $this->tenant)
            ->postJson('/api/config/subscription/change-billing-plan', ['plan_key' => 'esencial'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'not_account_owner');
    }

    public function test_reading_the_subscription_stays_open_to_managers(): void
    {
        // The owner gate is deliberately scoped to MUTATIONS: a treasurer/admin must
        // still be able to SEE what the cluster pays.
        $this->tenant->update(['status' => Tenant::STATUS_ACTIVO]);
        $this->grantStripeEntitlement($this->base, 10, 'sub_read_authz', 'si_base');

        $this->as($this->login($this->scn['emails']['laura']), $this->tenant)
            ->getJson('/api/config/subscription')
            ->assertStatus(200);
    }

    // =========================================================================
    // 12. Apply change-interval → cascade via service + availability guard
    // =========================================================================

    public function test_change_interval_cascades_all_lines_via_service(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_ACTIVO]);
        $this->seedYearPrices();
        $this->grantStripeEntitlement($this->base, 10, 'sub_int', 'si_base');
        $this->grantStripeEntitlement($this->addon, 10, 'sub_int', 'si_addon');
        $this->seedActiveCashierSubscription('sub_int');

        $service = Mockery::mock(MultiItemSubscriptionService::class);
        $service->shouldReceive('changeInterval')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::on(fn ($ids): bool => in_array('price_base_year', $ids, true) && in_array('price_addon_year', $ids, true)),
            );
        $this->app->instance(MultiItemSubscriptionService::class, $service);

        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/config/subscription/change-interval', ['interval' => 'year'])
            ->assertStatus(200);
    }

    public function test_change_interval_updates_the_local_entitlement_in_the_same_request(): void
    {
        // F-11: Stripe was swapped correctly but the entitlement kept the OLD
        // interval, so GET /config/subscription answered "$7,410 / año" next to a
        // monthly next-charge date. The fix is symmetric with change-billing-plan,
        // which already converges locally instead of waiting for the webhook.
        $this->tenant->update(['status' => Tenant::STATUS_ACTIVO]);
        $this->seedYearPrices();
        $this->grantStripeEntitlement($this->base, 10, 'sub_int_local', 'si_base');
        $this->grantStripeEntitlement($this->addon, 10, 'sub_int_local', 'si_addon');
        $this->seedActiveCashierSubscription('sub_int_local');

        $service = Mockery::mock(MultiItemSubscriptionService::class);
        $service->shouldReceive('changeInterval')->once();
        $this->app->instance(MultiItemSubscriptionService::class, $service);

        $response = $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/config/subscription/change-interval', ['interval' => 'year'])
            ->assertStatus(200);

        $this->assertSame('year', $this->entitlementFor($this->base)->interval);
        $this->assertSame('year', $this->entitlementFor($this->addon)->interval);
        // The presented payload must agree with itself immediately.
        $this->assertSame('year', $response->json('data.interval'));
    }

    public function test_change_interval_rejects_when_target_interval_not_available(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_ACTIVO]);
        // Only month prices seeded → no year price for the base.
        $this->grantStripeEntitlement($this->base, 10, 'sub_int2', 'si_base');
        $this->seedActiveCashierSubscription('sub_int2');

        $service = Mockery::mock(MultiItemSubscriptionService::class);
        $service->shouldNotReceive('changeInterval');
        $this->app->instance(MultiItemSubscriptionService::class, $service);

        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/config/subscription/change-interval', ['interval' => 'year'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'interval_unavailable');
    }

    // =========================================================================
    // 4. Cancel → grace until period end (entitlements untouched) → block on delete
    // =========================================================================

    public function test_cancel_keeps_access_until_period_end_then_blocks_after_deletion(): void
    {
        $subscriptionId = 'sub_cancel_lifecycle';
        $periodEnd = now()->addMonth();

        TenantEntitlement::create([
            'tenant_id' => $this->tenant->id,
            'billable_module_id' => $this->base->id,
            'billing_package_id' => $this->plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => 'month',
            'quantity' => 10,
            'expires_at' => $periodEnd,
            'stripe_subscription_id' => $subscriptionId,
            'stripe_item_id' => 'si_base',
        ]);
        $this->tenant->update(['status' => Tenant::STATUS_ACTIVO]);

        $subscription = Subscription::create([
            'user_id' => $this->owner->id,
            'type' => 'default',
            'stripe_id' => $subscriptionId,
            'stripe_status' => 'active',
            'stripe_price' => self::BASE_PRICE_ID,
            'quantity' => 10,
            'trial_ends_at' => now()->addDays(20),
            'ends_at' => null,
        ]);

        $this->fakeStripeClient();

        $response = $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/config/subscription/cancel')
            ->assertStatus(200);

        $this->assertIsArray($this->capturedSubscriptionUpdate, 'subscriptions->update() was not called');
        $this->assertTrue($this->capturedSubscriptionUpdate['cancel_at_period_end'] ?? null);
        $this->assertArrayNotHasKey('proration_behavior', $this->capturedSubscriptionUpdate);
        $this->assertArrayNotHasKey('cancel_at', $this->capturedSubscriptionUpdate);

        $this->assertNotNull($subscription->fresh()->ends_at, 'subscription must be scheduled to end (grace)');
        $this->assertTrue($response->json('data.cancel_at_period_end'));

        // Entitlements are UNTOUCHED — access continues through the paid period.
        $baseEnt = $this->entitlementFor($this->base);
        $this->assertSame($periodEnd->timestamp, $baseEnt->expires_at->timestamp, 'cancel must not shorten the entitlement');
        $this->assertSame(1, TenantEntitlement::where('tenant_id', $this->tenant->id)->active()->count());

        $duringGrace = app(SubscriptionStateService::class)->narrativeFor($this->owner, $this->tenant->fresh());
        $this->assertFalse($duringGrace['is_blocked'], 'owner keeps access during the grace period');

        $this->dispatchWebhook('customer.subscription.deleted', [
            'object' => ['id' => $subscriptionId, 'metadata' => ['tenant_id' => $this->tenant->id]],
        ]);

        $this->assertSame(
            0,
            TenantEntitlement::where('tenant_id', $this->tenant->id)->active()->count(),
            'entitlements must be expired after the subscription is deleted',
        );
        $this->assertSame(Tenant::STATUS_BLOQUEADO, Tenant::find($this->tenant->id)->status);
        $afterCycle = app(SubscriptionStateService::class)->narrativeFor($this->owner, $this->tenant->fresh());
        $this->assertTrue($afterCycle['is_blocked'], 'owner is blocked once the paid period ends');
    }

    // =========================================================================
    // 5. Payment failure → pending + grace (entitlement still live) → block later
    // =========================================================================

    public function test_past_due_marks_pending_but_keeps_grace_until_expiry(): void
    {
        $subscriptionId = 'sub_pastdue_lifecycle';

        TenantEntitlement::create([
            'tenant_id' => $this->tenant->id,
            'billable_module_id' => $this->base->id,
            'billing_package_id' => $this->plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => 'month',
            'quantity' => 10,
            'expires_at' => now()->addMonth(),
            'stripe_subscription_id' => $subscriptionId,
            'stripe_item_id' => 'si_base',
        ]);
        $this->tenant->update(['status' => Tenant::STATUS_ACTIVO]);

        $this->dispatchWebhook('customer.subscription.updated', [
            'object' => [
                'id' => $subscriptionId,
                'status' => 'past_due',
                'metadata' => ['tenant_id' => $this->tenant->id, 'plan_key' => 'esencial', 'interval' => 'month'],
                'items' => ['data' => [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, now()->addMonth()->timestamp),
                ]],
            ],
        ]);

        $this->assertSame(Tenant::STATUS_PENDIENTE_PAGO, Tenant::find($this->tenant->id)->status);
        $grace = app(SubscriptionStateService::class)->narrativeFor($this->owner, $this->tenant->fresh());
        $this->assertFalse($grace['is_blocked'], 'past_due keeps access during the grace window');
        $this->assertSame(1, TenantEntitlement::where('tenant_id', $this->tenant->id)->active()->count());

        $this->dispatchWebhook('customer.subscription.deleted', [
            'object' => ['id' => $subscriptionId, 'metadata' => ['tenant_id' => $this->tenant->id]],
        ]);

        $this->assertSame(Tenant::STATUS_BLOQUEADO, Tenant::find($this->tenant->id)->status);
        $blocked = app(SubscriptionStateService::class)->narrativeFor($this->owner, $this->tenant->fresh());
        $this->assertTrue($blocked['is_blocked']);
    }

    // =========================================================================
    // 6. Resume within grace → reactivated
    // =========================================================================

    public function test_resume_within_grace_period_reactivates_subscription(): void
    {
        $subscription = Subscription::create([
            'user_id' => $this->owner->id,
            'type' => 'default',
            'stripe_id' => 'sub_resume_lifecycle',
            'stripe_status' => 'active',
            'stripe_price' => self::BASE_PRICE_ID,
            'quantity' => 10,
            'trial_ends_at' => now()->addDays(20),
            'ends_at' => now()->addDays(20),
        ]);

        $this->fakeStripeClient();

        $response = $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/config/subscription/resume')
            ->assertStatus(200);

        $this->assertNull($subscription->fresh()->ends_at, 'resume must clear the grace-period end');
        $this->assertFalse($response->json('data.cancel_at_period_end'));
        $this->assertTrue($this->capturedSubscriptionUpdate['cancel_at_period_end'] === false);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function entitlementFor(BillableModule $module): ?TenantEntitlement
    {
        return TenantEntitlement::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('billable_module_id', $module->id)
            ->first();
    }

    private function grantStripeEntitlement(BillableModule $module, int $quantity, string $subscriptionId, string $itemId): TenantEntitlement
    {
        return TenantEntitlement::create([
            'tenant_id' => $this->tenant->id,
            'billable_module_id' => $module->id,
            'billing_package_id' => $this->plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => 'month',
            'quantity' => $quantity,
            'expires_at' => now()->addMonth(),
            'stripe_subscription_id' => $subscriptionId,
            'stripe_item_id' => $itemId,
        ]);
    }

    private function grantTrialEntitlement(BillableModule $module, ?Carbon $expiresAt): TenantEntitlement
    {
        return TenantEntitlement::create([
            'tenant_id' => $this->tenant->id,
            'billable_module_id' => $module->id,
            'billing_package_id' => $this->plan->id,
            'source' => TenantEntitlement::SOURCE_TRIAL,
            'interval' => 'month',
            'quantity' => 1,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * The owner's active Cashier subscription (multi-item: no single price/quantity).
     */
    private function seedActiveCashierSubscription(string $stripeId): Subscription
    {
        return Subscription::create([
            'user_id' => $this->owner->id,
            'type' => 'default',
            'stripe_id' => $stripeId,
            'stripe_status' => 'active',
            'stripe_price' => null,
            'quantity' => null,
        ]);
    }

    /**
     * Add YEAR matrix prices (base + add-on) so a month→year interval cascade can
     * remap every line.
     */
    private function seedYearPrices(): void
    {
        // Same pairs seedMatrix() already flagged — idempotent upsert, kept
        // here too so this helper stands alone (fixture invariant).
        PlanModule::updateOrCreate(
            ['billing_package_id' => $this->plan->id, 'billable_module_id' => $this->base->id],
            ['is_free' => false, 'trial_days' => null],
        );
        PlanModule::updateOrCreate(
            ['billing_package_id' => $this->plan->id, 'billable_module_id' => $this->addon->id],
            ['is_free' => false, 'trial_days' => null],
        );

        PlanModulePrice::create([
            'billing_package_id' => $this->plan->id,
            'billable_module_id' => $this->base->id,
            'interval' => 'year',
            'unit_amount' => 1083000,
            'currency' => 'MXN',
            'is_active' => true,
            'stripe_price_id' => 'price_base_year',
            'stripe_product_id' => 'prod_base_year',
        ]);

        PlanModulePrice::create([
            'billing_package_id' => $this->plan->id,
            'billable_module_id' => $this->addon->id,
            'interval' => 'year',
            'unit_amount' => 136800,
            'currency' => 'MXN',
            'is_active' => true,
            'stripe_price_id' => 'price_addon_year',
            'stripe_product_id' => 'prod_addon_year',
        ]);
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

    private function fakeStripeClient(): void
    {
        $subscriptions = Mockery::mock();
        $subscriptions->shouldReceive('update')
            ->andReturnUsing(function (string $id, array $params): StripeSubscription {
                $this->capturedSubscriptionUpdate = $params;

                return StripeSubscription::constructFrom(['id' => $id, 'status' => 'active']);
            });

        $client = Mockery::mock(StripeClient::class);
        $client->shouldReceive('getService')->with('subscriptions')->andReturn($subscriptions);
        $client->shouldReceive('getService')->andReturn(Mockery::mock()->shouldIgnoreMissing());

        $this->app->bind(StripeClient::class, fn () => $client);
    }

    /**
     * Seed the plan×module matrix: a plan + a base billable module (wired to a
     * system/module for projection) + a per-module add-on, both priced for `month`
     * and synced to Stripe.
     */
    private function seedMatrix(): void
    {
        $this->plan = BillingPackage::create([
            'key' => 'esencial',
            'name' => 'Esencial',
            'description' => 'Plan esencial.',
            'sort_order' => 12,
            'is_active' => true,
            'is_public' => true,
            'suggested_unit_min' => 51,
            'suggested_unit_max' => 100,
        ]);

        $this->base = BillableModule::create([
            'key' => 'base',
            'name' => 'Plan Base',
            'description' => 'Módulo base.',
            'sort_order' => 0,
            'is_active' => true,
            'is_base' => true,
        ]);

        $this->addon = BillableModule::create([
            'key' => 'package',
            'name' => 'Paquetería',
            'description' => 'Add-on con precio.',
            'sort_order' => 1,
            'is_active' => true,
            'is_base' => false,
        ]);

        PlanModule::updateOrCreate(
            ['billing_package_id' => $this->plan->id, 'billable_module_id' => $this->base->id],
            ['is_free' => false, 'trial_days' => null],
        );
        PlanModulePrice::create([
            'billing_package_id' => $this->plan->id,
            'billable_module_id' => $this->base->id,
            'interval' => 'month',
            'unit_amount' => 95000,
            'currency' => 'MXN',
            'is_active' => true,
            'stripe_price_id' => self::BASE_PRICE_ID,
            'stripe_product_id' => 'prod_base_lifecycle',
        ]);

        PlanModule::updateOrCreate(
            ['billing_package_id' => $this->plan->id, 'billable_module_id' => $this->addon->id],
            ['is_free' => false, 'trial_days' => null],
        );
        PlanModulePrice::create([
            'billing_package_id' => $this->plan->id,
            'billable_module_id' => $this->addon->id,
            'interval' => 'month',
            'unit_amount' => 12000,
            'currency' => 'MXN',
            'is_active' => true,
            'stripe_price_id' => self::ADDON_PRICE_ID,
            'stripe_product_id' => 'prod_addon_lifecycle',
        ]);
    }

    /**
     * Invoke the listener through Cashier's WebhookReceived event with a crafted
     * payload (no real Stripe call).
     *
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
}
