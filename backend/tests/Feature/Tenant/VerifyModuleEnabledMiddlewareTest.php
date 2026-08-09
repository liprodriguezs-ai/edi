<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Http\Middleware\InitializeTenancyFromUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Module\Models\Module;
use Modules\System\Services\ModuleTenantService;
use Modules\Tenant\Models\Tenant;
use PHPUnit\Framework\Attributes\DataProvider;
use QCH\Core\Models\Role;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

final class VerifyModuleEnabledMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private string $entity;

    private string $route;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entity = 'mwtest-'.Str::random(8);
        $this->route = '/api/_test/module-gate/'.$this->entity;

        Route::get($this->route, fn () => response()->json(['ok' => true]))
            ->middleware(['auth:sanctum', 'moduleEnabled:'.$this->entity]);
    }

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

    public function test_allows_when_no_tenant_initialized_and_user_has_no_tenant(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson($this->route)
            ->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    public function test_allows_when_module_is_not_disabled(): void
    {
        Module::factory()->create(['permission_entity' => $this->entity]);
        $tenant = $this->makeTenant();

        $user = User::factory()->create();
        $tenant->users()->attach($user->id);

        $this->withoutMiddleware(InitializeTenancyFromUser::class)
            ->actingAs($user, 'sanctum')
            ->getJson($this->route)
            ->assertStatus(200);
    }

    public function test_returns_403_when_module_is_disabled_for_tenant(): void
    {
        $module = Module::factory()->create(['permission_entity' => $this->entity]);
        $tenant = $this->makeTenant();

        $user = User::factory()->create();
        $tenant->users()->attach($user->id);

        $service = app(ModuleTenantService::class);
        $service->setEnabled($tenant, $module, false);

        $this->assertFalse($service->isEnabled($tenant, $module), 'pre-condition: disabled row written');
        $this->assertFalse($service->isEnabledByPermissionEntity($tenant, $this->entity), 'pre-condition: resolves by permission_entity');
        $this->assertSame(1, $user->fresh()->tenants()->count(), 'pre-condition: user belongs to tenant');

        $response = $this->withoutMiddleware(InitializeTenancyFromUser::class)
            ->actingAs($user, 'sanctum')
            ->getJson($this->route);

        $response->assertStatus(403);
        $response->assertJson(['code' => 'MODULE_DISABLED']);
    }

    public function test_super_admin_bypasses_the_gate_even_when_module_disabled(): void
    {
        $module = Module::factory()->create(['permission_entity' => $this->entity]);
        $tenant = $this->makeTenant();

        $user = User::factory()->create();
        $tenant->users()->attach($user->id);

        $role = Role::firstOrNew(['uuid' => Role::SUPER_ADMIN]);
        $role->name = Role::SLUG_SUPER_ADMIN;
        $role->guard_name = 'api';
        $role->save();
        $user->assignRole(Role::SLUG_SUPER_ADMIN);

        app(ModuleTenantService::class)->setEnabled($tenant, $module, false);

        $this->withoutMiddleware(InitializeTenancyFromUser::class)
            ->actingAs($user, 'sanctum')
            ->getJson($this->route)
            ->assertStatus(200);
    }

    /**
     * The four billable modules (in billing_package_module) must carry the
     * moduleEnabled gate with their real permission_entity, so a disabled module
     * blocks its whole API — not just the menu. Proves the route wiring for every
     * billable module, not just the middleware logic (covered above on a synthetic
     * route). Route name → expected gate entity.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function billableRouteProvider(): array
    {
        return [
            'Cobros (Collections)' => ['api.charge_batch.index', 'charge_batch'],
            'Avisos (Announcements)' => ['api.announcement.index', 'announcement'],
            'Control de Accesos (Access)' => ['api.access_log.index', 'access_log'],
            'Paquetería (Package)' => ['api.package.index', 'package'],
        ];
    }

    #[DataProvider('billableRouteProvider')]
    public function test_billable_module_route_is_gated_by_module_enabled(string $routeName, string $entity): void
    {
        $route = Route::getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "Route {$routeName} must be registered");

        $gated = collect($route->gatherMiddleware())->contains(
            fn (string $middleware): bool => str_contains($middleware, 'moduleEnabled:'.$entity)
                || str_contains($middleware, 'VerifyModuleEnabled:'.$entity),
        );

        $this->assertTrue(
            $gated,
            "Route {$routeName} must be gated by moduleEnabled:{$entity}. Middleware: ".implode(', ', $route->gatherMiddleware()),
        );
    }

    public function test_disabled_billable_module_returns_403_on_a_real_endpoint(): void
    {
        // End-to-end proof on a REAL billable endpoint: the moduleEnabled gate runs
        // BEFORE the Orion controller, so a disabled "Cobros" module blocks
        // GET /api/charge_batch with MODULE_DISABLED regardless of token abilities.
        //
        // The shared test DB (and a real seeded catalog) can hold MORE than one
        // `module` row for the same permission_entity: the 'Cobros' seeded by the
        // system catalog PLUS this factory row. The gate must resolve the entity
        // consistently — disabling the row the tenant holds has to block the whole
        // permission_entity, not be masked by a sibling row that carries no flag.
        // A sibling row is created first on purpose so an ambiguous ->first() would
        // resolve the WRONG (still-enabled) module and let the request slip through.
        Module::factory()->create(['name' => 'Cobros (seeded)', 'permission_entity' => 'charge_batch']);
        $module = Module::factory()->create(['name' => 'Cobros', 'permission_entity' => 'charge_batch']);
        $tenant = $this->makeTenant();

        $user = User::factory()->create();
        $tenant->users()->attach($user->id);

        app(ModuleTenantService::class)->setEnabled($tenant, $module, false);

        $this->assertFalse(
            app(ModuleTenantService::class)->isEnabledByPermissionEntity($tenant, 'charge_batch'),
            'pre-condition: gate sees charge_batch as disabled even with a sibling module sharing the entity',
        );

        // Tenancy left uninitialized (as the sibling tests do): the gate resolves the
        // tenant from the user's memberships and blocks before the controller runs.
        $response = $this->withoutMiddleware(InitializeTenancyFromUser::class)
            ->actingAs($user, 'sanctum')
            ->getJson('/api/charge_batch');

        $response->assertStatus(403);
        $response->assertJson(['code' => 'MODULE_DISABLED']);
    }
}
