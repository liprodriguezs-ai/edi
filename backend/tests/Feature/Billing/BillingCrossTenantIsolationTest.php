<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Module\Models\Module;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\SubscriptionPayment;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\EntitlementService;
use Modules\SubscriptionPlan\Services\SubscriptionPaymentService;
use Modules\System\Models\System;
use Modules\Tenant\Models\Tenant;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * CROSS-TENANT ISOLATION for the billing engine. Every billing write targets an
 * EXPLICIT tenant — recompute(), recordManual(), and the expiry cron must NEVER
 * bleed into a sibling cluster that shares the same central billing catalog.
 *
 * Two tenants A and B share ONE plan + base module (the central catalog is
 * global); their entitlements, ledger rows, access projection and status are
 * per-tenant. Each test mutates A and asserts B is untouched.
 */
final class BillingCrossTenantIsolationTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        $this->travelBack();
        $this->cleanBillingTables();

        parent::tearDown();
    }

    public function test_record_manual_payment_for_one_tenant_does_not_touch_a_sibling(): void
    {
        [, $base, $plan] = $this->seedCatalog();

        $tenantA = $this->makeSiblingTenant();
        $tenantB = $this->makeSiblingTenant();
        $admin = $this->makeSuperAdmin();

        // B starts with an active courtesy entitlement (untouched baseline).
        app(EntitlementService::class)->grant($tenantB, $base, $plan, TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, [
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'expires_at' => now()->addDays(30),
        ]);
        $bExpiryBefore = $this->entitlement($tenantB, $base)->expires_at->timestamp;

        // Record a manual payment for A.
        app(SubscriptionPaymentService::class)->recordManual(
            $tenantA,
            [['module' => $base, 'plan' => $plan, 'amount' => 150000, 'interval' => PlanModulePrice::INTERVAL_MONTH]],
            SubscriptionPayment::METHOD_TRANSFER,
            now(),
            'pago A',
            $admin,
        );

        // A got its ledger row + entitlement.
        $this->assertSame(1, SubscriptionPayment::where('tenant_id', $tenantA->id)->count());
        $this->assertTrue($this->entitlementExists($tenantA, $base));

        // B is completely untouched.
        $this->assertSame(0, SubscriptionPayment::where('tenant_id', $tenantB->id)->count());
        $this->assertSame($bExpiryBefore, $this->entitlement($tenantB, $base)->expires_at->timestamp);
        $this->assertSame(Tenant::STATUS_ACTIVO, $tenantB->fresh()->status);
    }

    public function test_recompute_expiring_one_tenant_leaves_the_other_projection_intact(): void
    {
        [$module, $base, $plan, $system] = $this->seedCatalog();

        $tenantA = $this->makeSiblingTenant();
        $tenantB = $this->makeSiblingTenant();

        $service = app(EntitlementService::class);
        $service->grant($tenantA, $base, $plan, TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, ['expires_at' => now()->addDays(30)]);
        $service->grant($tenantB, $base, $plan, TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, ['expires_at' => now()->addDays(30)]);

        $this->assertTrue($this->systemAttached($tenantA, $system));
        $this->assertTrue($this->systemAttached($tenantB, $system));

        // Expire ONLY A's entitlement and recompute ONLY A.
        TenantEntitlement::query()
            ->where('tenant_id', $tenantA->id)
            ->update(['expires_at' => now()->subDay()]);
        $service->recompute($tenantA);

        // A loses access...
        $this->assertFalse($this->systemAttached($tenantA, $system));
        $this->assertFalse($this->moduleEnabled($tenantA, $module));

        // ...B keeps it (no leakage).
        $this->assertTrue($this->systemAttached($tenantB, $system));
        $this->assertTrue($this->moduleEnabled($tenantB, $module));
    }

    public function test_expiry_cron_blocks_only_the_lapsed_tenant_not_a_sibling(): void
    {
        [$module, $base, $plan] = $this->seedCatalog();

        $lapsed = $this->makeSiblingTenant();
        $covered = $this->makeSiblingTenant();

        $service = app(EntitlementService::class);
        $service->grant($lapsed, $base, $plan, TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, ['expires_at' => now()->addDays(10)]);
        $service->grant($covered, $base, $plan, TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, ['expires_at' => now()->addDays(60)]);

        // Day 11: lapsed's window closed, covered's still open.
        $this->travelTo(now()->addDays(11));

        $this->artisan('billing:expire-manual-entitlements')->assertExitCode(0);

        $this->assertSame(Tenant::STATUS_BLOQUEADO, $lapsed->fresh()->status);
        $this->assertFalse($this->moduleEnabled($lapsed, $module));

        $this->assertSame(Tenant::STATUS_ACTIVO, $covered->fresh()->status);
        $this->assertTrue($this->moduleEnabled($covered, $module));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Shared central catalog: one System → one Module ('Cobros') → one base
     * billable module mapping to it + a plan pricing it.
     *
     * @return array{0: Module, 1: BillableModule, 2: BillingPackage, 3: System}
     */
    private function seedCatalog(): array
    {
        $system = $this->makeSystem();
        $module = $this->makeModuleIn($system, 'Cobros');
        $base = $this->makeBillableModule('base', 'Plan Base', [$module], isBase: true);
        $plan = $this->makePlan('esencial');
        $this->makePlanModulePrice($plan, $base, PlanModulePrice::INTERVAL_MONTH, 150000);

        return [$module, $base, $plan, $system];
    }

    private function makeSiblingTenant(): Tenant
    {
        return $this->makeTenant([
            'payment_method' => Tenant::PAYMENT_METHOD_TRANSFER,
            'currency' => 'MXN',
        ]);
    }

    private function makeSuperAdmin(): User
    {
        return User::factory()->create();
    }

    private function entitlement(Tenant $tenant, BillableModule $module): TenantEntitlement
    {
        return TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_module_id', $module->id)
            ->firstOrFail();
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
}
