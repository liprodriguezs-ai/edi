<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\System\Models\System;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * billing:recompute-entitlements — re-proyección del acceso (system_tenant /
 * module_tenant) desde los entitlements activos. system_tenant es un SNAPSHOT:
 * solo se recalcula cuando un entitlement cambia, NO cuando cambia la matriz
 * billable_module_system_module. Este comando es el paso operativo obligado
 * tras editar la matriz (PlanModuleMatrixSeeder) o tras un reset de menú
 * (ModuleTableSeeder trunca `module` y arrastra el pivot por FK cascade).
 */
final class RecomputeEntitlementsCommandTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        $this->cleanBillingTables();

        parent::tearDown();
    }

    public function test_recompute_all_reprojects_stale_snapshots(): void
    {
        $tenant = $this->makeTenant();

        $system = System::factory()->create();
        $module = $this->makeModuleIn($system, 'Cobros');
        $base = $this->makeBillableModule('base', 'Plan Base', [$module], isBase: true);
        $plan = $this->makePlan('esencial');

        // Grant SIN recompute → snapshot obsoleto, como queda tras editar la matriz.
        $this->grantModule($tenant, $base, $plan, TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, recompute: false);

        $this->assertFalse($tenant->systems()->where('system.id', $system->id)->exists());

        $this->artisan('billing:recompute-entitlements')->assertSuccessful();

        $this->assertTrue(
            $tenant->systems()->where('system.id', $system->id)->wherePivot('is_addon', false)->exists(),
            'el sistema otorgado debe adjuntarse como sistema de plan tras el recompute',
        );
    }

    public function test_tenant_option_recomputes_only_that_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        $system = System::factory()->create();
        $module = $this->makeModuleIn($system, 'Cobros');
        $base = $this->makeBillableModule('base', 'Plan Base', [$module], isBase: true);
        $plan = $this->makePlan('esencial');

        $this->grantModule($tenantA, $base, $plan, TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, recompute: false);
        $this->grantModule($tenantB, $base, $plan, TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, recompute: false);

        $this->artisan('billing:recompute-entitlements', ['--tenant' => $tenantA->id])->assertSuccessful();

        $this->assertTrue($tenantA->systems()->where('system.id', $system->id)->exists());
        $this->assertFalse(
            $tenantB->systems()->where('system.id', $system->id)->exists(),
            'un recompute dirigido no debe tocar otros tenants',
        );
    }

    public function test_unknown_tenant_option_fails(): void
    {
        $this->artisan('billing:recompute-entitlements', ['--tenant' => '00000000-0000-4000-8000-000000000000'])
            ->assertFailed();
    }
}
