<?php

declare(strict_types=1);

namespace Tests\Feature\ChildRecord;

use Database\Seeders\ModuleTableSeeder;
use Database\Seeders\PermissionsTableSeeder;
use Database\Seeders\RolePermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\SystemTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\ChildRecord\Models\Sex;
use Modules\Clinic\Database\Factories\HealthCenterFactory;
use Modules\Clinic\Models\HealthCenter;
use Modules\Clinic\Models\PractitionerProfile;
use Modules\System\Models\System;
use QCH\Core\Models\Role;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-XC-004 — module registration and menu coverage for the
 * edi-clinic-and-child-registry change (Phase 5 wiring). Runs the real
 * module/permission/role seed chain (WithTenantSetup::seedTenant() alone only
 * covers per-tenant catalogs, not the platform module registry).
 */
final class ModuleRegistrationTest extends TestCase
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

    private function seedPlatformRegistry(): void
    {
        Artisan::call('db:seed', ['--class' => ModuleTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => SystemTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => PermissionsTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => RolesTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => RolePermissionTableSeeder::class, '--force' => true]);
    }

    public function test_menu_shows_visible_modules_and_hides_the_rest(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $evaluacion = System::where('slug', 'evaluacion')->firstOrFail();
        $tenant->systems()->attach($evaluacion->id, ['is_addon' => false]);

        $user = $this->makeUser($tenant);
        $user->assignRole(Role::SLUG_ADMINISTRATOR);
        $token = $user->createToken('test', $user->getPermissionsViaRoles()->pluck('name')->toArray())->plainTextToken;

        $response = $this->tenantRequest($token, $tenant)->getJson('/api/menu')->assertOk();

        $moduleNames = collect($response->json('data'))
            ->flatMap(fn ($sys) => collect($sys['items'] ?? [])->flatMap(function ($item) {
                if (($item['type'] ?? null) === 'group') {
                    return collect($item['modules'])->pluck('name');
                }

                return [$item['name'] ?? null];
            }))
            ->filter()
            ->values()
            ->all();

        foreach (['Centros de Salud', 'Profesionales de Salud', 'Niños', 'Visitas'] as $visible) {
            $this->assertContains($visible, $moduleNames, "{$visible} must appear in the menu.");
        }

        foreach (['Cuidadores', 'Sexos', 'Tipos de Población'] as $hidden) {
            $this->assertNotContains($hidden, $moduleNames, "{$hidden} must be hidden from the menu.");
        }
    }

    public function test_selects_contract_returns_non_empty_arrays_for_all_seven_catalog_types(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        // Seed one row per Clinic catalog so those two selects are non-empty too
        // (sex/population_type/caregiver_relationship/education_level/occupation
        // already come from CatalogSeeder/TenantCatalogSeeder via seedTenant()).
        $this->inTenant($tenant, function (): void {
            $municipality = HealthCenterFactory::defaultMunicipality();
            $healthCenter = HealthCenter::factory()->create(['municipality_id' => $municipality->id]);
            PractitionerProfile::factory()->create(['health_center_id' => $healthCenter->id]);
        });

        $keys = ['sex', 'population_type', 'caregiver_relationship', 'education_level', 'occupation', 'health_center', 'practitioner_profile'];
        $payload = array_fill_keys($keys, []);

        $response = $this->tenantRequest($token, $tenant)->postJson('/api/selects', $payload)->assertOk();

        foreach ($keys as $key) {
            $this->assertNotEmpty($response->json('data.'.$key), "{$key} select must not be silently empty.");
        }
    }

    public function test_sex_catalog_id_resolves_via_selects(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $response = $this->tenantRequest($token, $tenant)->postJson('/api/selects', ['sex' => []])->assertOk();

        $ids = collect($response->json('data.sex'))->pluck('id')->all();
        $this->assertContains(Sex::MALE, $ids);
        $this->assertContains(Sex::FEMALE, $ids);
    }
}
