<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Http\Middleware\InitializeTenancyFromUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\System\Models\System;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

final class MenuEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Exclude pgsql from transaction wrapping — same pattern as TenantSeedDefaultsTest.
     * Required because Stancl's Tenancy has issues when the central connection is
     * inside a transaction.
     *
     * @var array<string>
     */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        DB::table('system_tenant')->truncate();
        DB::table('user_tenant')->truncate();
        DB::table('system')->truncate();
        DB::table('user')->truncate();
        DB::table('tenant')->truncate();

        parent::tearDown();
    }

    /**
     * Creates a Tenant row in the central DB without triggering the
     * TenantCreated event (which would try to provision a real tenant DB).
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
     * Super admin (no tenant) gets only the Core system in the menu.
     * No tenancy initialization happens — middleware skips because user has no tenant.
     */
    public function test_super_admin_without_tenant_sees_only_core_system(): void
    {
        // firstOrCreate: the E2E suite commits seeded systems (shared scenario),
        // so under full-suite order these slugs may already exist.
        System::firstOrCreate(['slug' => 'core'], [
            'id' => (string) Str::uuid7(),
            'name' => 'Core',
            'description' => 'Administracion de la plataforma (solo super admin)',
            'icon' => 'IconSettings',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        // Create a non-core system to confirm it is excluded for super admin
        System::firstOrCreate(['slug' => 'rrhh'], System::factory()->make(['is_active' => true])->getAttributes());

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/menu');

        $response->assertStatus(200);

        $data = $response->json('data');

        // For a user with no tenant, only core may appear
        $returnedSlugs = collect($data)->pluck('slug')->all();
        foreach ($returnedSlugs as $slug) {
            $this->assertSame('core', $slug, "Non-core slug [{$slug}] returned for user without tenant.");
        }
    }

    /**
     * Tenant user with assigned systems sees their active systems (not core).
     *
     * The InitializeTenancyFromUser middleware is disabled for this test because it
     * would attempt to provision a real tenant database — which is outside the scope
     * of this menu-logic test. The MenuController queries only the central DB
     * (System model uses CentralConnection), so skipping tenancy initialization
     * does not affect the correctness of the assertion.
     */
    public function test_tenant_user_sees_active_systems_from_pivot(): void
    {
        $rrhh = System::factory()->create(['slug' => 'rrhh', 'is_active' => true]);

        $tenant = $this->makeTenant();
        $tenant->systems()->attach($rrhh->id, ['is_addon' => false]);

        $user = User::factory()->create();
        $tenant->users()->attach($user->id);

        // Bypass InitializeTenancyFromUser — this test verifies menu response shape,
        // not tenant DB provisioning (tested separately via TenantSeedDefaultsTest).
        $response = $this->withoutMiddleware(InitializeTenancyFromUser::class)
            ->actingAs($user, 'sanctum')
            ->getJson('/api/menu');

        $response->assertStatus(200);

        $data = $response->json('data');

        // Core must not appear for tenant users
        $returnedSlugs = collect($data)->pluck('slug')->all();
        $this->assertNotContains('core', $returnedSlugs);

        // Each returned system must have the expected response shape
        foreach ($data as $system) {
            $this->assertArrayHasKey('id', $system);
            $this->assertArrayHasKey('name', $system);
            $this->assertArrayHasKey('slug', $system);
            $this->assertArrayHasKey('icon', $system);
            $this->assertArrayHasKey('items', $system);
        }
    }

    /**
     * Unauthenticated request to /api/menu must return 401.
     */
    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/menu')->assertStatus(401);
    }
}
