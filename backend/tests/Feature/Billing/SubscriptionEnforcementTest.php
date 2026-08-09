<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Services\SubscriptionStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Slice 1b — server-side enforcement of the organization's `is_blocked` state.
 *
 * The EnsureSubscriptionActive middleware (appended to the `api` group) turns
 * is_blocked into a real 403 boundary on organization-data endpoints, while keeping the
 * auth + pay-flow reachable so the owner can recover. Enforcement is fail-secure
 * ON in prod but OFF in the suite (phpunit.xml), so this test flips it ON.
 *
 * Central-connection writes (billing_package / tenant_entitlement) with no tenant
 * scope, so transaction wrapping is disabled and the touched central tables are
 * reset in tearDown — mirroring the sibling Billing tests.
 */
final class SubscriptionEnforcementTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private const DATA_ENDPOINT = '/api/user';

    protected function setUp(): void
    {
        parent::setUp();

        // Enforcement is disabled suite-wide; this test class exercises it ON.
        config(['billing.enforce_subscription' => true]);
    }

    protected function tearDown(): void
    {
        DB::table('tenant_entitlement')->delete();
        DB::table('plan_module_price')->delete();
        DB::table('billable_module')->delete();
        DB::table('billing_package')->delete();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_blocked_tenant_is_denied_on_a_data_endpoint(): void
    {
        [$tenant, $token] = $this->organizationWithWildcardToken();
        // No entitlement granted → getSubscriptionState reports blocked.

        $this->tenantRequest($token, $tenant)
            ->getJson(self::DATA_ENDPOINT)
            ->assertStatus(403)
            ->assertJsonPath('code', 'SUBSCRIPTION_INACTIVE');
    }

    public function test_tenant_with_active_entitlement_reaches_a_data_endpoint(): void
    {
        [$tenant, $token] = $this->organizationWithWildcardToken();
        $this->grantEntitlement($tenant, TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, null);

        $this->tenantRequest($token, $tenant)
            ->getJson(self::DATA_ENDPOINT)
            ->assertStatus(200);
    }

    /**
     * Cheap gate adds zero hot-path queries (spec: subscription-state-derivation).
     * Pre-change (SubscriptionStateService::forTenant()'s entitled branch): the
     * gate ran exactly ONE tenant_entitlement query and never touched Cashier's
     * subscriptions table — the new gate must never exceed that.
     */
    public function test_gate_query_count_on_entitled_request_never_exceeds_pre_change_and_never_touches_subscriptions(): void
    {
        [$tenant, $token] = $this->organizationWithWildcardToken();
        $this->grantEntitlement($tenant, TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, null);

        [$entitlementQueries, $subscriptionQueries] = $this->captureGateQueries(
            fn () => $this->tenantRequest($token, $tenant)->getJson(self::DATA_ENDPOINT)->assertStatus(200)
        );

        $this->assertLessThanOrEqual(1, count($entitlementQueries), 'the gate must add zero extra hot-path queries');
        $this->assertCount(0, $subscriptionQueries, 'the gate must never read the Cashier subscriptions table');
    }

    /**
     * The blocked branch runs STRICTLY FEWER queries than before: pre-change
     * (forTenant()'s blocked branch) ran the active-entitlements query PLUS an
     * everHadEntitlement exists() PLUS, conditionally, a stripe-history exists()
     * for payment_issue — two or three queries. The gate now runs exactly one.
     */
    public function test_blocked_branch_runs_strictly_fewer_queries_than_pre_change(): void
    {
        [$tenant, $token] = $this->organizationWithWildcardToken();
        // No entitlement granted → blocked.

        [$entitlementQueries, $subscriptionQueries] = $this->captureGateQueries(
            fn () => $this->tenantRequest($token, $tenant)->getJson(self::DATA_ENDPOINT)->assertStatus(403)
        );

        $this->assertLessThanOrEqual(1, count($entitlementQueries), 'the blocked branch must run strictly fewer queries than before (2-3 → 1)');
        $this->assertCount(0, $subscriptionQueries, 'the gate must never read the Cashier subscriptions table');
    }

    /**
     * Runs the given request-issuing callback with DB::listen capturing every
     * query, returning [queries touching tenant_entitlement, queries touching
     * the Cashier subscriptions table] — isolates the GATE's own footprint from
     * the request's other (auth, tenant resolution, controller) queries, which
     * this refactor does not touch.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function captureGateQueries(callable $makeRequest): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $makeRequest();

        $entitlementQueries = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'tenant_entitlement'),
        ));
        $subscriptionQueries = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'subscriptions'),
        ));

        return [$entitlementQueries, $subscriptionQueries];
    }

    public function test_trial_tenant_reaches_a_data_endpoint(): void
    {
        [$tenant, $token] = $this->organizationWithWildcardToken();
        $this->grantEntitlement($tenant, TenantEntitlement::SOURCE_TRIAL, now()->addDays(7));

        $this->tenantRequest($token, $tenant)
            ->getJson(self::DATA_ENDPOINT)
            ->assertStatus(200);
    }

    public function test_blocked_tenant_can_still_reach_the_pay_flow(): void
    {
        [$tenant, $token] = $this->organizationWithWildcardToken();
        // Blocked (no entitlement) — yet the owner MUST still be able to pay.

        $subscription = $this->tenantRequest($token, $tenant)
            ->getJson('/api/config/subscription');
        $this->assertNotSame(403, $subscription->status(), 'pay-flow config/subscription must not be blocked');
        $this->assertNotSame('SUBSCRIPTION_INACTIVE', $subscription->json('code'));

        // The public catalog (used to pick a plan) is always reachable too.
        $this->tenantRequest($token, $tenant)
            ->getJson('/api/billing/plan-catalog')
            ->assertStatus(200);

        // The tracking beacon (Facebook CAPI / GA4 MP) must ALSO stay reachable —
        // otherwise an authenticated user of a blocked organization gets 403 firing
        // InitiateCheckout/Purchase on their way to paying (the exact traffic this
        // gate must never obstruct).
        $tracking = $this->tenantRequest($token, $tenant)
            ->postJson('/api/track', [
                'event' => 'InitiateCheckout',
                'event_id' => (string) Str::uuid(),
            ]);
        $this->assertNotSame(403, $tracking->status(), 'tracking beacon must not be blocked');
        $this->assertNotSame('SUBSCRIPTION_INACTIVE', $tracking->json('code'));
    }

    public function test_kill_switch_disables_enforcement(): void
    {
        config(['billing.enforce_subscription' => false]);

        [$tenant, $token] = $this->organizationWithWildcardToken();
        // Blocked organization, but the kill-switch is off → the gate no-ops.

        $this->tenantRequest($token, $tenant)
            ->getJson(self::DATA_ENDPOINT)
            ->assertStatus(200);
    }

    public function test_super_admin_and_tenantless_users_are_never_blocked(): void
    {
        $state = app(SubscriptionStateService::class);

        // Super admin: platform role, short-circuited before any tenant lookup.
        $role = Role::firstOrNew(['uuid' => Role::SUPER_ADMIN]);
        $role->name = Role::SLUG_SUPER_ADMIN;
        $role->guard_name = 'api';
        $role->save();

        $superAdmin = $this->makeCentralUser();
        $superAdmin->assignRole(Role::SLUG_SUPER_ADMIN);
        $this->assertFalse($state->gateFor($superAdmin)->isBlocked);

        // A user with no tenant membership is not a organization user → never blocked.
        $tenantless = $this->makeCentralUser();
        $this->assertFalse($state->gateFor($tenantless)->isBlocked);
    }

    /**
     * A1 regression — enforcement must evaluate the tenant the request actually
     * operates on (X-Tenant-Id), NOT the user's last_active tenant. A user who
     * belongs to an ACTIVE organization A and a BLOCKED organization B must be denied when
     * addressing B, even though last_active points at A.
     */
    public function test_enforcement_honors_x_tenant_id_for_multi_membership_user(): void
    {
        $tenantActive = $this->makeTenant('Active Organization A');
        $tenantBlocked = $this->makeTenant('Blocked Organization B');
        $this->seedTenant($tenantActive);
        $this->seedTenant($tenantBlocked);

        // A is active; B is intentionally left without any entitlement (blocked).
        $this->grantEntitlement($tenantActive, TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, null);

        // A user who is a member of BOTH organizations, with last_active pinned to A.
        $user = $this->makeUser($tenantActive);
        $user->tenants()->attach($tenantBlocked->id);
        $user->withoutEvents(function () use ($user, $tenantActive): void {
            $user->updateQuietly(['last_active_tenant_id' => $tenantActive->id]);
        });
        $token = $user->createToken('multi', ['*'])->plainTextToken;

        // Addressing the BLOCKED organization B must be denied, despite last_active = A.
        $this->tenantRequest($token, $tenantBlocked)
            ->getJson(self::DATA_ENDPOINT)
            ->assertStatus(403)
            ->assertJsonPath('code', 'SUBSCRIPTION_INACTIVE');

        // Addressing the ACTIVE organization A still succeeds.
        $this->tenantRequest($token, $tenantActive)
            ->getJson(self::DATA_ENDPOINT)
            ->assertStatus(200);
    }

    /**
     * Super admin is exempt from organization blocking AND can operate cross-tenant:
     * even against a BLOCKED organization (via X-Tenant-Id) it reaches the data endpoint.
     */
    public function test_super_admin_operates_cross_tenant_on_a_blocked_organization(): void
    {
        $tenant = $this->makeTenant('Blocked For SuperAdmin');
        $this->seedTenant($tenant);
        // No entitlement → a regular user here would be blocked.

        // Look up by (name, guard_name) so this reuses the role a sibling test may
        // already have created in the same non-transactional process, instead of
        // inserting a duplicate that trips roles_name_guard_name_unique.
        Role::firstOrCreate(
            ['name' => Role::SLUG_SUPER_ADMIN, 'guard_name' => 'api'],
            ['uuid' => Role::SUPER_ADMIN],
        );

        $superAdmin = $this->makeCentralUser();
        $superAdmin->assignRole(Role::SLUG_SUPER_ADMIN);
        $token = $superAdmin->createToken('sa', ['*'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->getJson(self::DATA_ENDPOINT)
            ->assertStatus(200);

        // Reaching the endpoint only proves the gate did not block. It does NOT
        // prove WHY: a gate that returned 'blocked' with isBlocked=false would
        // pass every assertion above. Pin the reason too.
        //
        // Asserted on gateFor(), NOT narrativeFor(): 'exempt' is an ACTOR-aware
        // verdict and lives only in the gate. narrativeFor() has no role
        // short-circuit on purpose (TenantProvisioningController passes the
        // super-admin actor to describe a NEW organization, where reporting 'exempt'
        // would be a lie about the organization) — see the SubscriptionStateService
        // docblock.
        $gate = app(SubscriptionStateService::class)->gateFor($superAdmin, $tenant->fresh());
        $this->assertSame('exempt', $gate->status);
        $this->assertFalse($gate->isBlocked);

        // El spec dice "super admin (o lawyer)". Es el MISMO
        // hasRole([SLUG_SUPER_ADMIN, SLUG_LAWYER]), pero afirmar sólo un brazo
        // deja el otro libre de desaparecer sin que nada lo note.
        //
        // `forceFill` y no `firstOrCreate([...], ['uuid' => ...])`: `guard_name`
        // y `uuid` NO están en el `$fillable` de Role, así que la asignación
        // masiva los descarta y el INSERT muere con "null value in column
        // guard_name". El firstOrCreate de super admin de arriba sobrevive sólo
        // porque ese rol ya existe y nunca llega a insertar.
        if (Role::where('name', Role::SLUG_LAWYER)->where('guard_name', 'api')->doesntExist()) {
            (new Role)->forceFill([
                'uuid' => Role::LAWYER,
                'name' => Role::SLUG_LAWYER,
                'guard_name' => 'api',
            ])->save();
        }

        $lawyer = $this->makeCentralUser();
        $lawyer->assignRole(Role::SLUG_LAWYER);

        $lawyerGate = app(SubscriptionStateService::class)->gateFor($lawyer, $tenant->fresh());
        $this->assertSame('exempt', $lawyerGate->status);
        $this->assertFalse($lawyerGate->isBlocked);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * A fully-seeded organization + a wildcard-token user (isolates the test to the
     * subscription gate, not permission checks).
     *
     * @return array{0: Tenant, 1: string}
     */
    private function organizationWithWildcardToken(): array
    {
        $tenant = $this->makeTenant('Enforcement Organization');
        $this->seedTenant($tenant);

        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        return [$tenant, $token];
    }

    private function makeCentralUser(): User
    {
        return User::create([
            'name' => 'Central '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => bcrypt('secret1234'),
            'auth_active' => true,
        ]);
    }

    private function grantEntitlement(Tenant $tenant, string $source, mixed $expiresAt): void
    {
        // Direct row (no recompute) so the gate sees an ACTIVE entitlement without
        // rewriting the module_tenant / system_tenant projection. Billing v2: keyed
        // by billable_module + the plan it was granted under.
        $module = BillableModule::firstOrCreate(
            ['key' => 'enforcement-base'],
            ['name' => 'Plan Base', 'description' => 'Módulo base.', 'sort_order' => 0, 'is_active' => true, 'is_base' => true],
        );

        $plan = BillingPackage::firstOrCreate(
            ['key' => 'enforcement-plan'],
            [
                'name' => 'Plan Enforcement',
                'description' => 'Plan.',
                'sort_order' => 0,
                'is_active' => true,
            ],
        );

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $module->id,
            'billing_package_id' => $plan->id,
            'source' => $source,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'expires_at' => $expiresAt,
        ]);
    }
}
