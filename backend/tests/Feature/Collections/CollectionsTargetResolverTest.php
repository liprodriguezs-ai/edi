<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Collections\Services\TargetResolver;
use Modules\Housing\Models\Section;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitFeature;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\Vehicle;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * TargetResolver — comprehensive unit + integration tests.
 *
 * Covers every target_type, the billability filter, and the InvalidArgumentException
 * for unknown types.
 */
final class CollectionsTargetResolverTest extends TestCase
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

    /**
     * Create a unit with optional tower_id, floor, unit_type_id, status.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeUnit(Tenant $tenant, array $overrides = []): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit, $overrides): void {
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));

            $unit = Unit::create(array_merge([
                'unit_type_id' => $unitType->id,
                'number' => (string) mt_rand(1, 99999),
                'unit_status_id' => $unitStatus->id,
                'ownership_share' => 1.0,
            ], $overrides));
        });

        return $unit;
    }

    private function makeUnitWithStatus(Tenant $tenant, string $statusId, array $overrides = []): Unit
    {
        return $this->makeUnit($tenant, array_merge(['unit_status_id' => $statusId], $overrides));
    }

    private function makeTower(Tenant $tenant): Tower
    {
        $tower = null;
        $tenant->run(function () use (&$tower): void {
            $tower = Tower::create([
                'name' => 'Torre '.mt_rand(1, 999),
                'floors_count' => 5,
            ]);
        });

        return $tower;
    }

    private function makeSection(Tenant $tenant): Section
    {
        $section = null;
        $tenant->run(function () use (&$section): void {
            $section = Section::create([
                'name' => 'Seccion '.mt_rand(1, 999),
            ]);
        });

        return $section;
    }

    private function makeUnitFeature(Tenant $tenant, string $name): UnitFeature
    {
        $feature = null;
        $tenant->run(function () use (&$feature, $name): void {
            $feature = UnitFeature::create([
                'name' => $name,
                'is_active' => true,
            ]);
        });

        return $feature;
    }

    // =========================================================================
    // by_type — only units of the matching type
    // =========================================================================

    public function test_by_type_only_returns_units_of_the_specified_type(): void
    {
        $tenant = $this->makeTenant('ByType Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $casaType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $dptoType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Departamento'));
            $activeStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));

            // 2 Casas + 1 Departamento
            $casa1 = Unit::create(['unit_type_id' => $casaType->id, 'number' => 'C1', 'unit_status_id' => $activeStatus->id, 'ownership_share' => 1.0]);
            $casa2 = Unit::create(['unit_type_id' => $casaType->id, 'number' => 'C2', 'unit_status_id' => $activeStatus->id, 'ownership_share' => 1.0]);
            $dpto = Unit::create(['unit_type_id' => $dptoType->id, 'number' => 'D1', 'unit_status_id' => $activeStatus->id, 'ownership_share' => 1.0]);

            $resolver = app(TargetResolver::class);
            $result = $resolver->resolve('by_type', ['unit_type_ids' => [$casaType->id]]);

            $ids = $result->pluck('id')->sort()->values()->all();
            $this->assertContains($casa1->id, $ids);
            $this->assertContains($casa2->id, $ids);
            $this->assertNotContains($dpto->id, $ids, 'Departamento must not be in by_type Casa result');
        });
    }

    // =========================================================================
    // by_tower — only units in that tower
    // =========================================================================

    public function test_by_tower_only_returns_units_in_the_specified_tower(): void
    {
        $tenant = $this->makeTenant('ByTower Tenant');
        $this->seedTenant($tenant);

        $tower = $this->makeTower($tenant);

        $tenant->run(function () use ($tower): void {
            $casaType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $activeStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));

            $inTower = Unit::create(['unit_type_id' => $casaType->id, 'number' => 'T1', 'unit_status_id' => $activeStatus->id, 'ownership_share' => 1.0, 'tower_id' => $tower->id]);
            $noTower = Unit::create(['unit_type_id' => $casaType->id, 'number' => 'N1', 'unit_status_id' => $activeStatus->id, 'ownership_share' => 1.0]);

            $resolver = app(TargetResolver::class);
            $result = $resolver->resolve('by_tower', ['tower_ids' => [$tower->id]]);

            $ids = $result->pluck('id')->all();
            $this->assertContains($inTower->id, $ids, 'Unit in the tower must be included');
            $this->assertNotContains($noTower->id, $ids, 'Unit without tower must be excluded');
        });
    }

    // =========================================================================
    // by_floor — tower + floor combination
    // =========================================================================

    public function test_by_floor_matches_tower_and_floor_combination(): void
    {
        $tenant = $this->makeTenant('ByFloor Tenant');
        $this->seedTenant($tenant);

        $tower = $this->makeTower($tenant);

        $tenant->run(function () use ($tower): void {
            $dptoType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Departamento'));
            $activeStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));

            $floor2 = Unit::create(['unit_type_id' => $dptoType->id, 'number' => '201', 'unit_status_id' => $activeStatus->id, 'ownership_share' => 1.0, 'tower_id' => $tower->id, 'floor' => '2']);
            $floor3 = Unit::create(['unit_type_id' => $dptoType->id, 'number' => '301', 'unit_status_id' => $activeStatus->id, 'ownership_share' => 1.0, 'tower_id' => $tower->id, 'floor' => '3']);
            $noTower = Unit::create(['unit_type_id' => $dptoType->id, 'number' => '999', 'unit_status_id' => $activeStatus->id, 'ownership_share' => 1.0, 'floor' => '2']);

            $resolver = app(TargetResolver::class);
            $result = $resolver->resolve('by_floor', ['tower_ids' => [$tower->id], 'floors' => ['2']]);

            $ids = $result->pluck('id')->all();
            $this->assertContains($floor2->id, $ids, 'Floor 2 in tower must match');
            $this->assertNotContains($floor3->id, $ids, 'Floor 3 must not match');
            $this->assertNotContains($noTower->id, $ids, 'Floor 2 without matching tower must not match');
        });
    }

    // =========================================================================
    // by_floor without tower — floor applies across ALL towers
    // =========================================================================

    public function test_by_floor_without_tower_matches_floor_across_all_towers(): void
    {
        $tenant = $this->makeTenant('ByFloorNoTower Tenant');
        $this->seedTenant($tenant);

        $towerA = $this->makeTower($tenant);
        $towerB = $this->makeTower($tenant);

        $tenant->run(function () use ($towerA, $towerB): void {
            $dptoType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Departamento'));
            $activeStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));

            $a2 = Unit::create(['unit_type_id' => $dptoType->id, 'number' => 'A201', 'unit_status_id' => $activeStatus->id, 'ownership_share' => 1.0, 'tower_id' => $towerA->id, 'floor' => '2']);
            $b2 = Unit::create(['unit_type_id' => $dptoType->id, 'number' => 'B201', 'unit_status_id' => $activeStatus->id, 'ownership_share' => 1.0, 'tower_id' => $towerB->id, 'floor' => '2']);
            $a3 = Unit::create(['unit_type_id' => $dptoType->id, 'number' => 'A301', 'unit_status_id' => $activeStatus->id, 'ownership_share' => 1.0, 'tower_id' => $towerA->id, 'floor' => '3']);

            $resolver = app(TargetResolver::class);
            // No tower_ids → floor 2 across every tower (matches the charge form, which
            // only collects the floor number).
            $result = $resolver->resolve('by_floor', ['floors' => ['2']]);

            $ids = $result->pluck('id')->all();
            $this->assertContains($a2->id, $ids, 'Floor 2 in tower A must match');
            $this->assertContains($b2->id, $ids, 'Floor 2 in tower B must match');
            $this->assertNotContains($a3->id, $ids, 'Floor 3 must not match');
        });
    }

    // =========================================================================
    // manual — exact unit ids
    // =========================================================================

    public function test_manual_returns_exactly_the_specified_units(): void
    {
        $tenant = $this->makeTenant('Manual Tenant');
        $this->seedTenant($tenant);

        $unitA = $this->makeUnit($tenant);
        $unitB = $this->makeUnit($tenant);
        $unitC = $this->makeUnit($tenant);

        $tenant->run(function () use ($unitA, $unitB, $unitC): void {
            $resolver = app(TargetResolver::class);
            $result = $resolver->resolve('manual', ['unit_ids' => [$unitA->id, $unitC->id]]);

            $ids = $result->pluck('id')->all();
            $this->assertContains($unitA->id, $ids);
            $this->assertContains($unitC->id, $ids);
            $this->assertNotContains($unitB->id, $ids, 'Unit B was not in the manual list');
        });
    }

    // =========================================================================
    // by_feature — INTERSECTION (unit must have ALL features)
    // =========================================================================

    public function test_by_feature_returns_only_units_with_all_requested_features(): void
    {
        $tenant = $this->makeTenant('ByFeature Tenant');
        $this->seedTenant($tenant);

        $featurePool = $this->makeUnitFeature($tenant, 'Alberca');
        $featureGym = $this->makeUnitFeature($tenant, 'Gym');

        $unitBoth = $this->makeUnit($tenant);
        $unitPool = $this->makeUnit($tenant);  // only pool
        $unitNeither = $this->makeUnit($tenant); // no features

        $tenant->run(function () use ($unitBoth, $unitPool, $unitNeither, $featurePool, $featureGym): void {
            // Attach features
            $unitBoth->features()->attach([$featurePool->id, $featureGym->id]);
            $unitPool->features()->attach([$featurePool->id]);

            $resolver = app(TargetResolver::class);
            // BOTH features required — intersection
            $result = $resolver->resolve('by_feature', ['unit_feature_ids' => [$featurePool->id, $featureGym->id]]);

            $ids = $result->pluck('id')->all();
            $this->assertContains($unitBoth->id, $ids, 'Unit with BOTH features must be included');
            $this->assertNotContains($unitPool->id, $ids, 'Unit with only one feature must be excluded (intersection)');
            $this->assertNotContains($unitNeither->id, $ids, 'Unit with no features must be excluded');
        });
    }

    // =========================================================================
    // single_unit — returns exactly that one unit
    // =========================================================================

    public function test_single_unit_returns_exactly_one_unit(): void
    {
        $tenant = $this->makeTenant('SingleUnit Tenant');
        $this->seedTenant($tenant);

        $targetUnit = $this->makeUnit($tenant);
        $otherUnit = $this->makeUnit($tenant);

        $tenant->run(function () use ($targetUnit): void {
            $resolver = app(TargetResolver::class);
            $result = $resolver->resolve('single_unit', ['unit_id' => $targetUnit->id]);

            $this->assertCount(1, $result);
            $this->assertSame($targetUnit->id, $result->first()->id);
        });
    }

    // =========================================================================
    // unknown type — throws InvalidArgumentException
    // =========================================================================

    public function test_unknown_target_type_throws_invalid_argument_exception(): void
    {
        $tenant = $this->makeTenant('UnknownType Tenant');
        $this->seedTenant($tenant);

        $this->expectException(\InvalidArgumentException::class);

        $tenant->run(function (): void {
            $resolver = app(TargetResolver::class);
            $resolver->resolve('completely_made_up_type', null);
        });
    }

    // =========================================================================
    // billability: by_type targeting an Inactiva unit must exclude it
    // =========================================================================

    public function test_by_type_excludes_inactive_unit(): void
    {
        $tenant = $this->makeTenant('Billability Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $casaType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $activeStatusId = Catalog::id(UnitStatus::class, 'Activa');
            $inactiveStatusId = Catalog::id(UnitStatus::class, 'Inactiva');

            $active = Unit::create(['unit_type_id' => $casaType->id, 'number' => 'ACT', 'unit_status_id' => $activeStatusId, 'ownership_share' => 1.0]);
            $inactive = Unit::create(['unit_type_id' => $casaType->id, 'number' => 'INC', 'unit_status_id' => $inactiveStatusId, 'ownership_share' => 1.0]);

            $resolver = app(TargetResolver::class);
            $result = $resolver->resolve('by_type', ['unit_type_ids' => [$casaType->id]]);

            $ids = $result->pluck('id')->all();
            $this->assertContains($active->id, $ids, 'Active unit must be included');
            $this->assertNotContains($inactive->id, $ids, 'Inactive unit must be excluded even when of the right type');
        });
    }

    // =========================================================================
    // billability: single_unit targeting an Inactiva unit returns empty set
    // =========================================================================

    public function test_single_unit_targeting_inactive_unit_returns_empty_collection(): void
    {
        $tenant = $this->makeTenant('SingleInactive Tenant');
        $this->seedTenant($tenant);

        $inactiveStatusId = Catalog::id(UnitStatus::class, 'Inactiva');
        $inactiveUnit = $this->makeUnitWithStatus($tenant, $inactiveStatusId);

        $tenant->run(function () use ($inactiveUnit): void {
            $resolver = app(TargetResolver::class);
            $result = $resolver->resolve('single_unit', ['unit_id' => $inactiveUnit->id]);

            $this->assertCount(0, $result, 'Targeting an inactive unit via single_unit must return an empty collection');
        });
    }

    // =========================================================================
    // billability: manual targeting mixed active+inactive skips the inactive one
    // =========================================================================

    public function test_manual_targeting_inactive_unit_silently_excludes_it(): void
    {
        $tenant = $this->makeTenant('ManualInactive Tenant');
        $this->seedTenant($tenant);

        $activeUnit = $this->makeUnit($tenant);
        $inactiveUnit = $this->makeUnitWithStatus($tenant, Catalog::id(UnitStatus::class, 'Inactiva'));

        $tenant->run(function () use ($activeUnit, $inactiveUnit): void {
            $resolver = app(TargetResolver::class);
            $result = $resolver->resolve('manual', ['unit_ids' => [$activeUnit->id, $inactiveUnit->id]]);

            $ids = $result->pluck('id')->all();
            $this->assertContains($activeUnit->id, $ids, 'Active unit must be included');
            $this->assertNotContains($inactiveUnit->id, $ids, 'Inactive unit must be silently excluded');
        });
    }

    // =========================================================================
    // by_section — only units whose OWN section_id matches (never inherited
    // from the unit's tower)
    // =========================================================================

    public function test_by_section_only_returns_units_with_a_direct_section_id(): void
    {
        $tenant = $this->makeTenant('BySection Tenant');
        $this->seedTenant($tenant);

        $section = $this->makeSection($tenant);

        $tenant->run(function () use ($section): void {
            $casaType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $activeStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));

            // The tower belongs to the section, but the unit itself carries no
            // section_id — inheriting the section from the tower is NOT the
            // contract, so this unit must be excluded.
            $towerInSection = Tower::create(['name' => 'Torre En Seccion', 'floors_count' => 5, 'section_id' => $section->id]);

            $direct = Unit::create(['unit_type_id' => $casaType->id, 'number' => 'S1', 'unit_status_id' => $activeStatus->id, 'ownership_share' => 1.0, 'section_id' => $section->id]);
            $viaTowerOnly = Unit::create(['unit_type_id' => $casaType->id, 'number' => 'S2', 'unit_status_id' => $activeStatus->id, 'ownership_share' => 1.0, 'tower_id' => $towerInSection->id]);
            $outside = Unit::create(['unit_type_id' => $casaType->id, 'number' => 'S3', 'unit_status_id' => $activeStatus->id, 'ownership_share' => 1.0]);

            $resolver = app(TargetResolver::class);
            $result = $resolver->resolve('by_section', ['section_ids' => [$section->id]]);

            $ids = $result->pluck('id')->all();
            $this->assertContains($direct->id, $ids, 'Unit with a direct section_id must be included');
            $this->assertNotContains($viaTowerOnly->id, $ids, 'A unit whose TOWER belongs to the section but has no own section_id must NOT be included');
            $this->assertNotContains($outside->id, $ids, 'Unit outside the section must be excluded');
        });
    }

    public function test_by_section_with_empty_or_null_selector_returns_empty_collection(): void
    {
        $tenant = $this->makeTenant('BySectionEmpty Tenant');
        $this->seedTenant($tenant);

        $this->makeUnit($tenant);

        $tenant->run(function (): void {
            $resolver = app(TargetResolver::class);

            // Mirrors by_tower/by_type/manual: an empty/absent selector resolves to
            // zero units — NOT an exception (the only thrown case is an unknown
            // target_type).
            $this->assertCount(0, $resolver->resolve('by_section', ['section_ids' => []]));
            $this->assertCount(0, $resolver->resolve('by_section', null));
        });
    }

    // =========================================================================
    // with_vehicles — billable units with at least one live vehicle
    // =========================================================================

    public function test_with_vehicles_includes_only_units_with_a_live_vehicle(): void
    {
        $tenant = $this->makeTenant('WithVehicles Tenant');
        $this->seedTenant($tenant);

        $withVehicle = $this->makeUnit($tenant);
        $withoutVehicle = $this->makeUnit($tenant);
        $withSoftDeletedVehicleOnly = $this->makeUnit($tenant);

        $tenant->run(function () use ($withVehicle, $withoutVehicle, $withSoftDeletedVehicleOnly): void {
            Vehicle::create(['unit_id' => $withVehicle->id, 'plate' => 'ABC-123']);

            $deleted = Vehicle::create(['unit_id' => $withSoftDeletedVehicleOnly->id, 'plate' => 'DEF-456']);
            $deleted->delete();

            $resolver = app(TargetResolver::class);
            $result = $resolver->resolve('with_vehicles', null);

            $ids = $result->pluck('id')->all();
            $this->assertContains($withVehicle->id, $ids, 'Unit with a live vehicle must be included');
            $this->assertNotContains($withoutVehicle->id, $ids, 'Unit without any vehicle must be excluded');
            $this->assertNotContains($withSoftDeletedVehicleOnly->id, $ids, 'Unit whose only vehicle is soft-deleted must be excluded');
        });
    }

    public function test_with_vehicles_excludes_non_billable_unit_even_with_a_live_vehicle(): void
    {
        $tenant = $this->makeTenant('WithVehiclesBillability Tenant');
        $this->seedTenant($tenant);

        $inactiveUnit = $this->makeUnitWithStatus($tenant, Catalog::id(UnitStatus::class, 'Inactiva'));

        $tenant->run(function () use ($inactiveUnit): void {
            Vehicle::create(['unit_id' => $inactiveUnit->id, 'plate' => 'GHI-789']);

            $resolver = app(TargetResolver::class);
            $result = $resolver->resolve('with_vehicles', null);

            $this->assertNotContains($inactiveUnit->id, $result->pluck('id')->all(), 'Non-billable unit must be excluded even with a live vehicle');
        });
    }
}
