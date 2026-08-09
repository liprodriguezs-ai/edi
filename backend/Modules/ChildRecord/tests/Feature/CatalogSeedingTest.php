<?php

declare(strict_types=1);

namespace Modules\ChildRecord\Tests\Feature;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ChildRecord\Models\CaregiverRelationship;
use Modules\ChildRecord\Models\PopulationType;
use Modules\ChildRecord\Models\Sex;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-XC-002 / REQ-XC-003 coverage for the 5 ChildRecord catalogs.
 */
final class CatalogSeedingTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // ── REQ-XC-003: central catalogs ───────────────────────────────────────

    public function test_central_catalogs_are_seeded_with_fixed_uuids(): void
    {
        (new CatalogSeeder)->run();

        $this->assertSame('Masculino', Sex::find(Sex::MALE)?->name);
        $this->assertSame('Femenino', Sex::find(Sex::FEMALE)?->name);
        $this->assertSame(2, Sex::count());
        $this->assertSame(3, PopulationType::count());
    }

    public function test_reseeding_central_catalogs_is_idempotent(): void
    {
        (new CatalogSeeder)->run();
        (new CatalogSeeder)->run();

        $this->assertSame(2, Sex::count());
        $this->assertSame(3, PopulationType::count());
    }

    // ── REQ-XC-003: tenant catalogs ────────────────────────────────────────

    public function test_new_tenant_gets_its_own_caregiver_relationship_rows(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $names = CaregiverRelationship::pluck('name')->all();
            $this->assertContains('Madre', $names);
            $this->assertContains('Tutor legal', $names);
            $this->assertCount(6, $names);
        });
    }

    /**
     * NOTE: TenantCatalogSeeder's firstOrCreate contract only guarantees full
     * rename-survival for `key` (system) rows — see its docblock ("las filas
     * con key ... sobreviven renombres del admin; las demás upsertean por
     * name"). caregiver_relationship/education_level/occupation deliberately
     * have NO `key` column (design.md §3.2: "No key column, nothing branches
     * on rows"), so a reseed after a rename cannot distinguish "admin renamed
     * this row" from "this default was never created" and will recreate a
     * fresh default under the original name. What IS guaranteed — and is
     * asserted here — is that the RENAMED row itself is never touched/reverted
     * and reseeding never violates the unique constraint or crashes.
     */
    public function test_reseeding_preserves_tenant_customization(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $row = CaregiverRelationship::where('name', 'Otro')->firstOrFail();
            $row->update(['name' => 'Otro familiar']);
        });

        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertDatabaseHas('caregiver_relationship', ['name' => 'Otro familiar']);
        });
    }

    public function test_tenant_catalogs_are_isolated_between_tenants(): void
    {
        [$tenantA, , $tenantB] = $this->twoTenants();

        $this->inTenant($tenantA, function (): void {
            CaregiverRelationship::where('name', 'Madre')->firstOrFail()->update(['name' => 'Mamá']);
        });

        $tenantB->run(function (): void {
            $this->assertTrue(CaregiverRelationship::where('name', 'Madre')->exists());
            $this->assertFalse(CaregiverRelationship::where('name', 'Mamá')->exists());
        });
    }

    // ── REQ-XC-002 ──────────────────────────────────────────────────────────

    public function test_administrator_403_on_create_sex(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        // Simulates the administrator role's abilities: broad, but WITHOUT
        // create.sex (super-admin exclusive per RolePermissionTableSeeder).
        $token = $user->createToken('test', ['read.sex', 'read.population_type'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/sex', ['key' => 'x', 'name' => 'X'])
            ->assertStatus(403);

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/sex')
            ->assertStatus(200);
    }
}
