<?php

declare(strict_types=1);

namespace Tests\Feature\Stimulation;

use Database\Seeders\ModuleTableSeeder;
use Database\Seeders\PermissionsTableSeeder;
use Database\Seeders\RolePermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\SystemTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Clinic\Database\Factories\HealthCenterFactory;
use Modules\Stimulation\Models\StimulationGroup;
use Modules\System\Models\System;
use QCH\Core\Models\Role;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-XC-002 / REQ-XC-003 coverage for the edi-stimulation-sessions change
 * (Phase 5 wiring).
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

    private function menuModuleEntries(array $json): array
    {
        return collect($json)
            ->flatMap(fn ($sys) => collect($sys['items'] ?? [])->flatMap(function ($item) {
                if (($item['type'] ?? null) === 'group') {
                    return collect($item['modules']);
                }

                return [$item];
            }))
            ->filter(fn ($item) => isset($item['name']))
            ->values()
            ->all();
    }

    public function test_menu_composition(): void
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
        $entries = $this->menuModuleEntries($response->json('data'));
        $names = collect($entries)->pluck('name')->all();

        foreach ([
            'Sesiones de Estimulación', 'Grupos de Estimulación',
            'Dinámicas de Estimulación', 'Zonas Corporales', 'Movimientos de Masaje',
            'Materiales Didácticos', 'Canciones', 'Tipos de Grupo',
        ] as $visible) {
            $this->assertContains($visible, $names, "{$visible} must appear in the menu.");
        }

        foreach (['Secciones de Sesión', 'Áreas de Estimulación', 'Estatus de Pago de Asistencia'] as $hidden) {
            $this->assertNotContains($hidden, $names, "{$hidden} must be hidden from the menu.");
        }
    }

    public function test_selects_contract_holds(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->inTenant($tenant, function () {
            HealthCenterFactory::defaultMunicipality();
            StimulationGroup::factory()->create();
        });

        $keys = [
            'stimulation_dynamic', 'body_zone', 'massage_movement',
            'stimulation_group_type', 'stimulation_area', 'attendance_payment_status', 'stimulation_group',
        ];
        $emptyByDesignKeys = ['didactic_material', 'song'];

        $payload = array_fill_keys(array_merge($keys, $emptyByDesignKeys), []);
        $response = $this->tenantRequest($token, $tenant)->postJson('/api/selects', $payload)->assertOk();

        foreach ($keys as $key) {
            $this->assertNotEmpty($response->json('data.'.$key), "{$key} select must not be silently empty.");
        }

        foreach ($emptyByDesignKeys as $key) {
            $this->assertIsArray($response->json('data.'.$key));
            $this->assertEmpty($response->json('data.'.$key));
        }
    }

    // ── REQ-XC-002 ──────────────────────────────────────────────────────────

    public function test_attendance_requires_the_group_permission(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['read.stimulation_group'])->plainTextToken;
        $group = $this->inTenant($tenant, fn () => StimulationGroup::factory()->create());

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_group/'.$group->id.'/attendance', [
                'session_date' => now()->toDateString(),
                'entries' => [],
            ])
            ->assertStatus(403);
    }

    public function test_tenant_admin_cannot_mutate_central_catalogs(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $user->assignRole(Role::SLUG_ADMINISTRATOR);
        $token = $user->createToken('test', $user->getPermissionsViaRoles()->pluck('name')->toArray())->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/stimulation_area', ['key' => 'x', 'name' => 'X'])
            ->assertStatus(403);

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/stimulation_area')
            ->assertStatus(200);
    }
}
