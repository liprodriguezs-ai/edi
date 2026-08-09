<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Module\Models\Module;
use Modules\System\Services\ModuleTenantService;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

final class TenantModuleServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        DB::table('module_tenant')->delete();

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

    public function test_is_enabled_returns_true_when_no_row_exists(): void
    {
        $tenant = $this->makeTenant();
        $module = Module::factory()->create();
        $service = app(ModuleTenantService::class);

        $this->assertTrue(
            $service->isEnabled($tenant, $module),
            'Opt-out semantics: absence of row means enabled.'
        );
    }

    public function test_is_enabled_returns_false_when_row_enabled_is_false(): void
    {
        $tenant = $this->makeTenant();
        $module = Module::factory()->create();
        $service = app(ModuleTenantService::class);

        $service->setEnabled($tenant, $module, false);

        $this->assertFalse($service->isEnabled($tenant, $module));
    }

    public function test_set_enabled_is_idempotent(): void
    {
        $tenant = $this->makeTenant();
        $module = Module::factory()->create();
        $service = app(ModuleTenantService::class);

        $service->setEnabled($tenant, $module, false);
        $service->setEnabled($tenant, $module, false);

        $count = DB::table('module_tenant')
            ->where('tenant_id', $tenant->id)
            ->where('module_id', $module->id)
            ->count();

        $this->assertSame(1, $count, 'setEnabled should not duplicate pivot rows.');
    }

    public function test_is_enabled_by_permission_entity_resolves_correctly(): void
    {
        $tenant = $this->makeTenant();
        $module = Module::factory()->create(['permission_entity' => 'workshift']);
        $service = app(ModuleTenantService::class);

        $this->assertTrue($service->isEnabledByPermissionEntity($tenant, 'workshift'));

        $service->setEnabled($tenant, $module, false);

        $this->assertFalse($service->isEnabledByPermissionEntity($tenant, 'workshift'));
    }

    public function test_is_enabled_by_unknown_permission_entity_returns_true(): void
    {
        $tenant = $this->makeTenant();
        $service = app(ModuleTenantService::class);

        $this->assertTrue(
            $service->isEnabledByPermissionEntity($tenant, 'nonexistent-entity'),
            'Unknown permission_entity must fail-open (true) to avoid lockouts.'
        );
    }

    public function test_disabled_module_ids_returns_only_disabled_rows(): void
    {
        $tenant = $this->makeTenant();
        $enabledModule = Module::factory()->create();
        $disabledModule = Module::factory()->create();
        $service = app(ModuleTenantService::class);

        $service->setEnabled($tenant, $enabledModule, true);
        $service->setEnabled($tenant, $disabledModule, false);

        $disabledIds = $service->disabledModuleIds($tenant);

        $this->assertContains($disabledModule->id, $disabledIds);
        $this->assertNotContains($enabledModule->id, $disabledIds);
    }

    public function test_sync_bulk_applies_all_entries(): void
    {
        $tenant = $this->makeTenant();
        $moduleA = Module::factory()->create();
        $moduleB = Module::factory()->create();
        $service = app(ModuleTenantService::class);

        $service->syncBulk($tenant, [
            ['module_id' => $moduleA->id, 'enabled' => false],
            ['module_id' => $moduleB->id, 'enabled' => true],
        ]);

        $this->assertFalse($service->isEnabled($tenant, $moduleA));
        $this->assertTrue($service->isEnabled($tenant, $moduleB));
    }

    /**
     * Regresion: en produccion el middleware initializeTenancy cambia la
     * conexion por defecto a la del tenant. El pivot module_tenant vive SOLO
     * en central, por lo que el servicio debe forzar la conexion central
     * independientemente de cual sea la por defecto al momento de la llamada.
     */
    public function test_service_works_when_default_connection_points_to_a_database_without_the_pivot(): void
    {
        $tenant = $this->makeTenant();
        $module = Module::factory()->create(['permission_entity' => 'regression-check']);
        $service = app(ModuleTenantService::class);

        $service->setEnabled($tenant, $module, false);

        $centralName = config('tenancy.database.central_connection', config('database.default'));
        $originalDefault = config('database.default');

        // Clonamos la conexion central en un alias 'fake-tenant' y la apuntamos
        // a una base nueva que NO tiene la tabla module_tenant.
        $centralConfig = config("database.connections.{$centralName}");
        config([
            'database.connections.fake-tenant' => array_merge($centralConfig, [
                'database' => $centralConfig['database'].'_fake_tenant_regression',
            ]),
        ]);

        try {
            config(['database.default' => 'fake-tenant']);
            DB::setDefaultConnection('fake-tenant');

            // Si el servicio no forzara la conexion central, todas estas
            // llamadas dispararian SQLSTATE[42P01] contra fake-tenant.
            $this->assertFalse($service->isEnabled($tenant, $module));
            $this->assertFalse($service->isEnabledByPermissionEntity($tenant, 'regression-check'));
            $this->assertSame([$module->id], $service->disabledModuleIds($tenant));
        } finally {
            DB::setDefaultConnection($originalDefault);
            config(['database.default' => $originalDefault]);
        }
    }
}
