<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Listeners\StripeWebhookListener;
use App\Models\Role as AppRole;
use App\Models\Subscription as CashierSubscription;
use App\Models\User;
use App\Services\SubscriptionStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Cashier\Events\WebhookReceived;
use Modules\Module\Models\Module;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\EntitlementService;
use Modules\System\Models\System;
use Modules\System\Services\ModuleTenantService;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * Coverage for the Stripe webhook → tenant_entitlement projection (billing v2,
 * per-module).
 *
 * The listener maps each subscription item's stripe_price_id to a plan_module_price
 * cell → grants one entitlement per (billable_module, plan). The listener is
 * invoked directly through Cashier's WebhookReceived event with a crafted payload
 * (no real Stripe round-trip); the subscription is provided INLINE (expanded), so
 * every test stays network-free.
 *
 * Each case asserts the FULL projection: tenant_entitlement rows +
 * system_tenant / module_tenant access tables + tenant.status.
 */
final class StripeWebhookEntitlementTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    private const BASE_PRICE_ID = 'price_base_wh';

    private const ADDON_PRICE_ID = 'price_addon_wh';

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        $this->cleanBillingTables();
        DB::table('subscriptions')->delete();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // =========================================================================
    // checkout.session.completed → grants entitlements + projects access
    // =========================================================================

    public function test_checkout_completed_grants_entitlements_and_activates_tenant(): void
    {
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_PENDIENTE_PAGO]);

        $plan = $this->makePlan('esencial');
        $system = System::factory()->create();
        $module = $this->makeModuleIn($system, 'Cobros');
        $base = $this->makeMatrixModule('base', 'Plan Base', [$module], self::BASE_PRICE_ID, true, $plan);

        $periodEnd = now()->addMonth();

        // REAL Stripe shape (F-10): Cashier's withMetadata() lands on
        // subscription_data.metadata, so the SESSION arrives with metadata: [] and
        // only the SUBSCRIPTION carries tenant_id. Fabricating session-level
        // metadata is exactly what hid the production bug from this suite.
        $this->dispatchWebhook('checkout.session.completed', [
            'object' => [
                'id' => 'cs_wh_1',
                'payment_status' => 'paid',
                'subscription' => $this->subscriptionPayload('sub_wh_1', [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, $periodEnd->timestamp),
                ], ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month']),
                'metadata' => [],
            ],
        ]);

        $entitlement = $this->entitlement($tenant, $base);

        $this->assertNotNull($entitlement);
        $this->assertSame(TenantEntitlement::SOURCE_STRIPE, $entitlement->source);
        $this->assertSame($plan->id, $entitlement->billing_package_id);
        $this->assertSame(10, $entitlement->quantity);
        $this->assertSame('sub_wh_1', $entitlement->stripe_subscription_id);
        $this->assertSame('si_base', $entitlement->stripe_item_id);
        $this->assertSame($periodEnd->timestamp, $entitlement->expires_at->timestamp);

        $this->assertTrue($this->systemAttached($tenant, $system));
        $this->assertTrue($this->moduleEnabled($tenant, $module));
        $this->assertSame(Tenant::STATUS_ACTIVO, Tenant::find($tenant->id)->status);
    }

    public function test_checkout_completed_falls_back_to_subscription_trial_end_when_item_has_no_period_end(): void
    {
        // D2 — a trialing subscription's item can carry no current_period_end
        // (no invoice has run yet). The grant must still get a NON-NULL expiry:
        // scopeActive treats null as PERPETUAL, which would grant free service
        // forever.
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_PENDIENTE_PAGO]);

        $plan = $this->makePlan('esencial');
        $system = System::factory()->create();
        $module = $this->makeModuleIn($system, 'Cobros');
        $base = $this->makeMatrixModule('base', 'Plan Base', [$module], self::BASE_PRICE_ID, true, $plan);

        $trialEnd = now()->addDays(30);

        $subscription = $this->subscriptionPayload('sub_wh_trial', [
            [
                'id' => 'si_base',
                'quantity' => 1,
                // NO current_period_end — the trialing-item shape this fallback exists for.
                'price' => ['id' => self::BASE_PRICE_ID, 'recurring' => ['interval' => 'month']],
            ],
        ], ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month']);
        $subscription['status'] = 'trialing';
        $subscription['trial_end'] = $trialEnd->timestamp;

        $this->dispatchWebhook('checkout.session.completed', [
            'object' => [
                'id' => 'cs_wh_trial',
                'payment_status' => 'no_payment_required',
                'subscription' => $subscription,
                'metadata' => [],
            ],
        ]);

        $entitlement = $this->entitlement($tenant, $base);

        $this->assertNotNull($entitlement);
        $this->assertNotNull($entitlement->expires_at, 'a trial entitlement must never carry a null (perpetual) expiry');
        $this->assertSame($trialEnd->timestamp, $entitlement->expires_at->timestamp);
    }

    public function test_checkout_completed_falls_back_to_config_trial_days_when_trial_end_is_also_missing(): void
    {
        // D2 last resort — Stripe should never omit trial_end on a trialing
        // subscription, but the grant must still never end up perpetual.
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_PENDIENTE_PAGO]);

        $plan = $this->makePlan('esencial');
        $system = System::factory()->create();
        $module = $this->makeModuleIn($system, 'Cobros');
        $base = $this->makeMatrixModule('base', 'Plan Base', [$module], self::BASE_PRICE_ID, true, $plan);

        $subscription = $this->subscriptionPayload('sub_wh_notrialend', [
            [
                'id' => 'si_base',
                'quantity' => 1,
                'price' => ['id' => self::BASE_PRICE_ID, 'recurring' => ['interval' => 'month']],
            ],
        ], ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month']);
        $subscription['status'] = 'trialing';
        // trial_end deliberately absent.

        $before = now();

        $this->dispatchWebhook('checkout.session.completed', [
            'object' => [
                'id' => 'cs_wh_notrialend',
                'payment_status' => 'no_payment_required',
                'subscription' => $subscription,
                'metadata' => [],
            ],
        ]);

        $entitlement = $this->entitlement($tenant, $base);

        $this->assertNotNull($entitlement);
        $this->assertNotNull($entitlement->expires_at);
        $this->assertTrue($entitlement->expires_at->greaterThan($before->copy()->addDays(29)));
        $this->assertTrue($entitlement->expires_at->lessThan($before->copy()->addDays(31)));
    }

    public function test_checkout_completed_trialing_subscription_reports_trial_then_active_after_conversion(): void
    {
        // D7 — end-to-end: a trialing Stripe checkout must report status='trial'.
        // Trial truth now comes from Cashier's synced stripe_status (read via the
        // owner's `subscriptions` row) — the grant writes NO local marker at all.
        // The day-31 auto-charge (subscription leaves 'trialing' +
        // invoice.payment_succeeded) must then report 'active' from that point on.
        $tenant = $this->makeTenant();
        $owner = $this->makeStripeOwner($tenant, 'cus_wh_trial_e2e');

        $plan = $this->makePlan('esencial');
        $system = System::factory()->create();
        $module = $this->makeModuleIn($system, 'Cobros');
        $base = $this->makeMatrixModule('base', 'Plan Base', [$module], self::BASE_PRICE_ID, true, $plan);

        $trialEnd = now()->addDays(30);

        $subscription = $this->subscriptionPayload('sub_wh_trial_e2e', [
            $this->itemPayload('si_base', self::BASE_PRICE_ID, 1, $trialEnd->timestamp),
        ], ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month']);
        $subscription['status'] = 'trialing';
        $subscription['trial_end'] = $trialEnd->timestamp;

        $this->dispatchWebhook('checkout.session.completed', [
            'object' => [
                'id' => 'cs_wh_trial_e2e',
                'payment_status' => 'no_payment_required',
                'customer' => 'cus_wh_trial_e2e',
                'subscription' => $subscription,
                'metadata' => [],
            ],
        ]);

        $entitlement = $this->entitlement($tenant, $base);
        $this->assertNotNull($entitlement);
        // The marker is NEVER written anymore — checked both before and after
        // conversion below, so this is no longer a proxy for "converted", just
        // the permanent baseline.
        $this->assertNull($entitlement->metadata);

        // Simulate what Cashier's own WebhookController keeps in sync from the
        // SAME event in production (this test dispatches the listener directly,
        // bypassing that controller — see OwnerSubscriptionLifecycleTest for the
        // same pattern).
        CashierSubscription::create([
            'user_id' => $owner->id,
            'type' => 'default',
            'stripe_id' => 'sub_wh_trial_e2e',
            'stripe_status' => 'trialing',
            'stripe_price' => self::BASE_PRICE_ID,
            'quantity' => 1,
            'trial_ends_at' => $trialEnd,
        ]);

        $state = app(SubscriptionStateService::class)->narrativeFor($owner, $tenant->fresh());
        $this->assertSame('trial', $state['status']);
        $this->assertSame($trialEnd->toIso8601String(), $state['trial_ends_at']);

        // ── Day-31 auto-charge succeeds: the trial converts ──
        $newPeriodEnd = now()->addMonth();

        CashierSubscription::where('stripe_id', 'sub_wh_trial_e2e')->update(['stripe_status' => 'active']);

        $this->dispatchWebhook('invoice.payment_succeeded', [
            'object' => [
                'id' => 'in_wh_trial_e2e',
                'subscription' => 'sub_wh_trial_e2e',
                'subscription_details' => ['metadata' => ['tenant_id' => $tenant->id]],
                'lines' => ['data' => [
                    ['period' => ['end' => $newPeriodEnd->timestamp]],
                ]],
            ],
        ]);

        $entitlement->refresh();
        $this->assertNull($entitlement->metadata, 'the marker must never be written, at any point in the flow');
        $this->assertSame($newPeriodEnd->timestamp, $entitlement->expires_at->timestamp);

        $stateAfter = app(SubscriptionStateService::class)->narrativeFor($owner, $tenant->fresh());
        $this->assertSame('active', $stateAfter['status']);
        $this->assertArrayNotHasKey('trial_ends_at', $stateAfter);
    }

    public function test_checkout_completed_resolves_the_tenant_from_the_customer_when_no_metadata_exists_anywhere(): void
    {
        // Last-resort layer (F-10): neither the session nor the subscription carries
        // tenant_id (e.g. a checkout created outside the app, or metadata stripped).
        // The customer → account owner → tenant fallback that the other handlers
        // already had must rescue the activation instead of dropping the payment.
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_PENDIENTE_PAGO]);
        $this->makeStripeOwner($tenant, 'cus_wh_fallback');

        $plan = $this->makePlan('esencial');
        $system = System::factory()->create();
        $module = $this->makeModuleIn($system, 'Cobros');
        $base = $this->makeMatrixModule('base', 'Plan Base', [$module], self::BASE_PRICE_ID, true, $plan);

        $this->dispatchWebhook('checkout.session.completed', [
            'object' => [
                'id' => 'cs_wh_fallback',
                'payment_status' => 'paid',
                'customer' => 'cus_wh_fallback',
                'subscription' => $this->subscriptionPayload('sub_wh_fallback', [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, now()->addMonth()->timestamp),
                ]),
                'metadata' => [],
            ],
        ]);

        $this->assertTrue($this->entitlementExists($tenant, $base));
        $this->assertSame(Tenant::STATUS_ACTIVO, Tenant::find($tenant->id)->status);
    }

    public function test_subscription_created_grants_entitlements_on_the_initial_purchase(): void
    {
        // Verified live (F-10): Stripe emits customer.subscription.CREATED — never
        // .updated — on the initial purchase. The handler must be wired so this
        // event alone can activate the organization if checkout.session.completed is
        // missed or delivered out of order.
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_PENDIENTE_PAGO]);

        $plan = $this->makePlan('esencial');
        $system = System::factory()->create();
        $module = $this->makeModuleIn($system, 'Cobros');
        $base = $this->makeMatrixModule('base', 'Plan Base', [$module], self::BASE_PRICE_ID, true, $plan);

        $this->dispatchWebhook('customer.subscription.created', [
            'object' => [
                'id' => 'sub_wh_created',
                'status' => 'active',
                'metadata' => ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month'],
                'items' => ['data' => [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, now()->addMonth()->timestamp),
                ]],
            ],
        ]);

        $entitlement = $this->entitlement($tenant, $base);

        $this->assertNotNull($entitlement, 'customer.subscription.created must grant the entitlement');
        $this->assertSame(TenantEntitlement::SOURCE_STRIPE, $entitlement->source);
        $this->assertSame('sub_wh_created', $entitlement->stripe_subscription_id);
        $this->assertTrue($this->moduleEnabled($tenant, $module));
        $this->assertSame(Tenant::STATUS_ACTIVO, Tenant::find($tenant->id)->status);
    }

    // =========================================================================
    // customer.subscription.updated → adds, removes, and re-quantifies add-ons
    // =========================================================================

    public function test_subscription_updated_removes_dropped_addon(): void
    {
        $tenant = $this->makeTenant();

        $plan = $this->makePlan('esencial');
        $systemBase = System::factory()->create();
        $systemAddon = System::factory()->create();
        $moduleBase = $this->makeModuleIn($systemBase, 'Cobros');
        $moduleAddon = $this->makeModuleIn($systemAddon, 'Paquetería');

        $base = $this->makeMatrixModule('base', 'Plan Base', [$moduleBase], self::BASE_PRICE_ID, true, $plan);
        $addon = $this->makeMatrixModule('package', 'Paquetería', [$moduleAddon], self::ADDON_PRICE_ID, false, $plan);

        $this->dispatchWebhook('checkout.session.completed', [
            'object' => [
                'id' => 'cs_wh_2',
                'payment_status' => 'paid',
                'subscription' => $this->subscriptionPayload('sub_wh_2', [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, now()->addMonth()->timestamp),
                    $this->itemPayload('si_addon', self::ADDON_PRICE_ID, 10, now()->addMonth()->timestamp),
                ]),
                'metadata' => ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month'],
            ],
        ]);

        $this->assertTrue($this->entitlementExists($tenant, $addon));
        $this->assertTrue($this->systemAttached($tenant, $systemAddon));

        $this->dispatchWebhook('customer.subscription.updated', [
            'object' => [
                'id' => 'sub_wh_2',
                'status' => 'active',
                'metadata' => ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month'],
                'items' => ['data' => [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, now()->addMonth()->timestamp),
                ]],
            ],
        ]);

        $this->assertTrue($this->entitlementExists($tenant, $base), 'base entitlement must survive');
        $this->assertFalse($this->entitlementExists($tenant, $addon), 'dropped add-on entitlement must be deleted');
        $this->assertTrue($this->systemAttached($tenant, $systemBase));
        $this->assertFalse($this->systemAttached($tenant, $systemAddon), 'dropped add-on system must be detached');
        $this->assertSame(Tenant::STATUS_ACTIVO, Tenant::find($tenant->id)->status);
    }

    public function test_subscription_updated_changes_quantity_and_refreshes_expiry(): void
    {
        $tenant = $this->makeTenant();

        $plan = $this->makePlan('esencial');
        $system = System::factory()->create();
        $module = $this->makeModuleIn($system, 'Cobros');
        $base = $this->makeMatrixModule('base', 'Plan Base', [$module], self::BASE_PRICE_ID, true, $plan);

        $this->dispatchWebhook('checkout.session.completed', [
            'object' => [
                'id' => 'cs_wh_3',
                'payment_status' => 'paid',
                'subscription' => $this->subscriptionPayload('sub_wh_3', [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, now()->addMonth()->timestamp),
                ]),
                'metadata' => ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month'],
            ],
        ]);

        $newPeriodEnd = now()->addMonths(2);

        $this->dispatchWebhook('customer.subscription.updated', [
            'object' => [
                'id' => 'sub_wh_3',
                'status' => 'active',
                'metadata' => ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month'],
                'items' => ['data' => [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 50, $newPeriodEnd->timestamp),
                ]],
            ],
        ]);

        $entitlement = $this->entitlement($tenant, $base);

        $this->assertSame(50, $entitlement->quantity);
        $this->assertSame($newPeriodEnd->timestamp, $entitlement->expires_at->timestamp);
    }

    public function test_subscription_updated_self_heals_a_stranded_billing_package_id(): void
    {
        // Crash recovery (plan 055): Stripe already carries the NEW plan's price, but
        // a failed local re-point (PreferentialSwapService::repointEntitlements after
        // swap() committed on Stripe) left the tenant stranded on the OLD plan — both
        // tenant.billing_package_id and its entitlement. The next reconcile must
        // converge everything onto the plan Stripe is actually on.
        $tenant = $this->makeTenant();

        $oldPlan = $this->makePlan('esencial');
        $newPlan = $this->makePlan('pro');

        $system = System::factory()->create();
        $module = $this->makeModuleIn($system, 'Cobros');
        // The webhook's price maps (plan_module_price) to the NEW plan.
        $base = $this->makeMatrixModule('base', 'Plan Base', [$module], self::BASE_PRICE_ID, true, $newPlan);

        $tenant->update(['billing_package_id' => $oldPlan->id]);
        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $oldPlan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => 'month',
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_heal',
            'stripe_item_id' => 'si_base',
            'expires_at' => now()->addMonth(),
        ]);

        $this->dispatchWebhook('customer.subscription.updated', [
            'object' => [
                'id' => 'sub_heal',
                'status' => 'active',
                'metadata' => ['tenant_id' => $tenant->id, 'plan_key' => 'pro', 'interval' => 'month'],
                'items' => ['data' => [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 1, now()->addMonth()->timestamp),
                ]],
            ],
        ]);

        // Derived from plan_module_price.plan (the SAME mapping used to grant) and
        // persisted, so the stranded tenant converges to Stripe's truth.
        $this->assertSame($newPlan->id, Tenant::find($tenant->id)->billing_package_id);
        // The re-grant also re-points the entitlement onto the new plan.
        $this->assertSame($newPlan->id, $this->entitlement($tenant, $base)->billing_package_id);
    }

    // =========================================================================
    // invoice.payment_succeeded → extends expiry + reactivates after past_due
    // =========================================================================

    public function test_invoice_payment_succeeded_extends_expiry_and_reactivates(): void
    {
        $tenant = $this->makeTenant();

        $plan = $this->makePlan('esencial');
        $system = System::factory()->create();
        $module = $this->makeModuleIn($system, 'Cobros');
        $base = $this->makeMatrixModule('base', 'Plan Base', [$module], self::BASE_PRICE_ID, true, $plan);

        $this->dispatchWebhook('checkout.session.completed', [
            'object' => [
                'id' => 'cs_wh_4',
                'payment_status' => 'paid',
                'subscription' => $this->subscriptionPayload('sub_wh_4', [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, now()->addDays(2)->timestamp),
                ]),
                'metadata' => ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month'],
            ],
        ]);

        $tenant->update(['status' => Tenant::STATUS_PENDIENTE_PAGO]);

        $newPeriodEnd = now()->addMonth();

        $this->dispatchWebhook('invoice.payment_succeeded', [
            'object' => [
                'id' => 'in_wh_4',
                'subscription' => 'sub_wh_4',
                'subscription_details' => ['metadata' => ['tenant_id' => $tenant->id]],
                'lines' => ['data' => [
                    ['period' => ['end' => $newPeriodEnd->timestamp]],
                ]],
            ],
        ]);

        $entitlement = $this->entitlement($tenant, $base);

        $this->assertSame($newPeriodEnd->timestamp, $entitlement->expires_at->timestamp);
        $this->assertTrue($this->moduleEnabled($tenant, $module));
        $this->assertSame(Tenant::STATUS_ACTIVO, Tenant::find($tenant->id)->status);
    }

    public function test_invoice_payment_succeeded_advances_expiry_without_touching_metadata(): void
    {
        // Regression guard for the ORIGINAL defect: the expiry update used to be
        // coupled to clearing the `stripe_trialing` marker and wrote
        // `['expires_at' => ..., 'metadata' => null]` — wiping the ENTIRE blob,
        // not just the marker.
        //
        // The sibling assertions elsewhere in this file check `assertNull($e->metadata)`
        // on an entitlement whose metadata was never populated: those pass whether or
        // not `'metadata' => null` is reintroduced, so they cannot catch a regression.
        // This test populates metadata FIRST, which is what makes the assertion bite.
        $tenant = $this->makeTenant();

        $plan = $this->makePlan('esencial');
        $system = System::factory()->create();
        $module = $this->makeModuleIn($system, 'Cobros');
        $base = $this->makeMatrixModule('base', 'Plan Base', [$module], self::BASE_PRICE_ID, true, $plan);

        $this->dispatchWebhook('checkout.session.completed', [
            'object' => [
                'id' => 'cs_wh_meta',
                'payment_status' => 'paid',
                'subscription' => $this->subscriptionPayload('sub_wh_meta', [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 1, now()->addDays(2)->timestamp),
                ]),
                'metadata' => ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month'],
            ],
        ]);

        // Someone else's data living in the same column — a promo code, a support
        // note, anything a future feature parks here. The conversion must not eat it.
        $preserved = ['promo_code' => 'LANZAMIENTO', 'note' => 'alta asistida por soporte'];
        $entitlement = $this->entitlement($tenant, $base);
        $entitlement->update(['metadata' => $preserved]);

        $newPeriodEnd = now()->addMonth();

        $this->dispatchWebhook('invoice.payment_succeeded', [
            'object' => [
                'id' => 'in_wh_meta',
                'subscription' => 'sub_wh_meta',
                'subscription_details' => ['metadata' => ['tenant_id' => $tenant->id]],
                'lines' => ['data' => [
                    ['period' => ['end' => $newPeriodEnd->timestamp]],
                ]],
            ],
        ]);

        $entitlement->refresh();

        $this->assertSame($newPeriodEnd->timestamp, $entitlement->expires_at->timestamp);
        $this->assertSame(
            $preserved,
            $entitlement->metadata,
            'the expiry update must not write metadata at all — the original defect nulled the whole blob'
        );
    }

    // =========================================================================
    // customer.subscription.deleted → expires entitlements + blocks + revokes
    // =========================================================================

    public function test_subscription_deleted_expires_entitlements_and_blocks_tenant(): void
    {
        $tenant = $this->makeTenant();

        $plan = $this->makePlan('esencial');
        $system = System::factory()->create();
        $module = $this->makeModuleIn($system, 'Cobros');
        $base = $this->makeMatrixModule('base', 'Plan Base', [$module], self::BASE_PRICE_ID, true, $plan);

        $this->dispatchWebhook('checkout.session.completed', [
            'object' => [
                'id' => 'cs_wh_5',
                'payment_status' => 'paid',
                'subscription' => $this->subscriptionPayload('sub_wh_5', [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, now()->addMonth()->timestamp),
                ]),
                'metadata' => ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month'],
            ],
        ]);

        $this->assertTrue($this->systemAttached($tenant, $system));

        $this->dispatchWebhook('customer.subscription.deleted', [
            'object' => [
                'id' => 'sub_wh_5',
                'metadata' => ['tenant_id' => $tenant->id],
            ],
        ]);

        $this->assertSame(
            0,
            TenantEntitlement::where('tenant_id', $tenant->id)->active()->count(),
        );
        $this->assertFalse($this->systemAttached($tenant, $system));
        $this->assertFalse($this->moduleEnabled($tenant, $module));
        $this->assertSame(Tenant::STATUS_BLOQUEADO, Tenant::find($tenant->id)->status);
        $this->assertNotNull($base->fresh());
    }

    public function test_subscription_deleted_keeps_tenant_active_when_another_entitlement_remains(): void
    {
        // M2 — deleting ONE subscription must not blanket-block the organization. Here a
        // super_admin_free entitlement on a second module keeps coverage.
        $tenant = $this->makeTenant();

        $plan = $this->makePlan('esencial');
        $stripeSystem = System::factory()->create();
        $stripeModuleRow = $this->makeModuleIn($stripeSystem, 'Cobros');
        $base = $this->makeMatrixModule('base', 'Plan Base', [$stripeModuleRow], self::BASE_PRICE_ID, true, $plan);

        $this->dispatchWebhook('checkout.session.completed', [
            'object' => [
                'id' => 'cs_wh_m2',
                'payment_status' => 'paid',
                'subscription' => $this->subscriptionPayload('sub_wh_m2', [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, now()->addMonth()->timestamp),
                ]),
                'metadata' => ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month'],
            ],
        ]);

        // A second, independent active entitlement (perpetual super_admin_free).
        $freeSystem = System::factory()->create();
        $freeModuleRow = $this->makeModuleIn($freeSystem, 'Paquetería');
        $freeModule = $this->makeMatrixModule('extra', 'Extra', [$freeModuleRow], self::ADDON_PRICE_ID, false, $plan);

        $this->grantModule($tenant, $freeModule, $plan, TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, [
            'interval' => 'month',
            'expires_at' => null,
        ]);

        $this->dispatchWebhook('customer.subscription.deleted', [
            'object' => ['id' => 'sub_wh_m2', 'metadata' => ['tenant_id' => $tenant->id]],
        ]);

        $this->assertSame(Tenant::STATUS_ACTIVO, Tenant::find($tenant->id)->status);
        $this->assertFalse(
            TenantEntitlement::where('tenant_id', $tenant->id)->where('billable_module_id', $base->id)->active()->exists(),
        );
        $this->assertTrue(
            TenantEntitlement::where('tenant_id', $tenant->id)->where('billable_module_id', $freeModule->id)->active()->exists(),
        );
        $this->assertTrue($this->moduleEnabled($tenant, $freeModuleRow));
        $this->assertFalse($this->moduleEnabled($tenant, $stripeModuleRow));
    }

    // =========================================================================
    // Idempotency ordering (plan 051): the event id is marked processed only
    // AFTER the handler succeeds, so a failed handler doesn't strand a paid
    // tenant behind a cached marker that turns Stripe's retry into a no-op.
    // =========================================================================

    public function test_failed_handler_releases_claim_so_stripe_retry_reprocesses_and_heals(): void
    {
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_PENDIENTE_PAGO]);

        $plan = $this->makePlan('esencial');
        $system = System::factory()->create();
        $module = $this->makeModuleIn($system, 'Cobros');
        $base = $this->makeMatrixModule('base', 'Plan Base', [$module], self::BASE_PRICE_ID, true, $plan);

        // Simulate a transient failure DURING entitlement projection: the FIRST
        // grant throws (after the status flip has already committed), then the
        // service behaves normally so the retry can heal.
        $spy = new class(app(ModuleTenantService::class)) extends EntitlementService
        {
            public bool $failNextGrant = false;

            public function grant(Tenant $tenant, BillableModule $module, BillingPackage $plan, string $source, array $options = [], bool $recompute = true): TenantEntitlement
            {
                if ($this->failNextGrant) {
                    $this->failNextGrant = false;

                    throw new RuntimeException('Simulated transient grant failure during entitlement projection');
                }

                return parent::grant($tenant, $module, $plan, $source, $options, $recompute);
            }
        };
        $this->app->instance(EntitlementService::class, $spy);
        $spy->failNextGrant = true;

        $eventId = 'evt_fail_heal_1';
        $checkout = [
            'object' => [
                'id' => 'cs_wh_fail',
                'payment_status' => 'paid',
                'subscription' => $this->subscriptionPayload('sub_wh_fail', [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, now()->addMonth()->timestamp),
                ]),
                'metadata' => ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month'],
            ],
        ];

        // First delivery: grant throws mid-activation. The tenant is left stranded
        // (ACTIVO, zero entitlements) but the claim MUST be released.
        $this->dispatchWebhook('checkout.session.completed', $checkout, $eventId);

        $this->assertFalse(
            Cache::has('stripe:evt:'.$eventId),
            'a failed handler must release the idempotency claim so Stripe can retry',
        );
        $this->assertSame(
            0,
            TenantEntitlement::where('tenant_id', $tenant->id)->active()->count(),
            'the failed delivery leaves the tenant stranded with zero entitlements',
        );
        $this->assertSame(Tenant::STATUS_ACTIVO, Tenant::find($tenant->id)->status);

        // Second delivery of the SAME event id (Stripe's retry): must reprocess
        // (not be dropped by the dedup marker) and heal the tenant.
        $this->dispatchWebhook('checkout.session.completed', $checkout, $eventId);

        $entitlement = $this->entitlement($tenant, $base);

        $this->assertNotNull($entitlement, 'the retry must reprocess and grant the entitlement');
        $this->assertSame(TenantEntitlement::SOURCE_STRIPE, $entitlement->source);
        $this->assertTrue($this->systemAttached($tenant, $system));
        $this->assertTrue($this->moduleEnabled($tenant, $module));
        $this->assertSame(Tenant::STATUS_ACTIVO, Tenant::find($tenant->id)->status);
        $this->assertTrue(
            Cache::has('stripe:evt:'.$eventId),
            'the successful retry re-claims the event id so further duplicates are deduped',
        );
    }

    public function test_duplicate_successful_delivery_applies_effects_exactly_once(): void
    {
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_PENDIENTE_PAGO]);

        $plan = $this->makePlan('esencial');
        $system = System::factory()->create();
        $module = $this->makeModuleIn($system, 'Cobros');
        $base = $this->makeMatrixModule('base', 'Plan Base', [$module], self::BASE_PRICE_ID, true, $plan);

        // Count how many times grant() actually runs, to prove the SECOND delivery
        // of the same event id is DEDUPED (never reaches the handler), not merely
        // absorbed by the upsert's idempotency.
        $spy = new class(app(ModuleTenantService::class)) extends EntitlementService
        {
            public int $grantCalls = 0;

            public function grant(Tenant $tenant, BillableModule $module, BillingPackage $plan, string $source, array $options = [], bool $recompute = true): TenantEntitlement
            {
                $this->grantCalls++;

                return parent::grant($tenant, $module, $plan, $source, $options, $recompute);
            }
        };
        $this->app->instance(EntitlementService::class, $spy);

        $eventId = 'evt_dup_1';
        $checkout = [
            'object' => [
                'id' => 'cs_wh_dup',
                'payment_status' => 'paid',
                'subscription' => $this->subscriptionPayload('sub_wh_dup', [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, now()->addMonth()->timestamp),
                ]),
                'metadata' => ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month'],
            ],
        ];

        $this->dispatchWebhook('checkout.session.completed', $checkout, $eventId);
        $this->dispatchWebhook('checkout.session.completed', $checkout, $eventId);

        $this->assertSame(1, $spy->grantCalls, 'the duplicate delivery must be deduped, not reprocessed');
        $this->assertSame(
            1,
            TenantEntitlement::where('tenant_id', $tenant->id)->where('billable_module_id', $base->id)->count(),
            'exactly one entitlement row survives a duplicate delivery',
        );
        $this->assertSame(Tenant::STATUS_ACTIVO, Tenant::find($tenant->id)->status);
    }

    public function test_out_of_order_invoice_does_not_regress_expires_at(): void
    {
        $tenant = $this->makeTenant();

        $plan = $this->makePlan('esencial');
        $system = System::factory()->create();
        $module = $this->makeModuleIn($system, 'Cobros');
        $base = $this->makeMatrixModule('base', 'Plan Base', [$module], self::BASE_PRICE_ID, true, $plan);

        $this->dispatchWebhook('checkout.session.completed', [
            'object' => [
                'id' => 'cs_wh_ooo',
                'payment_status' => 'paid',
                'subscription' => $this->subscriptionPayload('sub_wh_ooo', [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, now()->addDays(2)->timestamp),
                ]),
                'metadata' => ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month'],
            ],
        ]);

        $newerPeriodEnd = now()->addMonths(2);
        $olderPeriodEnd = now()->addMonth();

        // Newer invoice first: expires_at moves forward to the newer period end.
        $this->dispatchWebhook('invoice.payment_succeeded', [
            'object' => [
                'id' => 'in_wh_newer',
                'subscription' => 'sub_wh_ooo',
                'subscription_details' => ['metadata' => ['tenant_id' => $tenant->id]],
                'lines' => ['data' => [
                    ['period' => ['end' => $newerPeriodEnd->timestamp]],
                ]],
            ],
        ]);

        $this->assertSame(
            $newerPeriodEnd->timestamp,
            $this->entitlement($tenant, $base)->expires_at->timestamp,
            'a newer invoice pushes the paid-through date forward',
        );

        // Older invoice arrives out of order (Stripe delivers at-least-once and may
        // re-order): the monotonic guard must NOT move expires_at backwards.
        $this->dispatchWebhook('invoice.payment_succeeded', [
            'object' => [
                'id' => 'in_wh_older',
                'subscription' => 'sub_wh_ooo',
                'subscription_details' => ['metadata' => ['tenant_id' => $tenant->id]],
                'lines' => ['data' => [
                    ['period' => ['end' => $olderPeriodEnd->timestamp]],
                ]],
            ],
        ]);

        $this->assertSame(
            $newerPeriodEnd->timestamp,
            $this->entitlement($tenant, $base)->expires_at->timestamp,
            'an out-of-order older invoice must not shorten the paid-through date',
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * A billable module wired to its system modules + a plan_module_price cell
     * carrying the given Stripe price id (so the webhook can map it).
     *
     * @param  array<int, Module>  $systemModules
     */
    private function makeMatrixModule(string $key, string $name, array $systemModules, string $stripePriceId, bool $isBase, BillingPackage $plan): BillableModule
    {
        $module = $this->makeBillableModule($key, $name, $systemModules, isBase: $isBase);

        $this->makePlanModulePrice($plan, $module, 'month', $isBase ? 45000 : 400, [
            'stripe_price_id' => $stripePriceId,
            'stripe_product_id' => 'prod_'.$stripePriceId,
        ]);

        return $module;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function subscriptionPayload(string $id, array $items, array $metadata = []): array
    {
        return [
            'id' => $id,
            'items' => ['data' => $items],
            'metadata' => $metadata,
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

    /**
     * The organization's account owner, carrying the Stripe customer id — the anchor of
     * the customer → owner → tenant fallback resolution.
     */
    private function makeStripeOwner(Tenant $tenant, string $stripeCustomerId): User
    {
        $user = User::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Owner '.Str::random(5),
            'email' => 'owner.'.Str::lower(Str::random(10)).'@example.test',
            'auth_active' => true,
        ]);

        // stripe_id is NOT in $fillable (Cashier writes it directly), so it must be
        // set outside the create payload or it is silently dropped.
        $user->forceFill(['stripe_id' => $stripeCustomerId])->save();

        AppRole::firstOrCreate(['name' => Role::SLUG_ACCOUNT_OWNER, 'guard_name' => 'api']);

        $user->tenants()->attach($tenant->id);
        $user->assignRole(Role::SLUG_ACCOUNT_OWNER);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function entitlement(Tenant $tenant, BillableModule $module): ?TenantEntitlement
    {
        return TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_module_id', $module->id)
            ->first();
    }

    private function entitlementExists(Tenant $tenant, BillableModule $module): bool
    {
        return TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_module_id', $module->id)
            ->exists();
    }

    private function systemAttached(Tenant $tenant, System $system): bool
    {
        return $tenant->systems()
            ->wherePivot('is_addon', false)
            ->where('system.id', $system->id)
            ->exists();
    }

    /**
     * Dispatch a webhook through the listener. Pass an explicit $eventId to
     * deliver the SAME event twice (idempotency / retry cases); otherwise a random
     * id makes each dispatch a distinct event.
     *
     * @param  array<string, mixed>  $data
     */
    private function dispatchWebhook(string $type, array $data, ?string $eventId = null): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        app(StripeWebhookListener::class)->handle(
            new WebhookReceived([
                'id' => $eventId ?? 'evt_'.Str::lower(Str::random(16)),
                'type' => $type,
                'data' => $data,
            ]),
        );
    }
}
