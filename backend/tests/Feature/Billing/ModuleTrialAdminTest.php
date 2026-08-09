<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\System\Models\System;
use Modules\Tenant\Models\Tenant;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * Super-admin module-trial endpoint (billing v2, Fase 6):
 *   - POST tenant/{id}/entitlement/start-trial {module_key}
 *
 * Exercises the shared EntitlementService::startModuleTrial logic (same engine the
 * owner self-serve endpoint uses) through the admin HTTP surface: the happy addon
 * trial under the tenant's current plan + every guard (offered-by-plan,
 * base-active-for-addon, one-trial-per-module). Network-free (no Stripe).
 * Central-connection writes → transaction wrapping disabled, tables reset in tearDown.
 */
final class ModuleTrialAdminTest extends TestCase
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

    public function test_start_trial_creates_a_trial_entitlement_for_an_addon_under_the_current_plan(): void
    {
        [$tenant, $plan, $base, $addon] = $this->seedTenantWithPlan();
        $this->grantActiveBase($tenant, $base, $plan);
        $super = $this->makeUser();

        $this->request($this->superAdminToken($super))
            ->postJson("/api/tenant/{$tenant->id}/entitlement/start-trial", ['module_key' => 'package'])
            ->assertStatus(201)
            ->assertJsonPath('data.source', TenantEntitlement::SOURCE_TRIAL)
            ->assertJsonPath('data.billable_module.key', 'package');

        $trial = TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_module_id', $addon->id)
            ->firstOrFail();

        $this->assertSame(TenantEntitlement::SOURCE_TRIAL, $trial->source);
        $this->assertSame($plan->id, $trial->billing_package_id);
        // The package price carries trial_days=7 (per-plan×module override of the
        // config default of 14).
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $trial->expires_at->timestamp, 5);
    }

    public function test_start_trial_rejects_an_addon_without_an_active_base(): void
    {
        [$tenant] = $this->seedTenantWithPlan();
        $super = $this->makeUser();

        $this->request($this->superAdminToken($super))
            ->postJson("/api/tenant/{$tenant->id}/entitlement/start-trial", ['module_key' => 'package'])
            ->assertStatus(422);
    }

    public function test_start_trial_rejects_a_second_trial_of_the_same_module(): void
    {
        [$tenant, $plan, $base, $addon] = $this->seedTenantWithPlan();
        $this->grantActiveBase($tenant, $base, $plan);
        $super = $this->makeUser();

        // A prior EXPIRED trial exists — expired trials are never deleted, and they
        // still bar a second trial of the same module.
        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $addon->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_TRIAL,
            'interval' => 'month',
            'quantity' => 1,
            'expires_at' => now()->subDay(),
        ]);

        $this->request($this->superAdminToken($super))
            ->postJson("/api/tenant/{$tenant->id}/entitlement/start-trial", ['module_key' => 'package'])
            ->assertStatus(422);
    }

    public function test_start_trial_rejects_a_module_not_offered_by_the_plan(): void
    {
        [$tenant, $plan, $base] = $this->seedTenantWithPlan();
        $this->grantActiveBase($tenant, $base, $plan);
        $super = $this->makeUser();

        // 'amenities' is an active billable module but has NO price row in the plan.
        BillableModule::create([
            'key' => 'amenities',
            'name' => 'Amenidades',
            'description' => 'Amenidades',
            'sort_order' => 2,
            'is_active' => true,
            'is_base' => false,
        ]);

        $this->request($this->superAdminToken($super))
            ->postJson("/api/tenant/{$tenant->id}/entitlement/start-trial", ['module_key' => 'amenities'])
            ->assertStatus(422);
    }

    public function test_start_trial_is_forbidden_for_a_non_super_admin(): void
    {
        [$tenant] = $this->seedTenantWithPlan();
        $user = $this->makeUser();
        $token = $user->createToken('limited', ['read.tenant'])->plainTextToken;

        $this->request($token)
            ->postJson("/api/tenant/{$tenant->id}/entitlement/start-trial", ['module_key' => 'package'])
            ->assertStatus(403);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * A tenant on plan 'esencial' (billing_package_id set) + a base billable module
     * + an add-on 'package' priced in the plan (package carries trial_days=7).
     *
     * @return array{0: Tenant, 1: BillingPackage, 2: BillableModule, 3: BillableModule}
     */
    private function seedTenantWithPlan(): array
    {
        $system = System::factory()->create();
        $baseModuleRow = $this->makeModuleIn($system, 'Cobros');
        $addonModuleRow = $this->makeModuleIn($system, 'Paquetería');

        $base = $this->makeBillableModule('base', 'Plan Base', [$baseModuleRow], isBase: true);
        $addon = $this->makeBillableModule('package', 'Paquetería', [$addonModuleRow], sortOrder: 1);

        $plan = $this->makePlan('esencial');
        $this->makePlanModulePrice($plan, $base, 'month', 95000);
        $this->makePlanModulePrice($plan, $addon, 'month', 12000, ['trial_days' => 7]);

        $tenant = $this->makeTenant(['billing_package_id' => $plan->id]);

        return [$tenant, $plan, $base, $addon];
    }

    private function grantActiveBase(Tenant $tenant, BillableModule $base, BillingPackage $plan): TenantEntitlement
    {
        return TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_SUPER_ADMIN_FREE,
            'interval' => 'month',
            'quantity' => 1,
            'expires_at' => null,
        ]);
    }

    private function makeUser(): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Super '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);
    }

    private function superAdminToken(User $user): string
    {
        return $user->createToken('super', ['*'])->plainTextToken;
    }

    private function request(string $token): self
    {
        $this->app['auth']->forgetGuards();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        return $this->withToken($token);
    }
}
