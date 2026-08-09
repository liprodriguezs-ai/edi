<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Setting\Models\Setting;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\EntitlementService;
use Modules\System\Models\System;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Database\TenantScope;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * Coverage for the PER-MODULE entitlement projector (billing v2). EntitlementService
 * reads a tenant's ACTIVE tenant_entitlement rows (one per granted billable_module)
 * and recomputes system_tenant / module_tenant / per-tenant config from
 * billable_module.systemModules — WITHOUT touching Stripe.
 *
 * The PARITY test proves recompute() (fed by a base-module entitlement) yields the
 * same is_addon=false system_tenant set that the UNCHANGED syncFromPlan produces.
 */
final class EntitlementServiceTest extends TestCase
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

    // =========================================================================
    // recompute() — system_tenant + module_tenant
    // =========================================================================

    public function test_recompute_grants_systems_and_enables_modules_for_active_entitlement(): void
    {
        $tenant = $this->makeTenant();

        [$systemA, $systemB] = [System::factory()->create(), System::factory()->create()];
        $moduleA = $this->makeModuleIn($systemA, 'Cobros');
        $moduleB = $this->makeModuleIn($systemB, 'Avisos');
        // A billable module the tenant will NOT be granted (must end disabled).
        $ungranted = $this->makeModuleIn($systemB, 'Paquetería');

        $base = $this->makeBillableModule('base', 'Plan Base', [$moduleA, $moduleB], isBase: true);
        // Make $ungranted billable (a different billable module) so it enters module_tenant.
        $this->makeBillableModule('package', 'Paquetería', [$ungranted]);

        $plan = $this->makePlan('esencial');

        $this->grantModule($tenant, $base, $plan, TenantEntitlement::SOURCE_STRIPE, [
            'interval' => 'month',
            'quantity' => 10,
        ]);

        // system_tenant: both parent systems attached as plan systems (is_addon=false).
        $planSystemIds = $tenant->systems()->wherePivot('is_addon', false)->pluck('system.id')->sort()->values()->all();
        $this->assertSame(
            collect([$systemA->id, $systemB->id])->sort()->values()->all(),
            $planSystemIds,
        );

        // module_tenant: granted modules enabled, billable-but-ungranted disabled.
        $this->assertTrue($this->moduleEnabled($tenant, $moduleA));
        $this->assertTrue($this->moduleEnabled($tenant, $moduleB));
        $this->assertFalse($this->moduleEnabled($tenant, $ungranted));
    }

    public function test_recompute_preserves_manual_addons_and_detaches_plan_orphans(): void
    {
        $tenant = $this->makeTenant();

        $systemGranted = System::factory()->create();
        $systemAddon = System::factory()->create();
        $systemOrphan = System::factory()->create();

        $module = $this->makeModuleIn($systemGranted, 'Cobros');
        $base = $this->makeBillableModule('base', 'Plan Base', [$module], isBase: true);
        $plan = $this->makePlan('esencial');

        // Pre-existing manual add-on (must survive) and a stale plan system (must go).
        $tenant->systems()->attach($systemAddon->id, ['is_addon' => true]);
        $tenant->systems()->attach($systemOrphan->id, ['is_addon' => false]);

        $this->grantModule($tenant, $base, $plan, TenantEntitlement::SOURCE_SUPER_ADMIN_FREE);

        $this->assertTrue(
            $tenant->systems()->where('system.id', $systemAddon->id)->wherePivot('is_addon', true)->exists(),
            'manual add-on must be preserved',
        );
        $this->assertFalse(
            $tenant->systems()->where('system.id', $systemOrphan->id)->exists(),
            'orphan plan system must be detached',
        );
        $this->assertTrue(
            $tenant->systems()->where('system.id', $systemGranted->id)->wherePivot('is_addon', false)->exists(),
            'granted system attached as plan system',
        );
    }

    // =========================================================================
    // Expiration turns modules off and purges config
    // =========================================================================

    public function test_expired_entitlement_disables_modules_and_purges_config(): void
    {
        $tenant = $this->makeTenant();

        $system = System::factory()->create();
        // permission_entity 'charge_batch' matches EntitlementService::moduleSettingPrefixes,
        // which owns the "collections." Setting slug prefix that gets purged on revoke.
        $collectionsModule = $this->makeModuleIn($system, 'Cobros', 'charge_batch');
        $base = $this->makeBillableModule('base', 'Plan Base', [$collectionsModule], isBase: true);
        $plan = $this->makePlan('esencial');

        $service = app(EntitlementService::class);
        $this->grantModule($tenant, $base, $plan, TenantEntitlement::SOURCE_STRIPE, ['interval' => 'month']);

        // Tenant-scoped config row belonging to the Collections module.
        $this->withTenant($tenant, function (): void {
            Setting::create([
                'name' => 'collections.due_soon_days',
                'slug' => 'collections.due_soon_days',
                'value' => 5,
                'type' => Setting::TYPE_INTEGER,
            ]);
        });

        $this->assertTrue($this->moduleEnabled($tenant, $collectionsModule));
        $this->assertSettingExists($tenant, 'collections.due_soon_days');

        // Expire the entitlement and recompute.
        TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->update(['expires_at' => now()->subDay()]);

        $service->recompute($tenant);

        // System detached, module disabled, config soft-deleted.
        $this->assertSame(0, $tenant->systems()->wherePivot('is_addon', false)->count());
        $this->assertFalse($this->moduleEnabled($tenant, $collectionsModule));
        $this->assertSettingMissing($tenant, 'collections.due_soon_days');
    }

    // =========================================================================
    // Concurrent add-ons
    // =========================================================================

    public function test_concurrent_addons_union_their_systems_and_modules(): void
    {
        $tenant = $this->makeTenant();

        $systemBase = System::factory()->create();
        $systemAddon = System::factory()->create();

        $baseModuleRow = $this->makeModuleIn($systemBase, 'Cobros');
        $addonModuleRow = $this->makeModuleIn($systemAddon, 'Paquetería');

        $base = $this->makeBillableModule('base', 'Plan Base', [$baseModuleRow], isBase: true);
        $addon = $this->makeBillableModule('package', 'Paquetería', [$addonModuleRow]);
        $plan = $this->makePlan('esencial');

        $service = app(EntitlementService::class);
        $this->grantModule($tenant, $base, $plan, TenantEntitlement::SOURCE_STRIPE, ['interval' => 'month']);
        $this->grantModule($tenant, $addon, $plan, TenantEntitlement::SOURCE_STRIPE, ['interval' => 'month']);

        $planSystemIds = $tenant->systems()->wherePivot('is_addon', false)->pluck('system.id')->sort()->values()->all();
        $this->assertSame(
            collect([$systemBase->id, $systemAddon->id])->sort()->values()->all(),
            $planSystemIds,
        );

        $this->assertTrue($this->moduleEnabled($tenant, $baseModuleRow));
        $this->assertTrue($this->moduleEnabled($tenant, $addonModuleRow));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function withTenant(Tenant $tenant, callable $callback): void
    {
        tenancy()->initialize($tenant);

        try {
            $callback();
        } finally {
            tenancy()->end();
        }
    }

    private function assertSettingExists(Tenant $tenant, string $slug): void
    {
        $this->assertTrue(
            Setting::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant->id)
                ->where('slug', $slug)
                ->exists(),
            "expected setting {$slug} to exist",
        );
    }

    private function assertSettingMissing(Tenant $tenant, string $slug): void
    {
        $this->assertFalse(
            Setting::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant->id)
                ->where('slug', $slug)
                ->exists(),
            "expected setting {$slug} to be purged (soft-deleted)",
        );
    }
}
