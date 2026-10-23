<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Role as AppRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * Onboarding flow #2 (integrated) — super-admin ALTA of a organization WITH its billing
 * provisioned in ONE step via POST /api/tenant/provision. Billing v2: grants are
 * PER billable_module under a PLAN (billing_package: basico..premium); the body
 * carries `modules` (with 'base') and an optional `plan_key` (resolved from units
 * when absent). Sets tenant.billing_package_id.
 *
 * Network-free, central-connection writes only (no tenant scope), so transaction
 * wrapping is disabled and the touched central tables are reset in tearDown. The
 * TenantCreated listener (tenants:seed) is faked — it seeds ZERO billing rows.
 */
final class AdminTenantProvisionTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        // A fresh organization's tenants:seed seeds ZERO billing rows; suppress it for
        // speed and isolation in every provision test.
        Event::fake([TenantCreated::class]);
    }

    protected function tearDown(): void
    {
        $this->cleanBillingTables();
        DB::table('user_tenant')->delete();
        DB::table('tenant')->delete();
        DB::table('user')->delete();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // =========================================================================
    // trial
    // =========================================================================

    public function test_provision_trial_creates_trial_entitlement_and_reports_trial_state(): void
    {
        $superToken = $this->superAdminToken();
        [$plan, $base] = $this->seedPlanMatrix();

        $response = $this->authed($superToken)
            ->postJson('/api/tenant/provision', [
                'name' => 'Organization Trial '.Str::random(5),
                'payment_method' => Tenant::PAYMENT_METHOD_CARD,
                'provision_mode' => 'trial',
            ])
            ->assertStatus(201);

        $tenantId = $response->json('data.tenant.id');
        $this->assertNotEmpty($tenantId);

        $tenant = Tenant::find($tenantId);
        $this->assertSame(Tenant::PAYMENT_METHOD_CARD, $tenant->payment_method);
        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->status);
        // New organizations NEVER rely on the legacy plan column, but DO record their plan.
        $this->assertNull($tenant->subscription_plan_id);
        $this->assertSame($plan->id, $tenant->billing_package_id);

        $entitlement = TenantEntitlement::where('tenant_id', $tenantId)->sole();
        $this->assertSame($base->id, $entitlement->billable_module_id);
        $this->assertSame($plan->id, $entitlement->billing_package_id);
        $this->assertSame(TenantEntitlement::SOURCE_TRIAL, $entitlement->source);
        $this->assertNotNull($entitlement->expires_at);
        $this->assertEqualsWithDelta(now()->addDays((int) config('billing.trial_days'))->timestamp, $entitlement->expires_at->timestamp, 5);

        // A live trial is NOT blocked.
        $response->assertJsonPath('data.subscription.status', 'trial')
            ->assertJsonPath('data.subscription.is_blocked', false)
            ->assertJsonPath('data.entitlements_created', 1);
    }

    // =========================================================================
    // courtesy
    // =========================================================================

    public function test_provision_courtesy_grants_free_perpetual_and_reports_active(): void
    {
        $superToken = $this->superAdminToken();
        [$plan, $base] = $this->seedPlanMatrix();

        $response = $this->authed($superToken)
            ->postJson('/api/tenant/provision', [
                'name' => 'Organization Cortesia '.Str::random(5),
                'payment_method' => Tenant::PAYMENT_METHOD_TRANSFER,
                'provision_mode' => 'courtesy',
                'modules' => ['base'],
            ])
            ->assertStatus(201);

        $tenantId = $response->json('data.tenant.id');

        $entitlement = TenantEntitlement::where('tenant_id', $tenantId)->sole();
        $this->assertSame($base->id, $entitlement->billable_module_id);
        $this->assertSame($plan->id, $entitlement->billing_package_id);
        $this->assertSame(TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, $entitlement->source);
        // Courtesy is perpetual — never expires.
        $this->assertNull($entitlement->expires_at);
        $this->assertNull(Tenant::find($tenantId)->subscription_plan_id);

        $response->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.is_blocked', false);
    }

    // =========================================================================
    // manual_paid
    // =========================================================================

    public function test_provision_manual_paid_records_ledger_and_extends_one_cycle(): void
    {
        $superToken = $this->superAdminToken();
        [$plan, $base] = $this->seedPlanMatrix();

        $response = $this->authed($superToken)
            ->postJson('/api/tenant/provision', [
                'name' => 'Organization Manual '.Str::random(5),
                'payment_method' => Tenant::PAYMENT_METHOD_TRANSFER,
                'provision_mode' => 'manual_paid',
                'units' => 20,
                'interval' => PlanModulePrice::INTERVAL_MONTH,
                'modules' => ['base'],
                'notes' => 'Transferencia SPEI 0001',
            ])
            ->assertStatus(201);

        $tenantId = $response->json('data.tenant.id');
        $this->assertSame(Tenant::PAYMENT_METHOD_TRANSFER, Tenant::find($tenantId)->payment_method);

        $entitlement = TenantEntitlement::where('tenant_id', $tenantId)->sole();
        $this->assertSame($base->id, $entitlement->billable_module_id);
        $this->assertSame($plan->id, $entitlement->billing_package_id);
        $this->assertSame(TenantEntitlement::SOURCE_MANUAL, $entitlement->source);
        $this->assertSame(PlanModulePrice::INTERVAL_MONTH, $entitlement->interval);
        $this->assertEqualsWithDelta(now()->addMonth()->timestamp, $entitlement->expires_at->timestamp, 5);

        // Server-authored amount: the plan×module base price (45000), never a client value.
        $this->assertDatabaseHas('subscription_payment', [
            'tenant_id' => $tenantId,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'amount' => 45000,
            'method' => Tenant::PAYMENT_METHOD_TRANSFER,
        ]);

        $response->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.is_blocked', false);
    }

    // =========================================================================
    // card
    // =========================================================================

    public function test_provision_card_leaves_no_entitlements_and_reports_blocked(): void
    {
        $superToken = $this->superAdminToken();
        [$plan] = $this->seedPlanMatrix();

        $response = $this->authed($superToken)
            ->postJson('/api/tenant/provision', [
                'name' => 'Organization Card '.Str::random(5),
                'payment_method' => Tenant::PAYMENT_METHOD_CARD,
                'provision_mode' => 'card',
            ])
            ->assertStatus(201);

        $tenantId = $response->json('data.tenant.id');
        $tenant = Tenant::find($tenantId);

        $this->assertSame(Tenant::PAYMENT_METHOD_CARD, $tenant->payment_method);
        $this->assertSame(Tenant::STATUS_PENDIENTE_PAGO, $tenant->status);
        $this->assertNull($tenant->subscription_plan_id);
        $this->assertSame($plan->id, $tenant->billing_package_id);
        $this->assertSame(0, TenantEntitlement::where('tenant_id', $tenantId)->count());

        // No entitlement → the owner must self-checkout; the organization is blocked.
        $response->assertJsonPath('data.subscription.is_blocked', true)
            ->assertJsonPath('data.entitlements_created', 0);
    }

    // =========================================================================
    // contact-sales plan (price-less)
    // =========================================================================

    public function test_provision_courtesy_of_a_contact_sales_plan_is_allowed(): void
    {
        $superToken = $this->superAdminToken();
        [, $base] = $this->seedPlanMatrix();
        $aMedida = $this->seedContactSalesPlan();

        $response = $this->authed($superToken)
            ->postJson('/api/tenant/provision', [
                'name' => 'Organization Grande '.Str::random(5),
                'payment_method' => Tenant::PAYMENT_METHOD_TRANSFER,
                'provision_mode' => 'courtesy',
                'plan_key' => 'a_medida',
            ])
            ->assertStatus(201);

        $tenantId = $response->json('data.tenant.id');

        // Courtesy charges nothing, so a price-less plan is legitimate.
        $this->assertSame($aMedida->id, Tenant::find($tenantId)->billing_package_id);

        // No `modules` sent → the whole plan is granted; the base must be in it.
        $entitlement = TenantEntitlement::where('tenant_id', $tenantId)
            ->where('billable_module_id', $base->id)
            ->sole();
        $this->assertSame(TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, $entitlement->source);
    }

    public function test_provision_of_a_contact_sales_plan_on_a_charging_mode_is_rejected(): void
    {
        $superToken = $this->superAdminToken();
        $this->seedPlanMatrix();
        $this->seedContactSalesPlan();

        foreach ([
            ['provision_mode' => 'card', 'payment_method' => Tenant::PAYMENT_METHOD_CARD],
            ['provision_mode' => 'manual_paid', 'payment_method' => Tenant::PAYMENT_METHOD_TRANSFER, 'interval' => PlanModulePrice::INTERVAL_MONTH],
        ] as $payload) {
            $this->authed($superToken)
                ->postJson('/api/tenant/provision', array_merge([
                    'name' => 'Organization Cobro '.Str::random(5),
                    'plan_key' => 'a_medida',
                ], $payload))
                ->assertStatus(422)
                ->assertJsonPath('code', 'contact_sales');
        }

        $this->assertSame(0, Tenant::query()->count(), 'no orphan organization is created on a rejected provision');
    }

    // =========================================================================
    // validation / authz guards
    // =========================================================================

    public function test_courtesy_without_a_modules_array_grants_the_whole_plan(): void
    {
        $superToken = $this->superAdminToken();
        [$plan, $base, $package] = $this->seedPlanMatrix();

        $response = $this->authed($superToken)
            ->postJson('/api/tenant/provision', [
                'name' => 'Organization Default '.Str::random(5),
                'payment_method' => Tenant::PAYMENT_METHOD_TRANSFER,
                'provision_mode' => 'courtesy',
            ])
            ->assertStatus(201);

        $tenantId = $response->json('data.tenant.id');

        $moduleIds = TenantEntitlement::where('tenant_id', $tenantId)->pluck('billable_module_id')->all();
        $this->assertEqualsCanonicalizing([$base->id, $package->id], $moduleIds);
        $this->assertSame($plan->id, Tenant::find($tenantId)->billing_package_id);
    }

    public function test_manual_paid_requires_a_non_card_payment_method(): void
    {
        $superToken = $this->superAdminToken();
        $this->seedPlanMatrix();

        $this->authed($superToken)
            ->postJson('/api/tenant/provision', [
                'name' => 'Organization Bad '.Str::random(5),
                'payment_method' => Tenant::PAYMENT_METHOD_CARD,
                'provision_mode' => 'manual_paid',
                'units' => 20,
                'interval' => PlanModulePrice::INTERVAL_MONTH,
                'modules' => ['base'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Tenant::query()->count(), 'no orphan organization is created on a rejected provision');
    }

    public function test_courtesy_without_base_module_is_rejected(): void
    {
        $superToken = $this->superAdminToken();
        // Seed base + package modules + plan, but request ONLY the non-base module.
        $this->seedPlanMatrix();

        $this->authed($superToken)
            ->postJson('/api/tenant/provision', [
                'name' => 'Organization NoBase '.Str::random(5),
                'payment_method' => Tenant::PAYMENT_METHOD_TRANSFER,
                'provision_mode' => 'courtesy',
                'modules' => ['package'],
            ])
            ->assertStatus(422);

        $this->assertSame(0, Tenant::query()->count());
    }

    /**
     * Regression guard for the actor-vs-tenant-scoped narrative split
     * (SubscriptionStateService::narrativeFor has NO role short-circuit).
     * superAdminToken() below authorizes by TOKEN ABILITY only and never holds
     * the real SLUG_SUPER_ADMIN role, so it could never catch a regression
     * where narrativeFor() applied the actor's role and reported the brand-new
     * organization 'exempt' instead of its OWN (genuinely blocked) entitlement state.
     */
    public function test_provision_narrative_reflects_the_new_organizations_own_state_even_with_a_real_super_admin_actor(): void
    {
        $realSuperAdmin = User::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Real Super '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);
        AppRole::firstOrCreate(['name' => Role::SLUG_SUPER_ADMIN, 'guard_name' => 'api']);
        $realSuperAdmin->assignRole(Role::SLUG_SUPER_ADMIN);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $token = $realSuperAdmin->createToken('real-super', ['*'])->plainTextToken;

        $this->seedPlanMatrix();

        $response = $this->authed($token)
            ->postJson('/api/tenant/provision', [
                'name' => 'Organization RealSuper '.Str::random(5),
                'payment_method' => Tenant::PAYMENT_METHOD_CARD,
                'provision_mode' => 'card',
            ])
            ->assertStatus(201);

        // 'card' mode grants ZERO entitlements — the organization is genuinely
        // blocked. A regression that short-circuited on the actor's role would
        // report 'exempt' / is_blocked=false here instead.
        $response->assertJsonPath('data.subscription.status', 'none')
            ->assertJsonPath('data.subscription.is_blocked', true);
    }

    public function test_provision_requires_super_admin_ability(): void
    {
        $user = User::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Plain '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);
        // A token WITHOUT create.tenant / wildcard.
        $token = $user->createToken('plain', ['read.tenant'])->plainTextToken;

        $this->authed($token)
            ->postJson('/api/tenant/provision', [
                'name' => 'Organization Denied '.Str::random(5),
                'payment_method' => Tenant::PAYMENT_METHOD_CARD,
                'provision_mode' => 'card',
            ])
            ->assertStatus(403);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Seed a single plan (basico, 1–25 units) + base & package billable modules +
     * their month/year prices. Returns [plan, baseModule, packageModule].
     *
     * @return array{0: BillingPackage, 1: BillableModule, 2: BillableModule}
     */
    private function seedPlanMatrix(): array
    {
        $plan = $this->makePlan('basico', ['suggested_unit_min' => 1, 'suggested_unit_max' => 25]);
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $package = $this->makeBillableModule('package', 'Paquetería');

        $this->makePlanModulePrice($plan, $base, 'month', 45000);
        $this->makePlanModulePrice($plan, $base, 'year', (int) round(45000 * 12 * 0.95));
        $this->makePlanModulePrice($plan, $package, 'month', 8000);
        $this->makePlanModulePrice($plan, $package, 'year', (int) round(8000 * 12 * 0.95));

        return [$plan, $base, $package];
    }

    /**
     * A PUBLIC, ACTIVE plan with NO price row — the consultative tier.
     */
    private function seedContactSalesPlan(): BillingPackage
    {
        return $this->makePlan('a_medida', [
            'sort_order' => 15,
            'suggested_unit_min' => 301,
            'suggested_unit_max' => null,
        ]);
    }

    private function superAdminToken(): string
    {
        $user = User::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Super '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        return $user->createToken('super', ['*'])->plainTextToken;
    }

    private function authed(string $token): static
    {
        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->withToken($token);

        return $this;
    }
}
