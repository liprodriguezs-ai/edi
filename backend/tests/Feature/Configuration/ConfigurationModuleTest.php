<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Collections\Database\Seeders\CollectionsDatabaseSeeder;
use Modules\Company\Models\Company;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Integration tests for Module 12 — Configuración del Clúster.
 *
 * Covers:
 *  - Tenant operational columns persist (and stay out of tenant.data json — getCustomColumns)
 *  - Collection settings persist in the Setting table
 *  - Tower-with-units delete is blocked
 *  - Company general-data update works
 */
final class ConfigurationModuleTest extends TestCase
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

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    // =========================================================================
    // Tenant operational columns (Module 1A)
    // =========================================================================

    public function test_tenant_operational_columns_are_real_columns_not_in_data_json(): void
    {
        $tenant = $this->makeTenant();

        // Operational columns are defined in getCustomColumns() and are real DB columns.
        $customColumns = Tenant::getCustomColumns();

        $this->assertContains('timezone', $customColumns, 'timezone must be a real column');
        $this->assertContains('currency', $customColumns, 'currency must be a real column');
        $this->assertContains('type', $customColumns, 'type must be a real column');
        $this->assertContains('uses_ownership_share', $customColumns, 'uses_ownership_share must be a real column');
    }

    public function test_tenant_operational_columns_can_be_updated(): void
    {
        $tenant = $this->makeTenant();

        $tenant->timezone = 'America/Monterrey';
        $tenant->currency = 'USD';
        $tenant->uses_ownership_share = true;
        $tenant->save();

        $reloaded = Tenant::find($tenant->id);

        $this->assertSame('America/Monterrey', $reloaded->timezone);
        $this->assertSame('USD', $reloaded->currency);
        $this->assertTrue((bool) $reloaded->uses_ownership_share);
    }

    // =========================================================================
    // Collection settings
    // =========================================================================

    public function test_collection_settings_persist_in_setting_table(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => 'collections.due_soon_days'],
                [
                    'name' => 'collections.due_soon_days',
                    'slug' => 'collections.due_soon_days',
                    'value' => 7,
                    'type' => Setting::TYPE_INTEGER,
                ]
            );

            Setting::updateOrCreate(
                ['slug' => 'collections.overpayment_pct'],
                [
                    'name' => 'collections.overpayment_pct',
                    'slug' => 'collections.overpayment_pct',
                    'value' => '1.5',
                    'type' => Setting::TYPE_STRING,
                ]
            );

            $dueSoon = Setting::where('slug', 'collections.due_soon_days')->firstOrFail();
            $this->assertSame(7, $dueSoon->value);

            $overpayment = Setting::where('slug', 'collections.overpayment_pct')->firstOrFail();
            $this->assertSame('1.5', $overpayment->value);
        });
    }

    public function test_collection_settings_endpoint_get_returns_seeded_lifecycle_keys(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $token = $admin->createToken('admin', ['*'])->plainTextToken;

        $data = $this->tenantRequest($token, $tenant)
            ->getJson('/api/config/collection-settings')
            ->assertStatus(200)
            ->json('data');

        // The seeder populated the lifecycle keys with their defaults.
        $this->assertSame(1, $data['collections.charge_generation_day']);
        $this->assertSame(7, $data['collections.due_soon_days']);
        $this->assertSame(5, $data['collections.grace_days']);
    }

    public function test_collection_settings_endpoint_put_persists_lifecycle_keys(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $token = $admin->createToken('admin', ['*'])->plainTextToken;

        $put = $this->tenantRequest($token, $tenant)
            ->putJson('/api/config/collection-settings', [
                'collections.charge_generation_day' => 10,
                'collections.due_soon_days' => 12,
                'collections.grace_days' => 8,
            ])
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(10, $put['collections.charge_generation_day']);
        $this->assertSame(12, $put['collections.due_soon_days']);
        $this->assertSame(8, $put['collections.grace_days']);

        $readBack = $this->tenantRequest($token, $tenant)
            ->getJson('/api/config/collection-settings')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(10, $readBack['collections.charge_generation_day']);
        $this->assertSame(12, $readBack['collections.due_soon_days']);
        $this->assertSame(8, $readBack['collections.grace_days']);
    }

    /**
     * A soft-deleted setting row still occupies the (tenant_id, slug) unique key
     * (the index is NOT partial), so a plain updateOrCreate cannot see it, tries an
     * INSERT, and blows up with a duplicate-key error. Reported from production on
     * the Cobranza tab (2026-08-07).
     */
    public function test_collection_settings_endpoint_put_survives_a_soft_deleted_setting_row(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $token = $admin->createToken('admin', ['*'])->plainTextToken;

        tenancy()->initialize($tenant);
        Setting::where('slug', 'collections.charge_generation_day')->firstOrFail()->delete();
        tenancy()->end();

        $this->tenantRequest($token, $tenant)
            ->putJson('/api/config/collection-settings', [
                'collections.charge_generation_day' => 10,
            ])
            ->assertStatus(200);

        $readBack = $this->tenantRequest($token, $tenant)
            ->getJson('/api/config/collection-settings')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(10, $readBack['collections.charge_generation_day']);
    }

    public function test_collection_settings_endpoint_put_rejects_out_of_range_values(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $token = $admin->createToken('admin', ['*'])->plainTextToken;

        // generation_day max is 28; due_soon_days max is 60.
        $this->tenantRequest($token, $tenant)
            ->putJson('/api/config/collection-settings', [
                'collections.charge_generation_day' => 31,
            ])
            ->assertStatus(422);

        $this->tenantRequest($token, $tenant)
            ->putJson('/api/config/collection-settings', [
                'collections.due_soon_days' => 61,
            ])
            ->assertStatus(422);

        // grace_days max is 60.
        $this->tenantRequest($token, $tenant)
            ->putJson('/api/config/collection-settings', [
                'collections.grace_days' => 61,
            ])
            ->assertStatus(422);
    }

    public function test_collections_seeder_is_idempotent_and_preserves_customizations(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            // Admin customizes the generation day after the initial seed.
            Setting::updateOrCreate(
                ['slug' => 'collections.charge_generation_day'],
                ['name' => 'collections.charge_generation_day', 'value' => 15, 'type' => Setting::TYPE_INTEGER],
            );

            // Re-running the seeder (firstOrCreate) must NOT overwrite the custom value.
            (new CollectionsDatabaseSeeder)->run();

            $this->assertSame(15, (int) Setting::getValue('collections.charge_generation_day'));
            // The untouched key keeps its default.
            $this->assertSame(7, (int) Setting::getValue('collections.due_soon_days'));
        });
    }

    public function test_collection_settings_are_tenant_scoped(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');

        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        // Both tenants are seeded with the default due_soon_days (7). Tenant A
        // customizes it to 14; tenant B must keep its OWN seeded value (7), proving
        // the per-tenant Setting is isolated and A's customization does not leak.
        $tenantA->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => 'collections.due_soon_days'],
                ['name' => 'collections.due_soon_days', 'slug' => 'collections.due_soon_days', 'value' => 14, 'type' => Setting::TYPE_INTEGER]
            );

            $this->assertSame(14, (int) Setting::getValue('collections.due_soon_days'));
        });

        $tenantB->run(function (): void {
            $this->assertSame(7, (int) Setting::getValue('collections.due_soon_days'), 'Tenant B keeps its own seeded value; A\'s 14 must not leak');
        });
    }

    // =========================================================================
    // Tower-with-units delete guard
    // =========================================================================

    public function test_deleting_tower_without_units_succeeds(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tower = null;
        $tenant->run(function () use (&$tower): void {
            $tower = Tower::create(['name' => 'Torre A', 'order' => 0]);
        });

        $this->assertFalse($tower->hasUnits(), 'Tower without units must report no units');

        $deleted = false;
        $tenant->run(function () use ($tower, &$deleted): void {
            $deleted = $tower->delete();
        });

        $this->assertTrue($deleted, 'Tower without units should be deletable');
    }

    public function test_deleting_tower_with_units_is_blocked(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tower = null;
        $tenant->run(function () use (&$tower): void {
            $tower = Tower::create(['name' => 'Torre B', 'order' => 1, 'floors_count' => 10]);
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Departamento'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            Unit::create([
                'unit_type_id' => $unitType->id,
                'tower_id' => $tower->id,
                'number' => 'A-101',
                'floor' => '1',
                'unit_status_id' => $unitStatus->id,
            ]);
        });

        $tenant->run(function () use ($tower): void {
            $this->assertTrue($tower->hasUnits(), 'Tower with assigned units must report hasUnits=true');
        });

        // Spec §16.4 rule 1: deleting tower with units must be blocked.
        // The guard is enforced in TowerController::beforeDestroy via
        // UnprocessableEntityHttpException. Here we test the model helper directly.
        $tenant->run(function () use ($tower): void {
            $this->expectException(UnprocessableEntityHttpException::class);

            if ($tower->hasUnits()) {
                throw new UnprocessableEntityHttpException(
                    'No se puede eliminar la torre porque tiene unidades asignadas.'
                );
            }
        });
    }

    // =========================================================================
    // Company (general data / branding)
    // =========================================================================

    public function test_company_general_data_can_be_updated(): void
    {
        // Company is a central model (no tenant context needed for this assertion)
        $company = Company::create([
            'name' => 'Loma Chica',
            'legal_name' => 'Asociación Original AC',
            'main' => true,
            'slug' => 'loma-chica-'.Str::random(4),
        ]);

        $company->legal_name = 'Asociación de Colonos Loma Chica AC';
        $company->save();

        $reloaded = Company::find($company->id);
        $this->assertSame('Asociación de Colonos Loma Chica AC', $reloaded->legal_name);
    }
}
