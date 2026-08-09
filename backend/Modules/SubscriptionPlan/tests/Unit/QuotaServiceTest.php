<?php

declare(strict_types=1);

namespace Modules\SubscriptionPlan\Tests\Unit;

use App\Services\SubscriptionStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\SubscriptionPlan\Exceptions\QuotaExceededException;
use Modules\SubscriptionPlan\Exceptions\UnitLimitExceededException;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanQuota;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Models\TenantQuotaUsage;
use Modules\SubscriptionPlan\Services\PlanPricingService;
use Modules\SubscriptionPlan\Services\QuotaService;
use Modules\SubscriptionPlan\Services\UnitLimitService;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Unit tests for QuotaService — resolveQuota() resolution, fail-open,
 * consumption math, and the D5 band+quota composition on `unit`. Every test
 * seeds its OWN `plan_quota` rows (never relies on the shipped `-1` seed —
 * REQ-QE-05).
 */
class QuotaServiceTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected $connectionsToTransact = [];

    protected Tenant $tenant;

    protected QuotaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([TenantCreated::class]);
        $this->tenant = $this->makeTenant();
        tenancy()->initialize($this->tenant);

        $this->service = new QuotaService(
            app(SubscriptionStateService::class),
            new UnitLimitService(new PlanPricingService),
        );
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        // plan_quota is CENTRAL (no tenant_id) and this suite runs
        // transaction-less ($connectionsToTransact = [], same reason as
        // UnitLimitServiceTest — Stancl tenant creation writes to the central
        // DB). Global rows share a FIXED (scope, resource_key) key, so they
        // MUST be cleaned between tests or the next test's insert collides on
        // plan_quota_global_unique.
        DB::table('plan_quota')->delete();

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeBillableModule(string $key): BillableModule
    {
        return BillableModule::create([
            'key' => $key.'_'.Str::random(8),
            'name' => $key,
            'sort_order' => 0,
            'is_active' => true,
            'is_base' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makePlan(string $key, ?int $unitMax = null, array $overrides = []): BillingPackage
    {
        return BillingPackage::create(array_merge([
            'key' => $key.'_'.Str::random(8),
            'name' => ucfirst($key),
            'sort_order' => 0,
            'is_active' => true,
            'is_public' => true,
            'suggested_unit_min' => $unitMax !== null ? 1 : null,
            'suggested_unit_max' => $unitMax,
        ], $overrides));
    }

    /**
     * Grants a TRIAL-scope entitlement (so quotaScopeFor() resolves 'trial' —
     * the shipped/tested scope for this change).
     */
    private function grantTrial(BillableModule $module, BillingPackage $plan): TenantEntitlement
    {
        return TenantEntitlement::create([
            'tenant_id' => $this->tenant->id,
            'billable_module_id' => $module->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_TRIAL,
            'expires_at' => now()->addDays(14),
        ]);
    }

    private function globalQuota(string $resource, int $limit, string $scope = PlanQuota::SCOPE_TRIAL): PlanQuota
    {
        return PlanQuota::create([
            'billing_package_id' => null,
            'scope' => $scope,
            'resource_key' => $resource,
            'limit_value' => $limit,
        ]);
    }

    private function planQuota(BillingPackage $plan, string $resource, int $limit, string $scope = PlanQuota::SCOPE_TRIAL): PlanQuota
    {
        return PlanQuota::create([
            'billing_package_id' => $plan->id,
            'scope' => $scope,
            'resource_key' => $resource,
            'limit_value' => $limit,
        ]);
    }

    private function makeUnits(int $count): void
    {
        $unitType = UnitType::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Casa'],
            ['order' => 1, 'is_active' => true, 'requires_floor' => false]
        );
        $unitStatus = UnitStatus::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Activa'],
            ['order' => 1, 'is_active' => true, 'is_billable' => true]
        );

        for ($i = 0; $i < $count; $i++) {
            $number = Str::random(8);
            Unit::withoutEvents(function () use ($number, $unitType, $unitStatus): void {
                $unit = new Unit;
                $unit->forceFill([
                    'tenant_id' => $this->tenant->id,
                    'unit_type_id' => $unitType->id,
                    'unit_status_id' => $unitStatus->id,
                    'number' => $number,
                ]);
                $unit->save();
            });
        }
    }

    // =========================================================================
    // resolveQuota() — most-specific-row-wins (REQ-QC-02)
    // =========================================================================

    public function test_plan_specific_row_overrides_global_row_for_a_tenant_on_that_plan(): void
    {
        $module = $this->makeBillableModule('base');
        $premium = $this->makePlan('premium');
        $this->globalQuota(PlanQuota::RESOURCE_VISITOR, 3);
        $this->planQuota($premium, PlanQuota::RESOURCE_VISITOR, 10);
        $this->grantTrial($module, $premium);

        $limit = $this->service->resolveQuota(PlanQuota::RESOURCE_VISITOR, PlanQuota::SCOPE_TRIAL);

        $this->assertSame(10, $limit);
    }

    public function test_global_row_still_applies_to_a_tenant_on_a_different_plan(): void
    {
        $module = $this->makeBillableModule('base');
        $premium = $this->makePlan('premium');
        $basico = $this->makePlan('basico');
        $this->globalQuota(PlanQuota::RESOURCE_VISITOR, 3);
        $this->planQuota($premium, PlanQuota::RESOURCE_VISITOR, 10);
        $this->grantTrial($module, $basico);

        $limit = $this->service->resolveQuota(PlanQuota::RESOURCE_VISITOR, PlanQuota::SCOPE_TRIAL);

        $this->assertSame(3, $limit);
    }

    // =========================================================================
    // -1 / absent → unlimited (D6, REQ-QC-03)
    // =========================================================================

    public function test_unlimited_quota_never_blocks_at_any_usage_level(): void
    {
        $module = $this->makeBillableModule('base');
        $plan = $this->makePlan('premium');
        $this->globalQuota(PlanQuota::RESOURCE_VISITOR, PlanQuota::UNLIMITED);
        $this->grantTrial($module, $plan);

        $check = $this->service->checkConsumption(PlanQuota::RESOURCE_VISITOR, 100000);

        $this->assertTrue($check->allowed);
        $this->assertNull($check->limit, 'Unlimited must surface as null, never the raw -1 sentinel (D6)');
    }

    public function test_no_resolvable_row_fails_open_with_no_numbers_resolved(): void
    {
        $module = $this->makeBillableModule('base');
        $plan = $this->makePlan('premium');
        $this->grantTrial($module, $plan);
        // No plan_quota row at all for this resource.

        $check = $this->service->checkConsumption(PlanQuota::RESOURCE_VISITOR, 1);

        $this->assertTrue($check->allowed);
        $this->assertNull($check->scope, 'No numbers/scope resolved on the shipped-OFF fast path');
        $this->assertNull($check->limit);
    }

    public function test_no_tenancy_initialized_fails_open(): void
    {
        tenancy()->end();

        $check = $this->service->checkConsumption(PlanQuota::RESOURCE_VISITOR, 1);

        $this->assertTrue($check->allowed);
        $this->assertNull($check->scope);

        tenancy()->initialize($this->tenant);
    }

    public function test_assert_and_consume_fails_open_without_tenancy_and_writes_nothing(): void
    {
        // The guard inside assertAndConsume() itself — the one the WHOLE public
        // entry point depends on, and which the check-only test above does NOT
        // reach. Deleting it leaves the suite green otherwise: consume() would
        // then run with TenantScope::apply() early-returning unscoped, either
        // incrementing SOMEONE ELSE'S counter row or dying on the tenant_id NOT
        // NULL constraint — a fail-CLOSED 500 inside a guard REQ-QE-04 requires
        // to fail OPEN.
        $this->globalQuota(PlanQuota::RESOURCE_VISITOR, 1);
        TenantQuotaUsage::create([
            'resource_key' => PlanQuota::RESOURCE_VISITOR,
            'used_count' => 1, // already AT the limit: only the guard can let this pass
        ]);

        $rowsBefore = DB::table('tenant_quota_usage')->count();
        $usedBefore = (int) DB::table('tenant_quota_usage')
            ->where('resource_key', PlanQuota::RESOURCE_VISITOR)
            ->value('used_count');

        tenancy()->end();

        try {
            // No exception, no transaction required, no counter touched: it must
            // return before reaching checkConsumption()/consume() at all.
            $this->service->assertAndConsume(PlanQuota::RESOURCE_VISITOR);
        } finally {
            tenancy()->initialize($this->tenant);
        }

        $this->assertSame($rowsBefore, DB::table('tenant_quota_usage')->count(), 'no counter row may be created without tenancy');
        $this->assertSame(
            $usedBefore,
            (int) DB::table('tenant_quota_usage')->where('resource_key', PlanQuota::RESOURCE_VISITOR)->value('used_count'),
            'the counter must not move without tenancy'
        );
    }

    // =========================================================================
    // D5 composition — assertCanRegisterUnits() min(band, quota)
    // =========================================================================

    public function test_band_stricter_than_quota_still_governs(): void
    {
        $module = $this->makeBillableModule('base');
        $plan = $this->makePlan('basico', 25);
        $this->globalQuota(PlanQuota::RESOURCE_UNIT, 100);
        $this->grantTrial($module, $plan);
        $this->makeUnits(25);

        $this->expectException(UnitLimitExceededException::class);

        $this->service->assertCanRegisterUnits(1);
    }

    public function test_quota_stricter_than_band_blocks_first(): void
    {
        $module = $this->makeBillableModule('base');
        $plan = $this->makePlan('basico', 25);
        $this->globalQuota(PlanQuota::RESOURCE_UNIT, 2);
        $this->grantTrial($module, $plan);
        $this->makeUnits(2);

        $this->expectException(QuotaExceededException::class);

        $this->service->assertCanRegisterUnits(1);
    }

    public function test_unlimited_quota_leaves_band_only_behavior_byte_identical(): void
    {
        $module = $this->makeBillableModule('base');
        $plan = $this->makePlan('basico', 25);
        $this->globalQuota(PlanQuota::RESOURCE_UNIT, PlanQuota::UNLIMITED);
        $this->grantTrial($module, $plan);
        $this->makeUnits(24);

        // Under the band: allowed.
        $this->service->assertCanRegisterUnits(1);
        $this->addToAssertionCount(1);

        $this->makeUnits(1); // now at 25, exactly the band.

        $this->expectException(UnitLimitExceededException::class);
        $this->service->assertCanRegisterUnits(1);
    }
}
