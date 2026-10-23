<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Role as AppRole;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * The NAMED enforcement mechanism for the composition invariant documented on
 * SubscriptionStateService: `final readonly SubscriptionGate` prevents
 * mutating the gate, but narrativeFor() returns a PLAIN ARRAY whose
 * 'is_blocked' entry a future edit could replace with an independent
 * re-derivation — nothing in the type system stops that. This test is the
 * guardrail: across every state the service can report, narrativeFor()'s
 * `is_blocked` must be EXACTLY the value the private tenantGate() computed,
 * invoked here via reflection (mirrors SubscriptionStateAccessTest's use of
 * reflection to reach a private collaborator method).
 */
final class SubscriptionStateCompositionTest extends TestCase
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

    public function test_none_state_agrees(): void
    {
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_PENDIENTE_PAGO]);
        $user = $this->makeUser();
        // No entitlement ever created.

        $this->assertComposition($user, $tenant);
    }

    public function test_expired_state_agrees(): void
    {
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
            'stripe_subscription_id' => 'sub_composition_expired',
            'expires_at' => now()->subDay(),
        ]);

        $this->assertComposition($user, $tenant);
    }

    public function test_active_state_agrees(): void
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
            'stripe_subscription_id' => 'sub_composition_active',
            'expires_at' => now()->addDays(30),
        ]);

        $this->assertComposition($user, $tenant);
    }

    public function test_courtesy_trial_state_agrees(): void
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
            'source' => TenantEntitlement::SOURCE_TRIAL,
            'expires_at' => now()->addDays(14),
        ]);

        $this->assertComposition($user, $tenant);
    }

    public function test_stripe_trial_state_agrees(): void
    {
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_ACTIVO]);
        $owner = $this->makeOwner($tenant);
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
            'stripe_subscription_id' => 'sub_composition_trial',
            'expires_at' => now()->addDays(30),
        ]);

        Subscription::create([
            'user_id' => $owner->id,
            'type' => 'default',
            'stripe_id' => 'sub_composition_trial',
            'stripe_status' => 'trialing',
            'stripe_price' => 'price_composition',
            'quantity' => 1,
            'trial_ends_at' => now()->addDays(30),
        ]);

        $this->assertComposition($owner, $tenant);
    }

    /**
     * trial-usage-quotas (REQ-QE-02): quotaScopeFor() must agree with
     * narrativeFor()'s 'trial'/'active' status for every branch the trial
     * predicate can take — a Stripe trial, a local courtesy trial, and a
     * paid/active tenant — since both read the SAME shared isTrialScope()
     * predicate.
     */
    public function test_quota_scope_for_agrees_with_narrative_stripe_trial(): void
    {
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_ACTIVO]);
        $owner = $this->makeOwner($tenant);
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
            'stripe_subscription_id' => 'sub_quota_scope_trial',
            'expires_at' => now()->addDays(30),
        ]);

        Subscription::create([
            'user_id' => $owner->id,
            'type' => 'default',
            'stripe_id' => 'sub_quota_scope_trial',
            'stripe_status' => 'trialing',
            'stripe_price' => 'price_quota_scope',
            'quantity' => 1,
            'trial_ends_at' => now()->addDays(30),
        ]);

        $this->assertScopeAgreesWithNarrative($owner, $tenant, 'trial');
    }

    public function test_quota_scope_for_agrees_with_narrative_local_trial(): void
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
            'source' => TenantEntitlement::SOURCE_TRIAL,
            'expires_at' => now()->addDays(14),
        ]);

        $this->assertScopeAgreesWithNarrative($user, $tenant, 'trial');
    }

    public function test_quota_scope_for_agrees_with_narrative_paid_active(): void
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
            'stripe_subscription_id' => 'sub_quota_scope_active',
            'expires_at' => now()->addDays(30),
        ]);

        $this->assertScopeAgreesWithNarrative($user, $tenant, 'active');
    }

    /**
     * Asserts quotaScopeFor() returns $expectedScope AND agrees with
     * narrativeFor()'s 'status' for the same (user, tenant) pair.
     */
    private function assertScopeAgreesWithNarrative(User $user, Tenant $tenant, string $expectedScope): void
    {
        $service = app(SubscriptionStateService::class);

        $scope = $service->quotaScopeFor($tenant);
        $narrative = $service->narrativeFor($user, $tenant);

        $this->assertSame($expectedScope, $scope);
        $this->assertSame($narrative['status'], $scope, 'quotaScopeFor() must agree with narrativeFor() status');
    }

    /**
     * Assert narrativeFor()'s `is_blocked` matches the private tenantGate()'s
     * `isBlocked` for the exact same (user, tenant) pair.
     */
    private function assertComposition(User $user, Tenant $tenant): void
    {
        $service = app(SubscriptionStateService::class);

        $method = new ReflectionMethod(SubscriptionStateService::class, 'tenantGate');
        $method->setAccessible(true);
        $gate = $method->invoke($service, $user, $tenant);

        $narrative = $service->narrativeFor($user, $tenant);

        $this->assertSame(
            $gate->isBlocked,
            $narrative['is_blocked'],
            'narrativeFor() is_blocked must be EXACTLY tenantGate()->isBlocked, never an independent re-derivation',
        );
    }

    private function makeUser(): User
    {
        return User::create([
            'id' => (string) Str::uuid7(),
            'name' => 'User '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'auth_active' => true,
        ]);
    }

    /**
     * A user resolvable by AccountOwnerResolver as the tenant's SINGLE account
     * owner (real `account.owner` role, attached to the tenant).
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
