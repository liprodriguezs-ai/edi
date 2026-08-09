<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\System\Models\System;
use Modules\Tenant\Models\Tenant;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * Super-admin entitlement administration HTTP endpoints (billing v2, per module):
 *   - POST tenant/{id}/entitlement/grant-free {module_key, plan_key?}  activate a module free, perpetual
 *   - POST tenant/{id}/entitlement/revoke {module_key}                  revoke a module's access
 *
 * Network-free (no Stripe). Central-connection writes → transaction wrapping
 * disabled and the touched central tables reset in tearDown.
 */
final class EntitlementAdminFlowTest extends TestCase
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

    // =========================================================================
    // grant-free
    // =========================================================================

    public function test_grant_free_creates_a_perpetual_super_admin_free_entitlement_and_enables_modules(): void
    {
        $tenant = $this->makeTenant();
        $super = $this->makeUser();

        $system = System::factory()->create();
        $moduleRow = $this->makeModuleIn($system, 'Cobros');
        $base = $this->makeBillableModule('base', 'Plan Base', [$moduleRow], isBase: true);
        $plan = $this->makePlan('esencial');
        $this->makePlanModulePrice($plan, $base, 'month', 95000);

        $this->request($this->superAdminToken($super))
            ->postJson("/api/tenant/{$tenant->id}/entitlement/grant-free", ['module_key' => 'base', 'plan_key' => 'esencial'])
            ->assertStatus(201)
            ->assertJsonPath('data.source', TenantEntitlement::SOURCE_SUPER_ADMIN_FREE)
            ->assertJsonPath('data.expires_at', null);

        $entitlement = TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_module_id', $base->id)
            ->firstOrFail();

        $this->assertNull($entitlement->expires_at);
        $this->assertSame(TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, $entitlement->source);
        $this->assertSame($plan->id, $entitlement->billing_package_id);

        // Projected: the base module's parent system + module are enabled.
        $this->assertTrue(
            $tenant->systems()->where('system.id', $system->id)->wherePivot('is_addon', false)->exists(),
        );
        $this->assertTrue($this->moduleEnabled($tenant, $moduleRow));
    }

    public function test_grant_free_rejects_an_unknown_module(): void
    {
        $tenant = $this->makeTenant();
        $super = $this->makeUser();

        $this->request($this->superAdminToken($super))
            ->postJson("/api/tenant/{$tenant->id}/entitlement/grant-free", ['module_key' => 'does_not_exist'])
            ->assertStatus(422);
    }

    public function test_grant_free_is_forbidden_for_non_super_admin(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser();
        $this->makeBillableModule('base', 'Plan Base', [], isBase: true);

        $token = $user->createToken('limited', ['read.tenant'])->plainTextToken;

        $this->request($token)
            ->postJson("/api/tenant/{$tenant->id}/entitlement/grant-free", ['module_key' => 'base'])
            ->assertStatus(403);
    }

    public function test_grant_free_conflicts_with_a_live_stripe_entitlement(): void
    {
        // A2 — a courtesy grant must NEVER silently clobber a live Stripe row.
        // EntitlementService::grant()'s clobber guard already refuses the write and
        // returns the untouched stripe row; left unhandled the controller would
        // still answer 201 as if the free grant had happened.
        $tenant = $this->makeTenant();
        $super = $this->makeUser();

        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $plan = $this->makePlan('esencial');

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => 'month',
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_live_a2',
            'expires_at' => now()->addDays(30),
        ]);

        $this->request($this->superAdminToken($super))
            ->postJson("/api/tenant/{$tenant->id}/entitlement/grant-free", ['module_key' => 'base', 'plan_key' => 'esencial'])
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'sub_live_a2'));

        // The stripe entitlement is untouched — no clobber, no rows changed.
        $entitlement = TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_module_id', $base->id)
            ->firstOrFail();

        $this->assertSame(TenantEntitlement::SOURCE_STRIPE, $entitlement->source);
        $this->assertSame('sub_live_a2', $entitlement->stripe_subscription_id);
    }

    // =========================================================================
    // revoke
    // =========================================================================

    public function test_revoke_disables_a_non_base_addon_module(): void
    {
        $tenant = $this->makeTenant();
        $super = $this->makeUser();

        $systemBase = System::factory()->create();
        $systemAddon = System::factory()->create();
        $baseModuleRow = $this->makeModuleIn($systemBase, 'Cobros');
        $addonModuleRow = $this->makeModuleIn($systemAddon, 'Paquetería');

        $base = $this->makeBillableModule('base', 'Plan Base', [$baseModuleRow], isBase: true);
        $addon = $this->makeBillableModule('package', 'Paquetería', [$addonModuleRow]);
        $plan = $this->makePlan('esencial');
        $this->makePlanModulePrice($plan, $base, 'month', 95000);
        $this->makePlanModulePrice($plan, $addon, 'month', 30000);

        $token = $this->superAdminToken($super);

        $this->request($token)
            ->postJson("/api/tenant/{$tenant->id}/entitlement/grant-free", ['module_key' => 'base', 'plan_key' => 'esencial'])
            ->assertStatus(201);
        $this->request($token)
            ->postJson("/api/tenant/{$tenant->id}/entitlement/grant-free", ['module_key' => 'package', 'plan_key' => 'esencial'])
            ->assertStatus(201);

        $this->assertTrue($this->moduleEnabled($tenant, $addonModuleRow));

        // Revoke the add-on module: its platform module is disabled, the base survives.
        $this->request($token)
            ->postJson("/api/tenant/{$tenant->id}/entitlement/revoke", ['module_key' => 'package'])
            ->assertStatus(200);

        $this->assertFalse($this->moduleEnabled($tenant, $addonModuleRow));
        $this->assertTrue($this->moduleEnabled($tenant, $baseModuleRow));

        // The add-on entitlement is now expired (inactive).
        $revoked = TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_module_id', $addon->id)
            ->firstOrFail();
        $this->assertNotNull($revoked->expires_at);
        $this->assertTrue($revoked->expires_at->lessThanOrEqualTo(now()));

        $this->assertTrue(
            $tenant->systems()->where('system.id', $systemBase->id)->exists(),
            'base system survives',
        );
    }

    public function test_revoke_removes_a_courtesy_base_and_leaves_the_organization_without_access(): void
    {
        // F-03: with a SINGLE billable module (`base`) plus the unique index on
        // (tenant_id, billable_module_id), a organization holds at most ONE entitlement.
        // The old "never strip the last active base" guard therefore fired ALWAYS and
        // a courtesy granted by mistake could not be withdrawn by any product path.
        // Revoking must succeed and leave the organization with no active entitlement —
        // i.e. blocked, which is exactly what the operator asked for.
        $tenant = $this->makeTenant();
        $super = $this->makeUser();

        $system = System::factory()->create();
        $moduleRow = $this->makeModuleIn($system, 'Cobros');
        $base = $this->makeBillableModule('base', 'Plan Base', [$moduleRow], isBase: true);
        $plan = $this->makePlan('esencial');
        $this->makePlanModulePrice($plan, $base, 'month', 95000);

        $token = $this->superAdminToken($super);

        $this->request($token)
            ->postJson("/api/tenant/{$tenant->id}/entitlement/grant-free", ['module_key' => 'base', 'plan_key' => 'esencial'])
            ->assertStatus(201);

        $this->assertTrue($this->moduleEnabled($tenant, $moduleRow));

        $this->request($token)
            ->postJson("/api/tenant/{$tenant->id}/entitlement/revoke", ['module_key' => 'base'])
            ->assertStatus(200);

        $entitlement = TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_module_id', $base->id)
            ->firstOrFail();

        $this->assertNotNull($entitlement->expires_at);
        $this->assertTrue($entitlement->expires_at->lessThanOrEqualTo(now()));

        // recompute() must have turned the granted modules OFF, not just expired the row.
        $this->assertFalse($this->moduleEnabled($tenant, $moduleRow));
        $this->assertSame(
            0,
            TenantEntitlement::query()->where('tenant_id', $tenant->id)->active()->count(),
            'the organization ends with no active entitlement (blocked)',
        );
    }

    public function test_grant_free_accepts_the_contact_sales_plan_key_explicitly(): void
    {
        // F-05: `a_medida` is price-less on purpose, so planByKey() hides it unless
        // includeContactSales is passed. TenantProvisioningService already did; this
        // controller did not, so an explicit plan_key: "a_medida" 422'd while the
        // implicit path worked.
        $tenant = $this->makeTenant();
        $super = $this->makeUser();

        $system = System::factory()->create();
        $moduleRow = $this->makeModuleIn($system, 'Cobros');
        $this->makeBillableModule('base', 'Plan Base', [$moduleRow], isBase: true);

        $this->makePlan('a_medida', [
            'sort_order' => 15,
            'suggested_unit_min' => 301,
            'suggested_unit_max' => null,
        ]);

        $this->request($this->superAdminToken($super))
            ->postJson("/api/tenant/{$tenant->id}/entitlement/grant-free", [
                'module_key' => 'base',
                'plan_key' => 'a_medida',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.source', TenantEntitlement::SOURCE_SUPER_ADMIN_FREE);
    }

    public function test_revoke_refuses_an_active_stripe_entitlement(): void
    {
        // BM1 — revoking an active stripe entitlement only severs the access
        // projection; the row keeps source=stripe + stripe_subscription_id, so the
        // next invoice.payment_succeeded re-grants it while Stripe keeps charging.
        $tenant = $this->makeTenant();
        $super = $this->makeUser();

        $addonSystem = System::factory()->create();
        $addonModuleRow = $this->makeModuleIn($addonSystem, 'Paquetería');
        $addon = $this->makeBillableModule('package', 'Paquetería', [$addonModuleRow]);
        $plan = $this->makePlan('esencial');

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $addon->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => 'month',
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_live_bm1',
            'expires_at' => now()->addDays(30),
        ]);

        $this->request($this->superAdminToken($super))
            ->postJson("/api/tenant/{$tenant->id}/entitlement/revoke", ['module_key' => 'package'])
            ->assertStatus(422);

        // The stripe entitlement survives: still active, link intact.
        $entitlement = TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_module_id', $addon->id)
            ->firstOrFail();

        $this->assertSame('sub_live_bm1', $entitlement->stripe_subscription_id);
        $this->assertTrue($entitlement->expires_at->isFuture());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

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
