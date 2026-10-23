<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Http\Middleware\InitializeTenancyFromUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Module\Models\Module;
use Modules\System\Models\System;
use Modules\System\Services\ModuleTenantService;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

final class TenantModuleControllerTest extends TestCase
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
                'id' => (string) Str::uuid7(),
                'name' => 'Test Tenant',
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );
    }

    private function actAsUser(): User
    {
        // Tenant module/system assignment is platform administration (authz-02):
        // the endpoints require update.tenant, so the acting user is a super
        // admin. auth_active must be explicit — the route group now runs the
        // authActive middleware.
        $user = User::factory()->create(['auth_active' => true]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_index_returns_active_systems_with_modules_and_enabled_flag(): void
    {
        $this->actAsUser();
        $tenant = $this->makeTenant();

        $system = System::factory()->create(['is_active' => true]);
        $tenant->systems()->attach($system->id, ['is_addon' => false]);

        $moduleEnabled = Module::factory()->create(['visible' => true]);
        $moduleDisabled = Module::factory()->create(['visible' => true]);
        $system->modules()->attach($moduleEnabled->id, ['sort_order' => 1]);
        $system->modules()->attach($moduleDisabled->id, ['sort_order' => 2]);

        app(ModuleTenantService::class)->setEnabled($tenant, $moduleDisabled, false);

        $response = $this->withoutMiddleware(InitializeTenancyFromUser::class)
            ->getJson("/api/tenant/{$tenant->id}/modules");

        $response->assertStatus(200);

        $payload = $response->json('data');
        $this->assertCount(1, $payload);

        $modules = collect($payload[0]['modules']);
        $this->assertSame(
            true,
            $modules->firstWhere('id', $moduleEnabled->id)['enabled'],
            'Module without pivot row should resolve as enabled (opt-out).'
        );
        $this->assertSame(
            false,
            $modules->firstWhere('id', $moduleDisabled->id)['enabled'],
            'Module with enabled=false pivot row must be reported as disabled.'
        );
    }

    public function test_toggle_persists_enabled_state(): void
    {
        $this->actAsUser();
        $tenant = $this->makeTenant();
        $module = Module::factory()->create();

        $response = $this->withoutMiddleware(InitializeTenancyFromUser::class)
            ->patchJson("/api/tenant/{$tenant->id}/modules/{$module->id}", ['enabled' => false]);

        $response->assertStatus(200);
        $this->assertFalse(app(ModuleTenantService::class)->isEnabled($tenant, $module));

        $response = $this->withoutMiddleware(InitializeTenancyFromUser::class)
            ->patchJson("/api/tenant/{$tenant->id}/modules/{$module->id}", ['enabled' => true]);

        $response->assertStatus(200);
        $this->assertTrue(app(ModuleTenantService::class)->isEnabled($tenant, $module));
    }

    public function test_toggle_validates_enabled_is_required_boolean(): void
    {
        $this->actAsUser();
        $tenant = $this->makeTenant();
        $module = Module::factory()->create();

        $this->withoutMiddleware(InitializeTenancyFromUser::class)
            ->patchJson("/api/tenant/{$tenant->id}/modules/{$module->id}", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('enabled');

        $this->withoutMiddleware(InitializeTenancyFromUser::class)
            ->patchJson("/api/tenant/{$tenant->id}/modules/{$module->id}", ['enabled' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('enabled');
    }

    public function test_toggle_returns_404_for_unknown_tenant_or_module(): void
    {
        $this->actAsUser();
        $tenant = $this->makeTenant();
        $module = Module::factory()->create();

        $unknownId = (string) Str::uuid7();

        $this->withoutMiddleware(InitializeTenancyFromUser::class)
            ->patchJson("/api/tenant/{$unknownId}/modules/{$module->id}", ['enabled' => false])
            ->assertStatus(404);

        $this->withoutMiddleware(InitializeTenancyFromUser::class)
            ->patchJson("/api/tenant/{$tenant->id}/modules/{$unknownId}", ['enabled' => false])
            ->assertStatus(404);
    }

    public function test_sync_persists_bulk_entries(): void
    {
        $this->actAsUser();
        $tenant = $this->makeTenant();
        $moduleA = Module::factory()->create();
        $moduleB = Module::factory()->create();

        $response = $this->withoutMiddleware(InitializeTenancyFromUser::class)
            ->postJson("/api/tenant/{$tenant->id}/modules/sync", [
                'modules' => [
                    ['module_id' => $moduleA->id, 'enabled' => false],
                    ['module_id' => $moduleB->id, 'enabled' => true],
                ],
            ]);

        $response->assertStatus(200);

        $service = app(ModuleTenantService::class);
        $this->assertFalse($service->isEnabled($tenant, $moduleA));
        $this->assertTrue($service->isEnabled($tenant, $moduleB));
    }

    public function test_sync_rejects_unknown_module_id(): void
    {
        $this->actAsUser();
        $tenant = $this->makeTenant();

        $this->withoutMiddleware(InitializeTenancyFromUser::class)
            ->postJson("/api/tenant/{$tenant->id}/modules/sync", [
                'modules' => [
                    ['module_id' => (string) Str::uuid7(), 'enabled' => false],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('modules.0.module_id');
    }

    public function test_sync_rejects_missing_modules_array(): void
    {
        $this->actAsUser();
        $tenant = $this->makeTenant();

        $this->withoutMiddleware(InitializeTenancyFromUser::class)
            ->postJson("/api/tenant/{$tenant->id}/modules/sync", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('modules');
    }
}
