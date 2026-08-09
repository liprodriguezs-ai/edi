<?php

declare(strict_types=1);

namespace Tests\Feature\Billing\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Module\Models\Module;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModule;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\EntitlementService;
use Modules\System\Models\System;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;

/**
 * Shared fixtures for billing v2 (per-module + plan) tests.
 *
 * The entitlement model is: ONE tenant_entitlement PER granted billable_module,
 * carrying the PLAN (billing_package: basico..premium) it was granted under.
 * recompute() derives access from billable_module.systemModules
 * (billable_module_system_module pivot).
 *
 * Most billing tests run transaction-less ($connectionsToTransact = []) because
 * Stancl tenant creation writes to the central DB, so the fixed-key central tables
 * (billable_module.key, billing_package.key) accumulate across tests — call
 * cleanBillingTables() in tearDown for per-test isolation.
 */
trait BillingMatrixFixtures
{
    /**
     * Reset the central billing/projection tables that carry FIXED keys and
     * accumulate across transaction-less tests.
     */
    protected function cleanBillingTables(): void
    {
        DB::table('system_tenant')->delete();
        DB::table('module_tenant')->delete();
        DB::table('subscription_payment')->delete();
        DB::table('tenant_entitlement')->delete();
        DB::table('plan_module_price')->delete();
        DB::table('plan_module')->delete();
        DB::table('billable_module_system_module')->delete();
        DB::table('billable_module')->delete();
        DB::table('billing_package')->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeTenant(array $attributes = []): Tenant
    {
        return Event::fakeFor(
            static fn (): Tenant => Tenant::create(array_merge([
                'id' => (string) Str::uuid(),
                'name' => 'Test Tenant '.Str::random(5),
                'status' => Tenant::STATUS_ACTIVO,
            ], $attributes)),
            [TenantCreated::class],
        );
    }

    protected function makeSystem(): System
    {
        return System::factory()->create();
    }

    protected function makeModuleIn(System $system, string $name, ?string $permissionEntity = null): Module
    {
        $attributes = ['name' => $name];

        if ($permissionEntity !== null) {
            $attributes['permission_entity'] = $permissionEntity;
        }

        $module = Module::factory()->create($attributes);
        $system->modules()->attach($module->id, ['sort_order' => 0]);

        return $module;
    }

    /**
     * A billable module wired to its platform system-modules
     * (billable_module_system_module pivot). Pass real Module rows so recompute()
     * can project their parent systems.
     *
     * @param  array<int, Module>  $systemModules
     */
    protected function makeBillableModule(string $key, string $name, array $systemModules = [], bool $isBase = false, int $sortOrder = 0): BillableModule
    {
        $module = BillableModule::create([
            'key' => $key,
            'name' => $name,
            'description' => $name,
            'sort_order' => $sortOrder,
            'is_active' => true,
            'is_base' => $isBase,
        ]);

        $module->systemModules()->sync(collect($systemModules)->pluck('id')->all());

        return $module;
    }

    /**
     * A billing v2 PLAN (billing_package carrying plan_module_price rows). The
     * plan-discriminator is having plan_prices, so seed at least one price with
     * makePlanModulePrice() for plan resolvers to see it.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makePlan(string $key = 'esencial', array $overrides = []): BillingPackage
    {
        return BillingPackage::create(array_merge([
            'key' => $key,
            'name' => ucfirst($key),
            'description' => 'Plan '.$key,
            'sort_order' => 0,
            'is_active' => true,
            'is_public' => true,
            'suggested_unit_min' => 1,
            'suggested_unit_max' => 100,
        ], $overrides));
    }

    /**
     * Fixture invariant: is_free/trial_days are a (plan, module) fact owned by
     * plan_module, not plan_module_price columns anymore. Every call upserts
     * the pair's plan_module row so a plan_module_price row this helper
     * creates ALWAYS has a matching plan_module row — only the flag keys
     * actually present in $overrides are written, so a bare call (no flag
     * override) still guarantees the row EXISTS (DB defaults: is_free=false,
     * trial_days=null) without clobbering flags a prior call for the same
     * pair already set.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makePlanModulePrice(BillingPackage $plan, BillableModule $module, string $interval, int $unitAmount, array $overrides = []): PlanModulePrice
    {
        $flagOverrides = array_intersect_key($overrides, array_flip(['is_free', 'trial_days']));
        $priceOverrides = array_diff_key($overrides, $flagOverrides);

        PlanModule::updateOrCreate(
            ['billing_package_id' => $plan->id, 'billable_module_id' => $module->id],
            $flagOverrides,
        );

        return PlanModulePrice::create(array_merge([
            'billing_package_id' => $plan->id,
            'billable_module_id' => $module->id,
            'interval' => $interval,
            'unit_amount' => $unitAmount,
            'currency' => 'MXN',
            'is_active' => true,
        ], $priceOverrides));
    }

    /**
     * Convenience: build a plan + a base billable module (wired to a system) + its
     * month/year prices. Returns [plan, baseModule, system].
     *
     * @return array{0: BillingPackage, 1: BillableModule, 2: System}
     */
    protected function makePlanWithBase(string $planKey = 'esencial', int $monthAmount = 95000): array
    {
        $system = $this->makeSystem();
        $moduleRow = $this->makeModuleIn($system, 'Cobros', 'charge_batch');
        $base = $this->makeBillableModule('base', 'Plan Base', [$moduleRow], isBase: true);
        $plan = $this->makePlan($planKey);
        $this->makePlanModulePrice($plan, $base, 'month', $monthAmount);
        $this->makePlanModulePrice($plan, $base, 'year', (int) round($monthAmount * 12 * 0.95));

        return [$plan, $base, $system];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function grantModule(Tenant $tenant, BillableModule $module, BillingPackage $plan, string $source, array $options = [], bool $recompute = true): TenantEntitlement
    {
        return app(EntitlementService::class)->grant($tenant, $module, $plan, $source, $options, $recompute);
    }

    protected function moduleEnabled(Tenant $tenant, Module $module): bool
    {
        $row = DB::table('module_tenant')
            ->where('tenant_id', $tenant->id)
            ->where('module_id', $module->id)
            ->first();

        // Opt-out semantics: no row OR enabled=true means enabled.
        return $row === null ? true : (bool) $row->enabled;
    }
}
