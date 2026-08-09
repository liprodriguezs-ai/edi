<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Http\Middleware\InitializeTenancyFromUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Module\Models\Module;
use Modules\System\Models\System;
use Modules\System\Services\ModuleTenantService;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

final class MenuControllerModuleFilterTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        DB::table('module_tenant')->delete();
        DB::table('module_system')->delete();
        DB::table('system_tenant')->delete();

        parent::tearDown();
    }

    private function makeTenant(): Tenant
    {
        return Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => 'Test Tenant',
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );
    }

    public function test_menu_excludes_modules_disabled_for_tenant(): void
    {
        $tenant = $this->makeTenant();

        $system = System::factory()->create([
            'is_active' => true,
            'slug' => 'rrhh-menu-'.Str::random(4),
        ]);
        $tenant->systems()->attach($system->id, ['is_addon' => false]);

        $entityEnabled = 'menutest-enabled-'.Str::random(4);
        $entityDisabled = 'menutest-disabled-'.Str::random(4);

        $moduleEnabled = Module::factory()->create([
            'visible' => true,
            'permission_entity' => $entityEnabled,
        ]);
        $moduleDisabled = Module::factory()->create([
            'visible' => true,
            'permission_entity' => $entityDisabled,
        ]);
        $system->modules()->attach($moduleEnabled->id, ['sort_order' => 1]);
        $system->modules()->attach($moduleDisabled->id, ['sort_order' => 2]);

        $user = User::factory()->create();
        $tenant->users()->attach($user->id);

        $role = Role::create([
            'name' => 'menu-test-role-'.Str::random(4),
            'guard_name' => 'api',
        ]);

        $permEnabled = Permission::firstOrCreate(
            ['name' => "read.{$entityEnabled}", 'guard_name' => 'api'],
        );
        $permDisabled = Permission::firstOrCreate(
            ['name' => "read.{$entityDisabled}", 'guard_name' => 'api'],
        );

        $role->givePermissionTo([$permEnabled, $permDisabled]);
        $user->assignRole($role->name);

        app(ModuleTenantService::class)->setEnabled($tenant, $moduleDisabled, false);

        $response = $this->withoutMiddleware(InitializeTenancyFromUser::class)
            ->actingAs($user, 'sanctum')
            ->getJson('/api/menu');

        $response->assertStatus(200);

        $allModuleIds = collect($response->json('data'))
            ->flatMap(fn ($sys) => collect($sys['items'])->flatMap(function ($item) {
                if (($item['type'] ?? null) === 'group') {
                    return collect($item['modules'])->pluck('id');
                }

                return [$item['id'] ?? null];
            }))
            ->filter()
            ->values()
            ->all();

        $this->assertContains($moduleEnabled->id, $allModuleIds, 'Enabled module must appear in the menu.');
        $this->assertNotContains(
            $moduleDisabled->id,
            $allModuleIds,
            'Module disabled for tenant must NOT appear in the menu.'
        );
    }

    /**
     * Every module item in the menu payload — top-level and nested inside a
     * pivot group_label group (e.g. "Catálogos") — must carry the owning
     * module's `group` column, so the frontend can sub-group by module owner.
     */
    public function test_menu_module_items_include_group_field(): void
    {
        $tenant = $this->makeTenant();

        $system = System::factory()->create([
            'is_active' => true,
            'slug' => 'rrhh-menu-group-'.Str::random(4),
        ]);
        $tenant->systems()->attach($system->id, ['is_addon' => false]);

        $topLevelEntity = 'menutest-top-'.Str::random(4);
        $groupedEntity = 'menutest-grouped-'.Str::random(4);

        $topLevelModule = Module::factory()->create([
            'visible' => true,
            'permission_entity' => $topLevelEntity,
            'group' => 'Comunicación',
        ]);
        $groupedModule = Module::factory()->create([
            'visible' => true,
            'permission_entity' => $groupedEntity,
            'group' => 'RRHH',
        ]);

        $system->modules()->attach($topLevelModule->id, ['sort_order' => 1]);
        $system->modules()->attach($groupedModule->id, [
            'sort_order' => 2,
            'group_label' => 'Catálogos',
        ]);

        $user = User::factory()->create();
        $tenant->users()->attach($user->id);

        $role = Role::create([
            'name' => 'menu-group-test-role-'.Str::random(4),
            'guard_name' => 'api',
        ]);

        $permTop = Permission::firstOrCreate(['name' => "read.{$topLevelEntity}", 'guard_name' => 'api']);
        $permGrouped = Permission::firstOrCreate(['name' => "read.{$groupedEntity}", 'guard_name' => 'api']);

        $role->givePermissionTo([$permTop, $permGrouped]);
        $user->assignRole($role->name);

        $response = $this->withoutMiddleware(InitializeTenancyFromUser::class)
            ->actingAs($user, 'sanctum')
            ->getJson('/api/menu');

        $response->assertStatus(200);

        $system = collect($response->json('data'))->firstWhere('id', $system->id);
        $this->assertNotNull($system, 'System must be present in the menu payload.');

        $items = collect($system['items']);

        $topLevelItem = $items->firstWhere('id', $topLevelModule->id);
        $this->assertNotNull($topLevelItem, 'Top-level module item must be present.');
        $this->assertArrayHasKey('group', $topLevelItem);
        $this->assertSame('Comunicación', $topLevelItem['group']);

        $groupItem = $items->firstWhere('type', 'group');
        $this->assertNotNull($groupItem, 'Grouped ("Catálogos") item must be present.');
        $nestedModule = collect($groupItem['modules'])->firstWhere('id', $groupedModule->id);
        $this->assertNotNull($nestedModule, 'Nested module inside the group item must be present.');
        $this->assertArrayHasKey('group', $nestedModule);
        $this->assertSame('RRHH', $nestedModule['group']);
    }
}
