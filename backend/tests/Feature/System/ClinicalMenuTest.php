<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Database\Seeders\ModuleTableSeeder;
use Database\Seeders\PermissionsTableSeeder;
use Database\Seeders\RolePermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\SystemTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use QCH\Core\Models\Role;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * odd/tasks/edi-clinical-record.md S5 — the menu of a clinical-record system:
 * the agenda and the children first, then follow-ups, stimulation, reports and
 * configuration. The global "Prueba EDI" list is a report; "Visitas" leaves the
 * menu (visits are worked from the child record).
 */
final class ClinicalMenuTest extends TestCase
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

    /** @return array<int, array<string, mixed>> items of the clinical system */
    private function clinicalMenuItems(): array
    {
        foreach ([ModuleTableSeeder::class, SystemTableSeeder::class, PermissionsTableSeeder::class, RolesTableSeeder::class, RolePermissionTableSeeder::class] as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $user->assignRole(Role::SLUG_ADMINISTRATOR);
        $token = $user->createToken('test', $user->getPermissionsViaRoles()->pluck('name')->toArray())->plainTextToken;

        $systems = $this->tenantRequest($token, $tenant)->getJson('/api/menu')->assertOk()->json('data');
        $clinical = collect($systems)->first(fn (array $system): bool => collect($system['items'])->contains(
            fn (array $item): bool => ($item['name'] ?? null) === 'Agenda'
        ));
        $this->assertNotNull($clinical, 'The clinical system must hold the agenda.');

        return $clinical['items'];
    }

    public function test_the_agenda_and_the_children_come_first_then_the_groups(): void
    {
        $items = $this->clinicalMenuItems();

        $topLevel = collect($items)->where('type', 'module')->pluck('name')->values()->all();
        $groups = collect($items)->where('type', 'group')->pluck('label')->values()->all();

        $this->assertSame(['Agenda', 'Niños', 'Edad corregida', 'Seguimientos'], $topLevel);
        $this->assertSame(['Estimulación', 'Reportes', 'Configuración'], $groups);
    }

    public function test_the_global_test_list_is_a_report_and_visits_leave_the_menu(): void
    {
        $items = collect($this->clinicalMenuItems());
        $group = fn (string $label): array => collect($items->firstWhere('label', $label)['modules'] ?? [])->pluck('name')->all();

        $this->assertContains('Prueba EDI', $group('Reportes'));
        $this->assertContains('Dashboard', $group('Reportes'));
        $this->assertContains('Centros de Salud', $group('Configuración'));
        $this->assertContains('Profesionales de Salud', $group('Configuración'));

        $allNames = $items->flatMap(fn (array $item) => $item['type'] === 'group'
            ? collect($item['modules'])->pluck('name')
            : [$item['name']])->all();
        $this->assertNotContains('Visitas', $allNames);
    }

    public function test_every_group_shows_an_icon(): void
    {
        foreach (collect($this->clinicalMenuItems())->where('type', 'group') as $group) {
            $this->assertNotEmpty($group['icon'], "Group {$group['label']} must have an icon.");
        }
    }
}
