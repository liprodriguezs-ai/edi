<?php

declare(strict_types=1);

namespace Tests\Feature\Housing;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Housing\Models\Section;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\UnitFeature;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

/**
 * Part C: Integration tests for Housing module — tenant isolation,
 * unique-per-tenant constraint, and basic CRUD.
 */
final class HousingTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private function makeTenant(string $name = 'Test Tenant'): Tenant
    {
        return Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );
    }

    // -------------------------------------------------------------------------
    // Section: tenant isolation
    // -------------------------------------------------------------------------

    public function test_section_created_in_tenant_a_is_not_visible_in_tenant_b(): void
    {
        $tenantA = $this->makeTenant('Tenant A');
        $tenantB = $this->makeTenant('Tenant B');

        $tenantA->run(function (): void {
            Section::create(['name' => 'North Wing', 'order' => 0]);
        });

        $tenantB->run(function (): void {
            $this->assertSame(0, Section::count(), 'Section from Tenant A must not be visible in Tenant B.');
        });
    }

    public function test_tower_created_in_tenant_a_is_not_visible_in_tenant_b(): void
    {
        $tenantA = $this->makeTenant('Tenant A');
        $tenantB = $this->makeTenant('Tenant B');

        $tenantA->run(function (): void {
            Tower::create(['name' => 'Tower 1', 'order' => 0]);
        });

        $tenantB->run(function (): void {
            $this->assertSame(0, Tower::count(), 'Tower from Tenant A must not be visible in Tenant B.');
        });
    }

    public function test_unit_feature_created_in_tenant_a_is_not_visible_in_tenant_b(): void
    {
        $tenantA = $this->makeTenant('Tenant A');
        $tenantB = $this->makeTenant('Tenant B');

        $tenantA->run(function (): void {
            UnitFeature::create(['name' => 'Cochera doble', 'is_active' => true]);
        });

        $tenantB->run(function (): void {
            $this->assertSame(0, UnitFeature::count(), 'UnitFeature from Tenant A must not be visible in Tenant B.');
        });
    }

    // -------------------------------------------------------------------------
    // Section: unique-per-tenant constraints
    // -------------------------------------------------------------------------

    public function test_same_section_name_is_allowed_across_different_tenants(): void
    {
        $tenantA = $this->makeTenant('Tenant A');
        $tenantB = $this->makeTenant('Tenant B');

        $tenantA->run(function (): void {
            Section::create(['name' => 'North Wing', 'order' => 0]);
        });

        // Must not throw; constraint is per-tenant so the same name is fine in B.
        $tenantB->run(function (): void {
            Section::create(['name' => 'North Wing', 'order' => 0]);
            $this->assertSame(1, Section::count());
        });
    }

    public function test_duplicate_section_name_within_same_tenant_violates_unique_constraint(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        $tenant = $this->makeTenant();

        $tenant->run(function (): void {
            Section::create(['name' => 'North Wing', 'order' => 0]);
            Section::create(['name' => 'North Wing', 'order' => 1]); // must throw
        });
    }

    public function test_same_tower_name_is_allowed_across_different_tenants(): void
    {
        $tenantA = $this->makeTenant('Tenant A');
        $tenantB = $this->makeTenant('Tenant B');

        $tenantA->run(function (): void {
            Tower::create(['name' => 'Tower 1', 'order' => 0]);
        });

        $tenantB->run(function (): void {
            Tower::create(['name' => 'Tower 1', 'order' => 0]);
            $this->assertSame(1, Tower::count());
        });
    }

    public function test_duplicate_tower_name_within_same_tenant_violates_unique_constraint(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        $tenant = $this->makeTenant();

        $tenant->run(function (): void {
            Tower::create(['name' => 'Tower 1', 'order' => 0]);
            Tower::create(['name' => 'Tower 1', 'order' => 1]); // must throw
        });
    }

    public function test_same_unit_feature_name_is_allowed_across_different_tenants(): void
    {
        $tenantA = $this->makeTenant('Tenant A');
        $tenantB = $this->makeTenant('Tenant B');

        $tenantA->run(function (): void {
            UnitFeature::create(['name' => 'Cochera doble', 'is_active' => true]);
        });

        $tenantB->run(function (): void {
            UnitFeature::create(['name' => 'Cochera doble', 'is_active' => true]);
            $this->assertSame(1, UnitFeature::count());
        });
    }

    public function test_duplicate_unit_feature_name_within_same_tenant_violates_unique_constraint(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        $tenant = $this->makeTenant();

        $tenant->run(function (): void {
            UnitFeature::create(['name' => 'Cochera doble', 'is_active' => true]);
            UnitFeature::create(['name' => 'Cochera doble', 'is_active' => false]); // must throw
        });
    }

    // -------------------------------------------------------------------------
    // Section: basic CRUD via model
    // -------------------------------------------------------------------------

    public function test_section_crud_round_trip(): void
    {
        $tenant = $this->makeTenant();

        $tenant->run(function (): void {
            $section = Section::create(['name' => 'Alpha', 'order' => 1]);

            $this->assertNotNull($section->id);
            $this->assertSame('Alpha', $section->name);
            $this->assertSame(1, $section->order);

            $section->update(['name' => 'Alpha Updated']);
            $section->refresh();

            $this->assertSame('Alpha Updated', $section->name);

            $section->delete();
            $this->assertSame(0, Section::count());
            $this->assertSame(1, Section::withTrashed()->count(), 'Soft delete should retain the row.');
        });
    }

    public function test_tower_belongs_to_section(): void
    {
        $tenant = $this->makeTenant();

        $tenant->run(function (): void {
            $section = Section::create(['name' => 'Zone A', 'order' => 0]);
            $tower = Tower::create(['name' => 'T1', 'section_id' => $section->id, 'order' => 0]);

            $tower->refresh();
            $this->assertSame($section->id, $tower->section_id);
            $this->assertInstanceOf(Section::class, $tower->section);
            $this->assertSame('Zone A', $tower->section->name);
        });
    }

    public function test_unit_feature_crud_round_trip(): void
    {
        $tenant = $this->makeTenant();

        $tenant->run(function (): void {
            $feature = UnitFeature::create(['name' => 'Roof Terrace', 'is_active' => true]);

            $this->assertTrue($feature->is_active);

            $feature->update(['is_active' => false]);
            $feature->refresh();

            $this->assertFalse($feature->is_active);
        });
    }
}
