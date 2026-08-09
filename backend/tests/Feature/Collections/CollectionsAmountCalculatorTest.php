<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Services\AmountCalculator;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * AmountCalculator — comprehensive tests for every amount mode.
 *
 * Covers: FIXED_REQUIRED, FIXED_OPTIONAL, RANGE, FREE, BY_SHARE (per-unit rounding),
 * and computeByShareAllocation (largest-remainder, no drift).
 */
final class CollectionsAmountCalculatorTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Carbon::setTestNow();

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeUnit(Tenant $tenant, float $ownershipShare = 1.0): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit, $ownershipShare): void {
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            $unit = Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => (string) mt_rand(1, 99999),
                'unit_status_id' => $unitStatus->id,
                'ownership_share' => $ownershipShare,
            ]);
        });

        return $unit;
    }

    /**
     * Create a ChargeConcept (identity) + ChargePolicy (config) and return the policy.
     *
     * @param  array<string, mixed>  $extra  policy amount fields (fixed_amount, min/ideal/max, prorate)
     */
    private function makePolicy(Tenant $tenant, string $amountModeId, array $extra = []): ChargePolicy
    {
        $policy = null;
        $tenant->run(function () use (&$policy, $amountModeId, $extra): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));
            $amountMode = AmountMode::findOrFail($amountModeId);

            $concept = ChargeConcept::create([
                'code' => 'TEST_'.mt_rand(1000, 99999),
                'name' => 'Test concept',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);

            $policy = ChargePolicy::create(array_merge([
                'charge_concept_id' => $concept->id,
                'name' => 'Test policy',
                'amount_mode_id' => $amountMode->id,
                'periodicity_id' => Periodicity::MONTHLY,
                'valid_from' => '2026-01-01',
                'is_active' => true,
                'grace_days' => 0,
            ], $extra));
        });

        return $policy;
    }

    // =========================================================================
    // FIXED_REQUIRED → fixed_amount
    // =========================================================================

    public function test_fixed_required_returns_fixed_amount(): void
    {
        $tenant = $this->makeTenant('FixedRequired Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $policy = $this->makePolicy($tenant, AmountMode::FIXED_REQUIRED, ['fixed_amount' => 70000]);

        $tenant->run(function () use ($policy, $unit): void {
            $calculator = app(AmountCalculator::class);
            $amount = $calculator->compute($policy, $unit);
            $this->assertSame(70000, $amount, 'FIXED_REQUIRED must return the concept fixed_amount');
            $this->assertIsInt($amount, 'Result must be PHP int');
        });
    }

    // =========================================================================
    // FIXED_OPTIONAL → fixed_amount
    // =========================================================================

    public function test_fixed_optional_returns_fixed_amount(): void
    {
        $tenant = $this->makeTenant('FixedOptional Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $policy = $this->makePolicy($tenant, AmountMode::FIXED_OPTIONAL, ['fixed_amount' => 50000]);

        $tenant->run(function () use ($policy, $unit): void {
            $amount = app(AmountCalculator::class)->compute($policy, $unit);
            $this->assertSame(50000, $amount, 'FIXED_OPTIONAL must return the concept fixed_amount');
            $this->assertIsInt($amount);
        });
    }

    // =========================================================================
    // RANGE → ideal_amount
    // =========================================================================

    public function test_range_returns_ideal_amount(): void
    {
        $tenant = $this->makeTenant('Range Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $policy = $this->makePolicy($tenant, AmountMode::RANGE, [
            'min_amount' => 30000,
            'ideal_amount' => 55000,
            'max_amount' => 90000,
        ]);

        $tenant->run(function () use ($policy, $unit): void {
            $amount = app(AmountCalculator::class)->compute($policy, $unit);
            $this->assertSame(55000, $amount, 'RANGE mode must return ideal_amount');
        });
    }

    // =========================================================================
    // FREE → 0
    // =========================================================================

    public function test_free_mode_returns_zero(): void
    {
        $tenant = $this->makeTenant('Free Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $policy = $this->makePolicy($tenant, AmountMode::FREE);

        $tenant->run(function () use ($policy, $unit): void {
            $amount = app(AmountCalculator::class)->compute($policy, $unit);
            $this->assertSame(0, $amount, 'FREE mode must always return 0');
        });
    }

    // =========================================================================
    // BY_SHARE → round(prorate * share / 100)
    // =========================================================================

    public function test_by_share_computes_correct_prorated_amount(): void
    {
        $tenant = $this->makeTenant('ByShare Tenant');
        $this->seedTenant($tenant);
        // 1.3542% share
        $unit = $this->makeUnit($tenant, 1.3542);

        $policy = $this->makePolicy($tenant, AmountMode::BY_SHARE, ['prorate_total_amount' => 1000000]);

        $tenant->run(function () use ($policy, $unit): void {
            $amount = app(AmountCalculator::class)->compute($policy, $unit);
            // Expected: round(1000000 * (1.3542 / 100)) = round(13542.0) = 13542
            $this->assertSame(13542, $amount, 'BY_SHARE must compute round(total * share / 100)');
            $this->assertIsInt($amount);
        });
    }

    public function test_by_share_different_ownership_share(): void
    {
        $tenant = $this->makeTenant('ByShare2 Tenant');
        $this->seedTenant($tenant);
        // 5.25% share
        $unit = $this->makeUnit($tenant, 5.25);

        $policy = $this->makePolicy($tenant, AmountMode::BY_SHARE, ['prorate_total_amount' => 200000]);

        $tenant->run(function () use ($policy, $unit): void {
            $amount = app(AmountCalculator::class)->compute($policy, $unit);
            // Expected: round(200000 * (5.25 / 100)) = round(10500) = 10500
            $this->assertSame(10500, $amount);
        });
    }

    // =========================================================================
    // computeByShareAllocation: 3 units with shares summing to 100 → no drift
    // =========================================================================

    public function test_compute_by_share_allocation_sums_exactly_to_prorate_total(): void
    {
        $tenant = $this->makeTenant('Allocation Tenant');
        $this->seedTenant($tenant);

        // 3 units whose shares sum EXACTLY to 100.0%
        $unitA = $this->makeUnit($tenant, 33.3333);
        $unitB = $this->makeUnit($tenant, 33.3333);
        $unitC = $this->makeUnit($tenant, 33.3334);

        $policy = $this->makePolicy($tenant, AmountMode::BY_SHARE, ['prorate_total_amount' => 100001]); // odd total → forces remainder

        $tenant->run(function () use ($policy, $unitA, $unitB, $unitC): void {
            $units = Unit::whereIn('id', [$unitA->id, $unitB->id, $unitC->id])->get();
            $allocation = app(AmountCalculator::class)->computeByShareAllocation($policy, $units);

            $this->assertCount(3, $allocation, 'Allocation must have one entry per unit');

            foreach ($allocation as $amount) {
                $this->assertIsInt($amount, 'Each allocated amount must be an integer');
            }

            $this->assertSame(
                100001,
                array_sum($allocation),
                'computeByShareAllocation must sum EXACTLY to prorate_total_amount (no drift)'
            );
        });
    }

    public function test_compute_by_share_allocation_with_even_total_no_remainder(): void
    {
        $tenant = $this->makeTenant('EvenAlloc Tenant');
        $this->seedTenant($tenant);

        // Equal thirds → no remainder problem
        $unitA = $this->makeUnit($tenant, 33.3333);
        $unitB = $this->makeUnit($tenant, 33.3333);
        $unitC = $this->makeUnit($tenant, 33.3334);

        $policy = $this->makePolicy($tenant, AmountMode::BY_SHARE, ['prorate_total_amount' => 300000]);

        $tenant->run(function () use ($policy, $unitA, $unitB, $unitC): void {
            $units = Unit::whereIn('id', [$unitA->id, $unitB->id, $unitC->id])->get();
            $allocation = app(AmountCalculator::class)->computeByShareAllocation($policy, $units);

            $this->assertSame(
                300000,
                array_sum($allocation),
                'Even total must also sum exactly to prorate_total_amount'
            );
        });
    }

    public function test_compute_by_share_allocation_all_entries_are_non_negative(): void
    {
        $tenant = $this->makeTenant('NonNeg Tenant');
        $this->seedTenant($tenant);

        $unitA = $this->makeUnit($tenant, 1.0);
        $unitB = $this->makeUnit($tenant, 49.5);
        $unitC = $this->makeUnit($tenant, 49.5);

        $policy = $this->makePolicy($tenant, AmountMode::BY_SHARE, ['prorate_total_amount' => 777777]);

        $tenant->run(function () use ($policy, $unitA, $unitB, $unitC): void {
            $units = Unit::whereIn('id', [$unitA->id, $unitB->id, $unitC->id])->get();
            $allocation = app(AmountCalculator::class)->computeByShareAllocation($policy, $units);

            foreach ($allocation as $unitId => $amount) {
                $this->assertGreaterThanOrEqual(0, $amount, "Unit {$unitId} allocation must not be negative");
            }
        });
    }
}
