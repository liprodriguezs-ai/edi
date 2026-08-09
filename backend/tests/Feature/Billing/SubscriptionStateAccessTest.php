<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Http\Controllers\AuthController;
use App\Models\Role as AppRole;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;
use ReflectionMethod;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * AuthController::getSubscriptionState must read the SINGLE source of truth (an
 * active tenant_entitlement) when deciding access, not only the legacy
 * subscription_plan_id.
 *
 * The entitlement-based checkout grants PER-MODULE entitlements + flips the tenant
 * to ACTIVO but never sets subscription_plan_id. A cluster that paid through the
 * new checkout must NOT stay blocked at login just because it has no plan id.
 *
 * getSubscriptionState is invoked directly via reflection so the test isolates the
 * access decision without the full /auth/me middleware + tenancy stack.
 */
final class SubscriptionStateAccessTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Fixture debt: this class is transaction-less (central-connection
        // writes), so a Cashier subscriptions row created by one test could
        // leak into the next. Seven sibling Billing suites already clean this
        // table in setUp(); this one gains its own real Cashier rows in this
        // change and needed the same guard.
        DB::table('subscriptions')->delete();
    }

    protected function tearDown(): void
    {
        $this->cleanBillingTables();
        Carbon::setTestNow();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_tenant_with_active_entitlement_and_no_plan_id_is_not_blocked(): void
    {
        // A cluster that paid through the entitlement checkout: ACTIVE per-module
        // entitlement, NO legacy subscription_plan_id. The tenant status is left
        // non-active so the ENTITLEMENT is the sole reason it must not be blocked.
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_PENDIENTE_PAGO]);
        $user = $this->makeUser();
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $this->makePlanModulePrice($plan, $base, PlanModulePrice::INTERVAL_MONTH, 95000);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_a1',
            'expires_at' => now()->addDays(30),
        ]);

        $state = $this->subscriptionState($user, $tenant);

        $this->assertFalse($state['is_blocked'], 'a paid entitlement-checkout tenant must not be blocked at login');
    }

    public function test_session_state_flags_a_payment_issue_without_blocking_access(): void
    {
        // F-26 — during Stripe dunning (past_due) the webhook flips the cluster to
        // pendiente_pago while the entitlement is still live. Access is untouched,
        // but the session must carry a discriminator so the owner can be told
        // "tu pago falló, actualiza tu tarjeta" BEFORE the hard block.
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_PENDIENTE_PAGO]);
        $user = $this->makeUser();
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $this->makePlanModulePrice($plan, $base, PlanModulePrice::INTERVAL_MONTH, 95000);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_past_due',
            'expires_at' => now()->addDays(3),
        ]);

        $state = $this->subscriptionState($user, $tenant);

        $this->assertSame('active', $state['status'], 'is_blocked/status semantics must not change');
        $this->assertFalse($state['is_blocked']);
        $this->assertTrue($state['payment_issue']);
    }

    public function test_session_state_reports_no_payment_issue_for_a_healthy_cluster(): void
    {
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_ACTIVO]);
        $user = $this->makeUser();
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $this->makePlanModulePrice($plan, $base, PlanModulePrice::INTERVAL_MONTH, 95000);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_healthy',
            'expires_at' => now()->addDays(30),
        ]);

        $state = $this->subscriptionState($user, $tenant);

        $this->assertFalse($state['payment_issue']);
    }

    public function test_tenant_without_plan_and_without_entitlement_is_blocked(): void
    {
        // No plan, no active entitlement, not ACTIVO → genuinely blocked (control).
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_PENDIENTE_PAGO]);
        $user = $this->makeUser();

        $state = $this->subscriptionState($user, $tenant);

        $this->assertTrue($state['is_blocked']);
    }

    // =========================================================================
    // D7 — trial truth is read from Cashier's synced `stripe_status`, NEVER from
    // a local metadata mirror. A Stripe Checkout trial must report
    // status='trial' with STRIPE's OWN date (trial_ends_at on the `subscriptions`
    // row), not the entitlement's expiry — the two can legitimately differ (D2
    // fallback expiry). See StripeWebhookEntitlementTest for the end-to-end
    // webhook-driven version of this same scenario.
    // =========================================================================

    public function test_stripe_subscription_trialing_reports_trial_status_and_stripes_own_date(): void
    {
        // The Stripe trial entitlement is source=stripe (D1/D2 — SOURCE_TRIAL is
        // reserved for the LOCAL card-free paths). The entitlement's OWN expiry
        // is deliberately set to a DIFFERENT date than Stripe's trial_ends_at, so
        // a passing assertion proves the date came from Stripe, not the
        // entitlement.
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_ACTIVO]);
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $this->makePlanModulePrice($plan, $base, PlanModulePrice::INTERVAL_MONTH, 95000);

        $owner = $this->makeOwner($tenant);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_trialing',
            'expires_at' => now()->addDays(45),
        ]);

        $stripeTrialEnd = now()->addDays(30);
        Subscription::create([
            'user_id' => $owner->id,
            'type' => 'default',
            'stripe_id' => 'sub_trialing',
            'stripe_status' => 'trialing',
            'stripe_price' => 'price_trialing',
            'quantity' => 1,
            'trial_ends_at' => $stripeTrialEnd,
        ]);

        $state = $this->subscriptionState($owner, $tenant);

        $this->assertSame('trial', $state['status']);
        $this->assertFalse($state['is_blocked']);
        $this->assertSame($stripeTrialEnd->toIso8601String(), $state['trial_ends_at']);
        // Team-lead review: a Stripe trial already has a plan + card and
        // auto-charges on conversion — the UI must NOT tell this owner to
        // "contrata un plan" (SelectPlanPage's trial banner branches on this).
        $this->assertTrue($state['trial_auto_charges']);
    }

    // =========================================================================
    // trial-countdown-utility — trial_days_left is API-owned, computed from the
    // SAME source datetime as trial_ends_at (never a second store). Calendar
    // days, clamped at 0 (spec: trial-countdown-utility).
    // =========================================================================

    public function test_stripe_trial_days_left_reads_thirty_on_day_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 09:00:00'));

        $tenant = $this->makeTenant(['status' => Tenant::STATUS_ACTIVO]);
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $this->makePlanModulePrice($plan, $base, PlanModulePrice::INTERVAL_MONTH, 95000);

        $owner = $this->makeOwner($tenant);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_trial_day1',
            'expires_at' => now()->addDays(45),
        ]);

        Subscription::create([
            'user_id' => $owner->id,
            'type' => 'default',
            'stripe_id' => 'sub_trial_day1',
            'stripe_status' => 'trialing',
            'stripe_price' => 'price_trial_day1',
            'quantity' => 1,
            'trial_ends_at' => now()->addDays(30),
        ]);

        $state = $this->subscriptionState($owner, $tenant);

        $this->assertSame(30, $state['trial_days_left'], 'day one of a 30-day trial must read 30, never 31');
    }

    public function test_stripe_trial_days_left_reads_zero_on_the_expiry_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 23:00:00'));

        $tenant = $this->makeTenant(['status' => Tenant::STATUS_ACTIVO]);
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $this->makePlanModulePrice($plan, $base, PlanModulePrice::INTERVAL_MONTH, 95000);

        $owner = $this->makeOwner($tenant);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_trial_expiry_day',
            'expires_at' => now()->addDays(45),
        ]);

        Subscription::create([
            'user_id' => $owner->id,
            'type' => 'default',
            'stripe_id' => 'sub_trial_expiry_day',
            'stripe_status' => 'trialing',
            'stripe_price' => 'price_trial_expiry_day',
            'quantity' => 1,
            // Same calendar day as "now" (2026-08-07), earlier that day.
            'trial_ends_at' => Carbon::parse('2026-08-07 09:00:00'),
        ]);

        $state = $this->subscriptionState($owner, $tenant);

        $this->assertSame(0, $state['trial_days_left'], 'the expiry day itself must read 0');
    }

    public function test_local_trial_entitlement_reports_trial_auto_charges_false(): void
    {
        // A LOCAL trial (super-admin courtesy MODE_TRIAL, or a per-module
        // startModuleTrial add-on trial) has NO plan and NO card behind it —
        // the owner genuinely must contract a plan or lose access, so the
        // banner's "contrata un plan" call to action must stay for this case.
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_ACTIVO]);
        $user = $this->makeUser();
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $this->makePlanModulePrice($plan, $base, PlanModulePrice::INTERVAL_MONTH, 95000);

        $trialEnd = now()->addDays(30);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_TRIAL,
            'expires_at' => $trialEnd,
        ]);

        $state = $this->subscriptionState($user, $tenant);

        $this->assertSame('trial', $state['status']);
        $this->assertFalse($state['trial_auto_charges']);
        // A courtesy trial (no Cashier row) computes trial_days_left from its
        // OWN entitlement expiry — the same 30-day boundary applies.
        $this->assertSame(30, $state['trial_days_left']);
    }

    public function test_day31_conversion_with_perpetual_entitlement_reports_active_without_countdown(): void
    {
        // The "stuck marker" case this refactor fixes: once Stripe is no longer
        // trialing, the cluster must report 'active' regardless of the
        // entitlement's own expires_at — including NULL (perpetual).
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_ACTIVO]);
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $this->makePlanModulePrice($plan, $base, PlanModulePrice::INTERVAL_MONTH, 95000);

        $owner = $this->makeOwner($tenant);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_day31',
            'expires_at' => null,
        ]);

        Subscription::create([
            'user_id' => $owner->id,
            'type' => 'default',
            'stripe_id' => 'sub_day31',
            'stripe_status' => 'active',
            'stripe_price' => 'price_day31',
            'quantity' => 1,
        ]);

        $state = $this->subscriptionState($owner, $tenant);

        $this->assertSame('active', $state['status']);
        $this->assertArrayNotHasKey('trial_ends_at', $state);
        // trial_days_left is trial-status-only — absent outside status='trial',
        // matching trial_ends_at/trial_auto_charges's existing shape.
        $this->assertArrayNotHasKey('trial_days_left', $state);
    }

    public function test_stripe_entitlement_with_no_cashier_row_reports_active_not_trial(): void
    {
        // Owner-unresolved / no Cashier row degrades safely to 'active' — never
        // 'trial' just because the entitlement is source=stripe.
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_ACTIVO]);
        $user = $this->makeUser();
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $this->makePlanModulePrice($plan, $base, PlanModulePrice::INTERVAL_MONTH, 95000);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_post_trial',
            'expires_at' => now()->addMonth(),
        ]);

        $state = $this->subscriptionState($user, $tenant);

        $this->assertSame('active', $state['status']);
        $this->assertArrayNotHasKey('trial_ends_at', $state);
    }

    public function test_a_member_session_also_reports_trial_during_the_owners_stripe_trial(): void
    {
        // The trial-vs-active distinction is a fact about the CLUSTER's access,
        // not about who is asking — the owner's live Stripe trial must be
        // visible in a MEMBER's session too, never isOwner-gated.
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_ACTIVO]);
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $this->makePlanModulePrice($plan, $base, PlanModulePrice::INTERVAL_MONTH, 95000);

        $owner = $this->makeOwner($tenant);
        $trialEnd = now()->addDays(30);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_member_view',
            'expires_at' => now()->addDays(45),
        ]);

        Subscription::create([
            'user_id' => $owner->id,
            'type' => 'default',
            'stripe_id' => 'sub_member_view',
            'stripe_status' => 'trialing',
            'stripe_price' => 'price_member_view',
            'quantity' => 1,
            'trial_ends_at' => $trialEnd,
        ]);

        $member = $this->makeUser();

        $state = $this->subscriptionState($member, $tenant);

        $this->assertSame('trial', $state['status']);
        $this->assertFalse($state['is_owner']);
    }

    // =========================================================================
    // payment_issue — entitled branch (net-new + no-op regression coverage)
    // =========================================================================

    public function test_active_entitlement_with_cashier_past_due_flags_payment_issue(): void
    {
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_ACTIVO]);
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $this->makePlanModulePrice($plan, $base, PlanModulePrice::INTERVAL_MONTH, 95000);

        $owner = $this->makeOwner($tenant);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_pd',
            'expires_at' => now()->addDays(5),
        ]);

        Subscription::create([
            'user_id' => $owner->id,
            'type' => 'default',
            'stripe_id' => 'sub_pd',
            'stripe_status' => 'past_due',
            'stripe_price' => 'price_pd',
            'quantity' => 1,
        ]);

        $state = $this->subscriptionState($owner, $tenant);

        $this->assertSame('active', $state['status']);
        $this->assertFalse($state['is_blocked']);
        $this->assertTrue($state['payment_issue']);
    }

    public function test_entitled_with_pendiente_pago_and_cashier_still_active_keeps_payment_issue(): void
    {
        // The unconditional entitled-branch check is a STRICT NO-OP versus
        // today: a prior design revision proposed gating it on Stripe's status
        // and was explicitly reversed. Stripe's own retry dunning can leave the
        // subscription `active` for a whole retry window while
        // handleInvoicePaymentFailed already flipped the tenant to
        // pendiente_pago — the warning must not blank out during that window.
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_PENDIENTE_PAGO]);
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $this->makePlanModulePrice($plan, $base, PlanModulePrice::INTERVAL_MONTH, 95000);

        $owner = $this->makeOwner($tenant);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_noop',
            'expires_at' => now()->addDays(10),
        ]);

        Subscription::create([
            'user_id' => $owner->id,
            'type' => 'default',
            'stripe_id' => 'sub_noop',
            'stripe_status' => 'active',
            'stripe_price' => 'price_noop',
            'quantity' => 1,
        ]);

        $state = $this->subscriptionState($owner, $tenant);

        $this->assertSame('active', $state['status']);
        $this->assertTrue($state['payment_issue']);
    }

    // =========================================================================
    // D8 — blocked-branch payment_issue matrix
    // =========================================================================

    public function test_blocked_state_a_never_chose_a_plan_reports_none_without_payment_issue(): void
    {
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_PENDIENTE_PAGO]);
        $user = $this->makeUser();

        $state = $this->subscriptionState($user, $tenant);

        $this->assertSame('none', $state['status']);
        $this->assertTrue($state['is_blocked']);
        $this->assertFalse($state['payment_issue']);
    }

    public function test_blocked_state_b_cancelled_subscription_reports_expired_without_payment_issue(): void
    {
        // Cancellation flow (StripeWebhookListener::handleSubscriptionDeleted) sets
        // the tenant BLOQUEADO, not pendiente_pago — never a card problem the owner
        // can fix by updating it, just an ended subscription.
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_BLOQUEADO]);
        $user = $this->makeUser();
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $this->makePlanModulePrice($plan, $base, PlanModulePrice::INTERVAL_MONTH, 95000);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_cancelled',
            'expires_at' => now()->subDay(),
        ]);

        $state = $this->subscriptionState($user, $tenant);

        $this->assertSame('expired', $state['status']);
        $this->assertTrue($state['is_blocked']);
        $this->assertFalse($state['payment_issue']);
    }

    public function test_blocked_state_c_failed_charge_reports_expired_with_payment_issue(): void
    {
        // Day-31 hard cut (D8): the webhook leaves tenant.status pendiente_pago and
        // the entitlement simply expires at trial_end with no extension — this is
        // the ONLY blocked state the owner can fix by updating their card.
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_PENDIENTE_PAGO]);
        $user = $this->makeUser();
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $this->makePlanModulePrice($plan, $base, PlanModulePrice::INTERVAL_MONTH, 95000);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_failed_charge',
            'expires_at' => now()->subDay(),
        ]);

        $state = $this->subscriptionState($user, $tenant);

        $this->assertSame('expired', $state['status']);
        $this->assertTrue($state['is_blocked']);
        $this->assertTrue($state['payment_issue']);
    }

    public function test_blocked_state_with_cashier_canceled_and_pendiente_pago_keeps_payment_issue(): void
    {
        // Regression pin (obs #2281): a Stripe-canceled subscription (not merely
        // an absent Cashier row) whose tenant is still pendiente_pago must keep
        // the recovery-screen signal — canceled provides no live payment signal,
        // same as no row at all.
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_PENDIENTE_PAGO]);
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $this->makePlanModulePrice($plan, $base, PlanModulePrice::INTERVAL_MONTH, 95000);

        $owner = $this->makeOwner($tenant);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_canceled',
            'expires_at' => now()->subDay(),
        ]);

        Subscription::create([
            'user_id' => $owner->id,
            'type' => 'default',
            'stripe_id' => 'sub_canceled',
            'stripe_status' => 'canceled',
            'stripe_price' => 'price_canceled',
            'quantity' => 1,
            'ends_at' => now()->subDay(),
        ]);

        $state = $this->subscriptionState($owner, $tenant);

        $this->assertSame('expired', $state['status']);
        $this->assertTrue($state['is_blocked']);
        $this->assertTrue($state['payment_issue']);
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionState(User $user, Tenant $tenant): array
    {
        $method = new ReflectionMethod(AuthController::class, 'getSubscriptionState');
        $method->setAccessible(true);

        return $method->invoke(app(AuthController::class), $user, $tenant);
    }

    private function makeUser(): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'name' => 'User '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);
    }

    /**
     * A user resolvable by AccountOwnerResolver as the tenant's SINGLE account
     * owner (real `account.owner` role, attached to the tenant) — required for
     * narrativeFor() to find a Cashier subscription at all.
     */
    private function makeOwner(Tenant $tenant): User
    {
        $user = $this->makeUser();

        AppRole::firstOrCreate(['name' => Role::SLUG_ACCOUNT_OWNER, 'guard_name' => 'api']);

        $user->tenants()->attach($tenant->id);
        $user->assignRole(Role::SLUG_ACCOUNT_OWNER);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}
