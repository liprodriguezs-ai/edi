<?php

declare(strict_types=1);

namespace Modules\SubscriptionPlan\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\SubscriptionPlan\Exceptions\UnitLimitExceededException;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModule;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\PlanPricingService;
use Modules\SubscriptionPlan\Services\UnitLimitService;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Unit tests for UnitLimitService — band resolution + checkIncrease() math.
 *
 * Covers: 0 / 1 / >1 distinct billing_package_id across active entitlements,
 * expired entitlements excluded, null suggested_unit_max fail-open,
 * remaining-room math at/over/under the limit, and the upgrade hint
 * (present when a fitting next band exists, null at the top band).
 */
class UnitLimitServiceTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected $connectionsToTransact = [];

    protected Tenant $tenant;

    protected UnitLimitService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([TenantCreated::class]);
        $this->tenant = $this->makeTenant();
        tenancy()->initialize($this->tenant);

        $this->service = new UnitLimitService(new PlanPricingService);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Central tables (billable_module, billing_package) carry a unique `key`
     * and this test runs transaction-less ($connectionsToTransact = []) — a
     * fixed key would collide across test methods within the same run.
     */
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
    private function makePlan(string $key, ?int $max, array $overrides = []): BillingPackage
    {
        return BillingPackage::create(array_merge([
            'key' => $key.'_'.Str::random(8),
            'name' => ucfirst($key),
            'sort_order' => 0,
            'is_active' => true,
            'is_public' => true,
            'suggested_unit_min' => $max !== null ? 1 : null,
            'suggested_unit_max' => $max,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function grant(BillableModule $module, BillingPackage $plan, array $overrides = []): TenantEntitlement
    {
        return TenantEntitlement::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'billable_module_id' => $module->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_MANUAL,
            'expires_at' => null,
        ], $overrides));
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
            // withoutEvents() mutes BelongsToTenant's own `creating` listener too
            // (it is a normal event listener, not a query scope) — forceFill()
            // bypasses the $fillable guard so tenant_id still gets set directly.
            Unit::withoutEvents(function () use ($i, $unitType, $unitStatus): void {
                $unit = new Unit;
                $unit->forceFill([
                    'tenant_id' => $this->tenant->id,
                    'unit_type_id' => $unitType->id,
                    'unit_status_id' => $unitStatus->id,
                    'number' => (string) $i,
                ]);
                $unit->save();
            });
        }
    }

    // =========================================================================
    // Band resolution
    // =========================================================================

    public function test_no_active_entitlement_resolves_to_null_band(): void
    {
        $this->assertNull($this->service->resolveBand());
    }

    public function test_one_distinct_package_resolves_its_band(): void
    {
        $module = $this->makeBillableModule('base');
        $plan = $this->makePlan('basico', 25);
        $this->grant($module, $plan);

        $band = $this->service->resolveBand();

        $this->assertNotNull($band);
        $this->assertSame('Basico', $band['planName']);
        $this->assertSame(25, $band['limit']);
    }

    public function test_expired_entitlement_is_excluded(): void
    {
        $module = $this->makeBillableModule('base');
        $plan = $this->makePlan('basico', 25);
        $this->grant($module, $plan, ['expires_at' => now()->subDay()]);

        $this->assertNull($this->service->resolveBand(), 'An expired entitlement must not resolve a band (fail-open)');
    }

    public function test_null_suggested_unit_max_fails_open(): void
    {
        $module = $this->makeBillableModule('base');
        $plan = $this->makePlan('sin_banda', null);
        $this->grant($module, $plan);

        $this->assertNull($this->service->resolveBand(), 'A plan with no suggested_unit_max must fail-open (future-proofs an unlimited band)');
    }

    public function test_multiple_distinct_packages_resolve_the_largest_cap_and_report_the_anomaly(): void
    {
        Log::spy();

        $moduleA = $this->makeBillableModule('module_a');
        $moduleB = $this->makeBillableModule('module_b');
        $planSmall = $this->makePlan('basico', 25);
        $planLarge = $this->makePlan('premium', 300);

        $this->grant($moduleA, $planSmall);
        $this->grant($moduleB, $planLarge);

        $band = $this->service->resolveBand();

        $this->assertNotNull($band);
        $this->assertSame(300, $band['limit'], 'The largest cap among the distinct packages must win');

        Log::shouldHaveReceived('error')->once();
    }

    // =========================================================================
    // checkIncrease() math
    // =========================================================================

    public function test_check_increase_allows_reaching_the_limit_exactly(): void
    {
        $module = $this->makeBillableModule('base');
        $plan = $this->makePlan('basico', 25);
        $this->grant($module, $plan);
        $this->makeUnits(24);

        $check = $this->service->checkIncrease(1);

        $this->assertTrue($check->allowed);
        $this->assertSame(24, $check->activeUnits);
    }

    public function test_check_increase_rejects_going_over_the_limit(): void
    {
        $module = $this->makeBillableModule('base');
        $plan = $this->makePlan('basico', 25);
        $this->grant($module, $plan);
        $this->makeUnits(25);

        $check = $this->service->checkIncrease(1);

        $this->assertFalse($check->allowed);
        $this->assertSame('Basico', $check->planName);
        $this->assertSame(25, $check->unitLimit);
        $this->assertSame(25, $check->activeUnits);
        $this->assertSame(1, $check->attemptedIncrease);
    }

    public function test_check_increase_allows_when_comfortably_under_the_limit(): void
    {
        $module = $this->makeBillableModule('base');
        $plan = $this->makePlan('basico', 25);
        $this->grant($module, $plan);
        $this->makeUnits(5);

        $check = $this->service->checkIncrease(3);

        $this->assertTrue($check->allowed);
    }

    public function test_check_increase_fails_open_without_tenancy_initialized(): void
    {
        tenancy()->end();

        $check = $this->service->checkIncrease(1);

        $this->assertTrue($check->allowed);
        $this->assertNull($check->planName);

        // Re-initialize so tearDown() can safely end tenancy again.
        tenancy()->initialize($this->tenant);
    }

    // =========================================================================
    // Upgrade hint
    // =========================================================================

    public function test_suggested_plan_name_present_when_a_fitting_next_band_exists(): void
    {
        $baseModuleRow = $this->makeBillableModule('base_module');
        $basico = $this->makePlan('basico', 25);
        $inicial = $this->makePlan('inicial', 50, ['sort_order' => 1, 'suggested_unit_min' => 26]);

        // planForUnits() reads BillingPackage::activePlans() -> whereHas('plan_prices'),
        // so both plans need at least one active price row to be resolvable.
        $pricingModule = BillableModule::firstOrCreate(
            ['key' => 'pricing_base'],
            ['name' => 'Pricing Base', 'sort_order' => 0, 'is_active' => true, 'is_base' => true]
        );
        foreach ([$basico, $inicial] as $plan) {
            PlanModule::updateOrCreate(
                ['billing_package_id' => $plan->id, 'billable_module_id' => $pricingModule->id],
                ['is_free' => false, 'trial_days' => null],
            );
            PlanModulePrice::create([
                'billing_package_id' => $plan->id,
                'billable_module_id' => $pricingModule->id,
                'interval' => 'month',
                'unit_amount' => 10000,
                'currency' => 'MXN',
                'is_active' => true,
            ]);
        }

        $this->grant($baseModuleRow, $basico);
        $this->makeUnits(25);

        $check = $this->service->checkIncrease(1);

        $this->assertFalse($check->allowed);
        $this->assertSame('Inicial', $check->suggestedPlanName);
    }

    public function test_suggested_plan_name_is_null_at_the_top_band(): void
    {
        $module = $this->makeBillableModule('base');
        $premium = $this->makePlan('premium', 300);
        $this->grant($module, $premium);
        $this->makeUnits(300);

        $check = $this->service->checkIncrease(1);

        $this->assertFalse($check->allowed);
        $this->assertNull($check->suggestedPlanName, 'No dead-end upgrade link may be offered at the top band (REQ-08)');
    }

    // =========================================================================
    // checkPlanFits() — plan-downgrade-guard EXIT-door check
    // =========================================================================

    public function test_check_plan_fits_blocks_a_target_whose_max_is_below_active_units(): void
    {
        $this->makeUnits(200);
        $target = $this->makePlan('basico', 25);

        $check = $this->service->checkPlanFits($target);

        $this->assertFalse($check->allowed);
        $this->assertSame('Basico', $check->planName, 'planName/unitLimit describe the TARGET plan, never resolveBand()');
        $this->assertSame(25, $check->unitLimit);
        $this->assertSame(200, $check->activeUnits);
        $this->assertSame(0, $check->attemptedIncrease, 'attemptedIncrease is meaningless under target semantics');
    }

    public function test_check_plan_fits_allows_exactly_at_the_target_max(): void
    {
        $this->makeUnits(25);
        $target = $this->makePlan('basico', 25);

        $check = $this->service->checkPlanFits($target);

        $this->assertTrue($check->allowed, 'Exactly-at-the-limit must fit — strict >, never >= (REQ-05)');
    }

    public function test_check_plan_fits_allows_a_null_max_target_regardless_of_unit_count(): void
    {
        $this->makeUnits(200);
        $target = $this->makePlan('enterprise', null);

        $check = $this->service->checkPlanFits($target);

        $this->assertTrue($check->allowed, 'NULL suggested_unit_max must always fit (REQ-06, future-proofs an unlimited Enterprise band)');
    }

    public function test_check_plan_fits_fails_open_without_tenancy_initialized(): void
    {
        tenancy()->end();

        $target = $this->makePlan('basico', 25);

        $check = $this->service->checkPlanFits($target);

        $this->assertTrue($check->allowed);
        $this->assertNull($check->planName);

        // Re-initialize so tearDown() can safely end tenancy again.
        tenancy()->initialize($this->tenant);
    }

    public function test_check_plan_fits_suggests_the_smallest_fitting_band_and_it_may_equal_the_tenants_current_plan(): void
    {
        // Unlike checkIncrease()'s suggestPlanName call (which excludes the tenant's
        // CURRENT band), checkPlanFits() passes NO exclusion — so when the ONE
        // fitting plan happens to be the tenant's own current plan, it is still
        // surfaced (Decision 2): "the plan that fits is the one you already have".
        $baseModuleRow = $this->makeBillableModule('base_module');
        $pricingModule = BillableModule::firstOrCreate(
            ['key' => 'pricing_base'],
            ['name' => 'Pricing Base', 'sort_order' => 0, 'is_active' => true, 'is_base' => true]
        );

        $current = $this->makePlan('avanzado', 200, ['sort_order' => 0]);
        PlanModule::updateOrCreate(
            ['billing_package_id' => $current->id, 'billable_module_id' => $pricingModule->id],
            ['is_free' => false, 'trial_days' => null],
        );
        PlanModulePrice::create([
            'billing_package_id' => $current->id,
            'billable_module_id' => $pricingModule->id,
            'interval' => 'month',
            'unit_amount' => 10000,
            'currency' => 'MXN',
            'is_active' => true,
        ]);

        $this->grant($baseModuleRow, $current);
        $this->makeUnits(200);

        $target = $this->makePlan('basico', 25);

        $check = $this->service->checkPlanFits($target);

        $this->assertFalse($check->allowed);
        $this->assertSame(
            'Avanzado',
            $check->suggestedPlanName,
            'checkPlanFits must NOT exclude the tenant\'s current plan from the suggestion (unlike checkIncrease)'
        );
    }

    public function test_check_plan_fits_suggestion_is_null_when_no_plan_fits_above_the_top_band(): void
    {
        // Pins planForUnits()'s clamp-to-largest-band behavior: 400 units is above
        // the ONLY published band (Premium, max 300). planForUnits() clamps and
        // returns Premium anyway, but suggestPlanName()'s own fits-check
        // (400 > 300) neutralizes that clamp back to null — this test guards that
        // neutralization; it must NOT be "fixed" by touching planForUnits itself.
        $pricingModule = BillableModule::firstOrCreate(
            ['key' => 'pricing_base'],
            ['name' => 'Pricing Base', 'sort_order' => 0, 'is_active' => true, 'is_base' => true]
        );
        $premium = $this->makePlan('premium', 300);
        PlanModule::updateOrCreate(
            ['billing_package_id' => $premium->id, 'billable_module_id' => $pricingModule->id],
            ['is_free' => false, 'trial_days' => null],
        );
        PlanModulePrice::create([
            'billing_package_id' => $premium->id,
            'billable_module_id' => $pricingModule->id,
            'interval' => 'month',
            'unit_amount' => 10000,
            'currency' => 'MXN',
            'is_active' => true,
        ]);

        $this->makeUnits(400);
        $target = $this->makePlan('basico', 25);

        $check = $this->service->checkPlanFits($target);

        $this->assertFalse($check->allowed);
        $this->assertNull($check->suggestedPlanName, 'No dead-end suggestion above the top published band');
    }

    // =========================================================================
    // assertPlanFits() + buildPlanChangeMessage() — REQ-07 copy
    // =========================================================================

    public function test_assert_plan_fits_throws_with_the_fits_exists_message_variant(): void
    {
        $this->makeUnits(200);
        $target = $this->makePlan('basico', 25);

        try {
            $this->service->assertPlanFits($target);
            $this->fail('Expected UnitLimitExceededException to be thrown');
        } catch (UnitLimitExceededException $e) {
            $response = $e->render();
            $payload = $response->getData(true);

            $this->assertSame(422, $response->getStatusCode());
            $this->assertSame('UNIT_LIMIT_EXCEEDED', $payload['code']);
            $this->assertSame('Basico', $payload['plan_name']);
            $this->assertSame(25, $payload['unit_limit']);
            $this->assertSame(200, $payload['active_units']);
            $this->assertArrayNotHasKey('attempted_increase', $payload, 'Meaningless under target semantics — must be omitted entirely');
            $this->assertStringContainsString('200 unidades activas', $payload['message']);
            $this->assertStringContainsString('Basico', $payload['message']);
        }
    }

    public function test_build_plan_change_message_degrades_gracefully_with_no_fitting_plan(): void
    {
        $this->makeUnits(400);
        $target = $this->makePlan('basico', 25);

        $check = $this->service->checkPlanFits($target);
        $message = $this->service->buildPlanChangeMessage($check);

        $this->assertStringContainsString('400 unidades activas', $message);
        $this->assertStringContainsString('Contacta a soporte', $message);
    }
}
