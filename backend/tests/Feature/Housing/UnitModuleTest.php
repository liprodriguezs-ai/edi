<?php

declare(strict_types=1);

namespace Tests\Feature\Housing;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Housing\Models\Section;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Housing\Models\Vehicle;
use Modules\Housing\Services\UnitUserService;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Integration tests for Module 2 — Housing / Units.
 *
 * Covers:
 *  - Catalog seeding (unit_type, unit_status, unit_relationship)
 *  - Unit creation with FKs + unique constraint
 *  - unit_user invariants (billing_owner, legal_rep, renter+owner)
 *  - Vehicle unique plate per tenant
 *  - Tenant isolation across all new tables
 */
final class UnitModuleTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    // =========================================================================
    // CATALOG SEEDING
    // =========================================================================

    public function test_fresh_tenant_has_three_unit_types_after_seeding(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertSame(3, UnitType::count());
            $ids = UnitType::orderBy('order')->pluck('id')->all();
            $this->assertSame([Catalog::id(UnitType::class, 'Casa'), Catalog::id(UnitType::class, 'Departamento'), Catalog::id(UnitType::class, 'Local comercial')], $ids);
        });
    }

    public function test_fresh_tenant_has_four_unit_statuses_after_seeding(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertSame(4, UnitStatus::count());
            $ids = UnitStatus::orderBy('order')->pluck('id')->all();
            $this->assertSame([
                Catalog::id(UnitStatus::class, 'Activa'),
                Catalog::id(UnitStatus::class, 'Vacante'),
                Catalog::id(UnitStatus::class, 'En obra'),
                Catalog::id(UnitStatus::class, 'Inactiva'),
            ], $ids);
        });
    }

    public function test_fresh_tenant_has_five_unit_relationships_after_seeding(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertSame(5, UnitRelationship::count());
            $ids = UnitRelationship::orderBy('order')->pluck('id')->all();
            $this->assertSame([
                UnitRelationship::OWNER,
                UnitRelationship::CO_OWNER,
                UnitRelationship::RENTER,
                UnitRelationship::FAMILY_RESIDENT,
                UnitRelationship::BILLING_CONTACT,
            ], $ids);
        });
    }

    public function test_catalog_seeder_is_idempotent(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $this->seedTenant($tenant); // run twice

        $tenant->run(function (): void {
            $this->assertSame(3, UnitType::count());
            $this->assertSame(4, UnitStatus::count());
            $this->assertSame(5, UnitRelationship::count());
        });
    }

    public function test_catalogs_are_central_shared_across_tenants(): void
    {
        // unit_type / unit_status / unit_relationship are now CENTRAL fixed-enum
        // catalogs (no tenant_id): they are seeded once and shared by every tenant,
        // NOT isolated per tenant. Both tenants must see the SAME central rows.
        $tenantA = $this->makeTenant('A');
        $tenantB = $this->makeTenant('B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $tenantA->run(function (): void {
            $this->assertSame(3, UnitType::count());
            $this->assertSame(4, UnitStatus::count());
            $this->assertSame(5, UnitRelationship::count());
        });

        $tenantB->run(function (): void {
            $this->assertSame(3, UnitType::count(), 'Tenant B sees the SAME central catalog');
            $this->assertSame(4, UnitStatus::count());
            $this->assertSame(5, UnitRelationship::count());
        });
    }

    // =========================================================================
    // UNIT CRUD + UNIQUE CONSTRAINT
    // =========================================================================

    public function test_unit_create_with_all_fks(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $section = Section::create(['name' => 'North', 'order' => 0]);
            $tower = Tower::create(['name' => 'T1', 'section_id' => $section->id, 'order' => 0]);
            $unitTypeId = Catalog::id(UnitType::class, 'Casa');
            $unitStatusId = Catalog::id(UnitStatus::class, 'Activa');

            $unit = Unit::create([
                'unit_type_id' => $unitTypeId,
                'number' => '101',
                'tower_id' => $tower->id,
                'floor' => '1',
                'section_id' => $section->id,
                'built_m2' => 120.50,
                'land_m2' => 150.00,
                'ownership_share' => 2.5000,
                'unit_status_id' => $unitStatusId,
                'notes' => 'Test unit',
            ]);

            $this->assertNotNull($unit->id);
            $this->assertSame($unitTypeId, $unit->unit_type_id);
            $this->assertSame($tower->id, $unit->tower_id);
            $this->assertSame($section->id, $unit->section_id);
            $this->assertInstanceOf(UnitType::class, $unit->unitType);
            $this->assertInstanceOf(UnitStatus::class, $unit->unitStatus);
            $this->assertInstanceOf(Tower::class, $unit->tower);
            $this->assertInstanceOf(Section::class, $unit->section);
        });
    }

    public function test_unit_unique_constraint_same_type_tower_floor_number_within_tenant(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $tower = Tower::create(['name' => 'T1', 'order' => 0]);
            $aptTypeId = Catalog::id(UnitType::class, 'Departamento');
            $activeStatusId = Catalog::id(UnitStatus::class, 'Activa');
            $vacantStatusId = Catalog::id(UnitStatus::class, 'Vacante');

            Unit::create([
                'unit_type_id' => $aptTypeId,
                'number' => '201',
                'tower_id' => $tower->id,
                'floor' => '2',
                'unit_status_id' => $activeStatusId,
            ]);

            Unit::create([
                'unit_type_id' => $aptTypeId,
                'number' => '201',
                'tower_id' => $tower->id,
                'floor' => '2',
                'unit_status_id' => $vacantStatusId,
            ]);
        });
    }

    public function test_same_unit_number_allowed_across_different_tenants(): void
    {
        $tenantA = $this->makeTenant('A');
        $tenantB = $this->makeTenant('B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $tenantA->run(function (): void {
            Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Departamento'),
                'number' => '101',
                'floor' => '1',
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);
        });

        $tenantB->run(function (): void {
            Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Departamento'),
                'number' => '101',
                'floor' => '1',
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);
            $this->assertSame(1, Unit::count());
        });
    }

    public function test_two_houses_with_same_number_and_null_tower_floor_collide(): void
    {
        // Regression: a plain Postgres UNIQUE treats NULL as distinct, so two houses
        // (tower_id/floor NULL) with the same number would NOT collide. The COALESCE
        // expression index must reject the duplicate.
        $this->expectException(UniqueConstraintViolationException::class);

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $houseTypeId = Catalog::id(UnitType::class, 'Casa');
            $statusId = Catalog::id(UnitStatus::class, 'Activa');

            Unit::create(['unit_type_id' => $houseTypeId, 'number' => '5', 'unit_status_id' => $statusId]);
            Unit::create(['unit_type_id' => $houseTypeId, 'number' => '5', 'unit_status_id' => $statusId]);
        });
    }

    public function test_soft_deleted_unit_frees_its_identity_for_reuse(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $houseTypeId = Catalog::id(UnitType::class, 'Casa');
            $statusId = Catalog::id(UnitStatus::class, 'Activa');

            $first = Unit::create(['unit_type_id' => $houseTypeId, 'number' => '7', 'unit_status_id' => $statusId]);
            $first->delete();

            // The partial index excludes soft-deleted rows, so the same identity
            // can be re-created after the original is removed.
            $second = Unit::create(['unit_type_id' => $houseTypeId, 'number' => '7', 'unit_status_id' => $statusId]);
            $this->assertNotNull($second->id);
        });
    }

    public function test_unit_tenant_isolation(): void
    {
        $tenantA = $this->makeTenant('A');
        $tenantB = $this->makeTenant('B');
        $this->seedTenant($tenantA);

        $tenantA->run(function (): void {
            Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);
        });

        $tenantB->run(function (): void {
            $this->assertSame(0, Unit::count(), 'Tenant B should not see Tenant A units');
        });
    }

    public function test_unit_soft_delete(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);

            $unit->delete();

            $this->assertSame(0, Unit::count());
            $this->assertSame(1, Unit::withTrashed()->count());
        });
    }

    // =========================================================================
    // UNIT_USER INVARIANTS
    // =========================================================================

    private function createUnit(Tenant $tenant): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);
        });

        return $unit;
    }

    public function test_setting_second_billing_owner_unsets_the_first(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->createUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $service = app(UnitUserService::class);
            $ownerRelId = UnitRelationship::OWNER;

            $userId1 = (string) Str::uuid();
            $userId2 = (string) Str::uuid();

            $first = $service->create([
                'unit_id' => $unit->id,
                'user_id' => $userId1,
                'unit_relationship_id' => $ownerRelId,
                'is_billing_owner' => true,
                'end_date' => null,
            ]);

            $this->assertTrue($first->is_billing_owner);

            $second = $service->create([
                'unit_id' => $unit->id,
                'user_id' => $userId2,
                'unit_relationship_id' => $ownerRelId,
                'is_billing_owner' => true,
                'end_date' => null,
            ]);

            $this->assertTrue($second->is_billing_owner);

            // First must have been unset
            $this->assertFalse($first->fresh()->is_billing_owner);

            // Only one active billing owner
            $count = UnitUser::where('unit_id', $unit->id)
                ->where('is_billing_owner', true)
                ->whereNull('end_date')
                ->count();
            $this->assertSame(1, $count);
        });
    }

    public function test_setting_second_legal_representative_unsets_the_first(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->createUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $service = app(UnitUserService::class);
            $ownerRelId = UnitRelationship::OWNER;

            $userId1 = (string) Str::uuid();
            $userId2 = (string) Str::uuid();

            $first = $service->create([
                'unit_id' => $unit->id,
                'user_id' => $userId1,
                'unit_relationship_id' => $ownerRelId,
                'is_legal_representative' => true,
                'end_date' => null,
            ]);

            $this->assertTrue($first->is_legal_representative);

            $second = $service->create([
                'unit_id' => $unit->id,
                'user_id' => $userId2,
                'unit_relationship_id' => $ownerRelId,
                'is_legal_representative' => true,
                'end_date' => null,
            ]);

            $this->assertTrue($second->is_legal_representative);
            $this->assertFalse($first->fresh()->is_legal_representative);
        });
    }

    public function test_renter_requires_existing_active_owner(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->createUnit($tenant);

        $this->expectException(ValidationException::class);

        $tenant->run(function () use ($unit): void {
            $service = app(UnitUserService::class);
            $renterRelId = UnitRelationship::RENTER;

            // No owner exists — this must throw
            $service->create([
                'unit_id' => $unit->id,
                'user_id' => (string) Str::uuid(),
                'unit_relationship_id' => $renterRelId,
                'end_date' => null,
            ]);
        });
    }

    public function test_renter_succeeds_when_owner_exists(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->createUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $service = app(UnitUserService::class);
            $ownerRelId = UnitRelationship::OWNER;
            $renterRelId = UnitRelationship::RENTER;

            // Create owner first
            $service->create([
                'unit_id' => $unit->id,
                'user_id' => (string) Str::uuid(),
                'unit_relationship_id' => $ownerRelId,
                'end_date' => null,
            ]);

            // Renter should succeed now
            $renter = $service->create([
                'unit_id' => $unit->id,
                'user_id' => (string) Str::uuid(),
                'unit_relationship_id' => $renterRelId,
                'end_date' => null,
            ]);

            $this->assertSame($renterRelId, $renter->unit_relationship_id);
        });
    }

    public function test_user_can_have_relationship_in_two_different_tenants(): void
    {
        $tenantA = $this->makeTenant('A');
        $tenantB = $this->makeTenant('B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $sharedUserId = (string) Str::uuid();

        $unitA = $this->createUnit($tenantA);
        $unitB = $this->createUnit($tenantB);

        $tenantA->run(function () use ($unitA, $sharedUserId): void {
            $ownerRelId = UnitRelationship::OWNER;
            UnitUser::create([
                'unit_id' => $unitA->id,
                'user_id' => $sharedUserId,
                'unit_relationship_id' => $ownerRelId,
            ]);
        });

        $tenantB->run(function () use ($unitB, $sharedUserId): void {
            $ownerRelId = UnitRelationship::OWNER;
            UnitUser::create([
                'unit_id' => $unitB->id,
                'user_id' => $sharedUserId,
                'unit_relationship_id' => $ownerRelId,
            ]);
            $this->assertSame(1, UnitUser::where('user_id', $sharedUserId)->count());
        });

        // Tenant A only sees its own assignment
        $tenantA->run(function () use ($sharedUserId): void {
            $this->assertSame(1, UnitUser::where('user_id', $sharedUserId)->count());
        });
    }

    public function test_unit_user_tenant_isolation(): void
    {
        $tenantA = $this->makeTenant('A');
        $tenantB = $this->makeTenant('B');
        $this->seedTenant($tenantA);

        $unitA = $this->createUnit($tenantA);

        $tenantA->run(function () use ($unitA): void {
            $ownerRelId = UnitRelationship::OWNER;
            UnitUser::create([
                'unit_id' => $unitA->id,
                'user_id' => (string) Str::uuid(),
                'unit_relationship_id' => $ownerRelId,
            ]);
        });

        $tenantB->run(function (): void {
            $this->assertSame(0, UnitUser::count(), 'Tenant B should not see Tenant A unit_user rows');
        });
    }

    // =========================================================================
    // VEHICLE
    // =========================================================================

    public function test_vehicle_create_with_unit(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Departamento'),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);

            $vehicle = Vehicle::create([
                'unit_id' => $unit->id,
                'plate' => 'ABC-1234',
                'make' => 'Toyota',
                'model' => 'Camry',
                'color' => 'Rojo',
                'year' => 2020,
            ]);

            $this->assertSame('ABC-1234', $vehicle->plate);
            $this->assertInstanceOf(Unit::class, $vehicle->unit);
        });
    }

    public function test_vehicle_plate_unique_per_tenant(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Departamento'),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);

            Vehicle::create(['unit_id' => $unit->id, 'plate' => 'XYZ-9999']);
            Vehicle::create(['unit_id' => $unit->id, 'plate' => 'XYZ-9999']); // must throw
        });
    }

    public function test_same_vehicle_plate_allowed_across_different_tenants(): void
    {
        $tenantA = $this->makeTenant('A');
        $tenantB = $this->makeTenant('B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $tenantA->run(function (): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Departamento'),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);
            Vehicle::create(['unit_id' => $unit->id, 'plate' => 'DUP-0001']);
        });

        $tenantB->run(function (): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Departamento'),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);
            Vehicle::create(['unit_id' => $unit->id, 'plate' => 'DUP-0001']);
            $this->assertSame(1, Vehicle::count());
        });
    }

    public function test_vehicle_tenant_isolation(): void
    {
        $tenantA = $this->makeTenant('A');
        $tenantB = $this->makeTenant('B');
        $this->seedTenant($tenantA);

        $tenantA->run(function (): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);
            Vehicle::create(['unit_id' => $unit->id, 'plate' => 'ISO-1111']);
        });

        $tenantB->run(function (): void {
            $this->assertSame(0, Vehicle::count(), 'Tenant B should not see Tenant A vehicles');
        });
    }
}
