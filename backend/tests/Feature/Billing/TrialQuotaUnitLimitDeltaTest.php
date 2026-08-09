<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanQuota;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\Tenant\Models\Tenant;
use Tests\Feature\Housing\HousingTestHelpers;
use Tests\Support\Catalog;
use Tests\TestCase;

/**
 * Feature tests for the unit-limit-enforcement delta
 * (openspec/changes/trial-usage-quotas/specs/unit-limit-enforcement/spec.md,
 * REQ-12 backend half — REQ-13's frontend half is covered by
 * UnitForm.test.tsx). Covers the 4 scenarios verbatim from the spec.
 */
final class TrialQuotaUnitLimitDeltaTest extends TestCase
{
    use HousingTestHelpers;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        DB::table('plan_quota')->delete();

        parent::tearDown();
    }

    /**
     * A TRIAL-scope band (so the tenant's scope resolves 'trial' — the
     * quota's shipped/tested scope), mirroring UnitLimitEnforcementTest's
     * grantBand() but with source=trial instead of source=manual.
     */
    private function grantTrialBand(Tenant $tenant, string $planName, int $bandLimit): BillingPackage
    {
        return $tenant->run(function () use ($tenant, $planName, $bandLimit): BillingPackage {
            $module = BillableModule::create([
                'key' => 'base_'.Str::random(6),
                'name' => 'Plan Base',
                'sort_order' => 0,
                'is_active' => true,
                'is_base' => true,
            ]);

            $plan = BillingPackage::create([
                'key' => Str::slug($planName).'_'.Str::random(6),
                'name' => $planName,
                'sort_order' => 0,
                'is_active' => true,
                'is_public' => true,
                'suggested_unit_min' => 1,
                'suggested_unit_max' => $bandLimit,
            ]);

            TenantEntitlement::create([
                'tenant_id' => $tenant->id,
                'billable_module_id' => $module->id,
                'billing_package_id' => $plan->id,
                'source' => TenantEntitlement::SOURCE_TRIAL,
                'expires_at' => now()->addDays(14),
            ]);

            return $plan;
        });
    }

    private function seedTrialUnitQuota(int $limit): void
    {
        PlanQuota::create([
            'billing_package_id' => null,
            'scope' => PlanQuota::SCOPE_TRIAL,
            'resource_key' => PlanQuota::RESOURCE_UNIT,
            'limit_value' => $limit,
        ]);
    }

    private function seedActiveUnits(Tenant $tenant, int $count): void
    {
        $tenant->run(function () use ($tenant, $count): void {
            $unitTypeId = Catalog::id(UnitType::class, 'Casa');
            $unitStatusId = Catalog::id(UnitStatus::class, 'Activa');

            for ($i = 0; $i < $count; $i++) {
                Unit::withoutEvents(function () use ($tenant, $i, $unitTypeId, $unitStatusId): void {
                    $unit = new Unit;
                    $unit->forceFill([
                        'tenant_id' => $tenant->id,
                        'unit_type_id' => $unitTypeId,
                        'unit_status_id' => $unitStatusId,
                        'number' => 'delta-seed-'.$i.'-'.Str::random(4),
                    ]);
                    $unit->save();
                });
            }
        });
    }

    private function unitPayload(?string $number = null): array
    {
        return [
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'number' => $number ?? Str::random(8),
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
        ];
    }

    // =========================================================================
    // Scenario 1 — trial quota stricter than the band blocks first
    // =========================================================================

    public function test_trial_quota_stricter_than_the_band_blocks_first(): void
    {
        $tenant = $this->makeTenant('Delta Quota Stricter');
        $this->seedTenant($tenant);
        $this->grantTrialBand($tenant, 'Básico', 25);
        $this->seedTrialUnitQuota(2);
        $this->seedActiveUnits($tenant, 2);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/unit', $this->unitPayload())
            ->assertStatus(422)
            ->assertJsonPath('code', 'QUOTA_EXCEEDED');

        $tenant->run(function (): void {
            $this->assertSame(2, Unit::count(), 'The active unit count must remain unchanged');
        });
    }

    // =========================================================================
    // Scenario 2 — band stricter than the quota still governs
    // =========================================================================

    public function test_band_stricter_than_the_quota_still_governs(): void
    {
        $tenant = $this->makeTenant('Delta Band Stricter');
        $this->seedTenant($tenant);
        $this->grantTrialBand($tenant, 'Básico', 25);
        $this->seedTrialUnitQuota(100);
        $this->seedActiveUnits($tenant, 25);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/unit', $this->unitPayload())
            ->assertStatus(422)
            ->assertJsonPath('code', 'UNIT_LIMIT_EXCEEDED', 'Must carry the BAND code, never the quota code, when the band is the stricter cap');
    }

    // =========================================================================
    // Scenario 3 — deleting a unit frees quota room
    // =========================================================================

    public function test_deleting_a_unit_frees_quota_room(): void
    {
        $tenant = $this->makeTenant('Delta Free Slot');
        $this->seedTenant($tenant);
        $this->grantTrialBand($tenant, 'Básico', 25);
        $this->seedTrialUnitQuota(2);
        $this->seedActiveUnits($tenant, 2);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $unitToDelete = $tenant->run(fn () => Unit::first());

        $this->tenantRequest($token, $tenant)
            ->deleteJson('/api/unit/'.$unitToDelete->id)
            ->assertStatus(200);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/unit', $this->unitPayload())
            ->assertStatus(201);

        $tenant->run(function (): void {
            $this->assertSame(2, Unit::count(), 'Live count back to 2 — the freed slot was reused');
        });
    }

    // =========================================================================
    // Scenario 4 — unlimited quota leaves the band alone
    // =========================================================================

    public function test_unlimited_quota_leaves_band_only_behavior_byte_identical(): void
    {
        $tenant = $this->makeTenant('Delta Unlimited Quota');
        $this->seedTenant($tenant);
        $this->grantTrialBand($tenant, 'Básico', 25);
        $this->seedTrialUnitQuota(PlanQuota::UNLIMITED);
        $this->seedActiveUnits($tenant, 25);
        $admin = $this->makeUser($tenant);
        $token = $this->tokenFor($admin, ['*']);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/unit', $this->unitPayload())
            ->assertStatus(422)
            ->assertJsonPath('code', 'UNIT_LIMIT_EXCEEDED');
    }
}
