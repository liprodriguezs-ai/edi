<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\System\Models\System;
use Modules\System\Services\SystemTenantService;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

final class TenantSystemAssignmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Exclude all connections from transaction wrapping to avoid Stancl
     * issues when creating Tenant rows in the central DB during tests.
     *
     * @var array<string>
     */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        DB::table('system_tenant')->delete();

        parent::tearDown();
    }

    /**
     * Creates a Tenant row in the central DB without triggering the
     * TenantCreated event (which would try to create a real tenant DB).
     */
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

    /**
     * addAddon() attaches the system with is_addon = true.
     */
    public function test_add_addon_attaches_with_is_addon_true(): void
    {
        $tenant = $this->makeTenant();
        $system = System::factory()->create();
        $service = app(SystemTenantService::class);

        $service->addAddon($tenant, $system);

        $pivot = $tenant->systems()->where('system.id', $system->id)->first();
        $this->assertNotNull($pivot, 'System was not attached to the tenant.');
        $this->assertTrue((bool) $pivot->pivot->is_addon, 'is_addon should be true for manual addon.');
    }

    /**
     * addAddon() is idempotent — calling it twice does not duplicate the pivot row.
     */
    public function test_add_addon_is_idempotent(): void
    {
        $tenant = $this->makeTenant();
        $system = System::factory()->create();
        $service = app(SystemTenantService::class);

        $service->addAddon($tenant, $system);
        $service->addAddon($tenant, $system);

        $count = $tenant->systems()->where('system.id', $system->id)->count();
        $this->assertSame(1, $count, 'addAddon should not create duplicate pivot rows.');
    }

    /**
     * removeAddon() refuses to detach a system that came from the plan (is_addon = false).
     */
    public function test_remove_addon_refuses_to_detach_plan_system(): void
    {
        $tenant = $this->makeTenant();
        $system = System::factory()->create();
        $service = app(SystemTenantService::class);

        $tenant->systems()->attach($system->id, ['is_addon' => false]);

        $result = $service->removeAddon($tenant, $system);

        $this->assertFalse($result, 'removeAddon should return false for plan systems.');

        $pivot = $tenant->systems()->where('system.id', $system->id)->first();
        $this->assertNotNull($pivot, 'Plan system should not be detached by removeAddon.');
    }

    /**
     * removeAddon() detaches a manually added addon and returns true.
     */
    public function test_remove_addon_detaches_addon_system(): void
    {
        $tenant = $this->makeTenant();
        $system = System::factory()->create();
        $service = app(SystemTenantService::class);

        $service->addAddon($tenant, $system);

        $result = $service->removeAddon($tenant, $system);

        $this->assertTrue($result, 'removeAddon should return true for addon systems.');

        $pivot = $tenant->systems()->where('system.id', $system->id)->first();
        $this->assertNull($pivot, 'Addon system should be detached after removeAddon.');
    }
}
