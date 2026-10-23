<?php

declare(strict_types=1);

namespace Tests\Feature\CrmReporting;

use App\Http\Middleware\InitializeTenancyFromUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\TenantDatabaseEnsurer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Tenant\Database\Seeders\TenantDatabaseSeeder;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

/**
 * Verifies that CRM reporting endpoints enforce Sanctum auth + Spatie
 * `read.crm_reporting` permission via the `permission:` middleware on
 * `backend/Modules/CrmReporting/routes/api.php`.
 */
final class PermissionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private const ENDPOINTS = ['overview', 'pipeline', 'activity'];

    private function makeTenant(): Tenant
    {
        $tenant = Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid7(),
                'name' => 'test-crm-perm-'.bin2hex(random_bytes(4)),
                'tenancy_db_name' => 'tenant_test_'.bin2hex(random_bytes(4)),
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );

        TenantDatabaseEnsurer::ensure($tenant, seed: false);

        $tenant->run(function (): void {
            Artisan::call('db:seed', [
                '--class' => TenantDatabaseSeeder::class,
                '--force' => true,
            ]);
        });

        return $tenant;
    }

    private function userWithCrmReportingRole(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $tenant->users()->attach($user->id);

        $permission = Permission::firstOrCreate(
            ['name' => 'read.crm_reporting', 'guard_name' => 'api'],
        );

        $role = Role::create([
            'name' => 'crm-reporting-test-role-'.Str::random(4),
            'guard_name' => 'api',
        ]);

        $role->givePermissionTo($permission);
        $user->assignRole($role->name);

        return $user;
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        foreach (self::ENDPOINTS as $endpoint) {
            $this->getJson("/api/crm-reporting/{$endpoint}")
                ->assertStatus(401);
        }
    }

    public function test_user_without_permission_returns_403(): void
    {
        $tenant = $this->makeTenant();
        $user = User::factory()->create();
        $tenant->users()->attach($user->id);

        foreach (self::ENDPOINTS as $endpoint) {
            $this->withoutMiddleware(InitializeTenancyFromUser::class)
                ->actingAs($user, 'sanctum')
                ->getJson("/api/crm-reporting/{$endpoint}")
                ->assertStatus(403);
        }
    }

    public function test_user_with_permission_returns_200(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->userWithCrmReportingRole($tenant);

        foreach (self::ENDPOINTS as $endpoint) {
            $response = $this->withoutMiddleware(InitializeTenancyFromUser::class)
                ->actingAs($user, 'sanctum')
                ->getJson("/api/crm-reporting/{$endpoint}");

            $response->assertStatus(200);
            $response->assertJsonStructure(['data']);
        }
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Tenant::withTrashed()
            ->where('name', 'like', 'test-crm-perm-%')
            ->get()
            ->each(static function (Tenant $tenant): void {
                try {
                    $manager = $tenant->database()->manager();
                    if ($manager && $manager->databaseExists($tenant->database()->getName())) {
                        $manager->deleteDatabase($tenant);
                    }
                } catch (\Throwable) {
                    //
                }
                Tenant::withoutEvents(static fn () => $tenant->forceDelete());
            });

        parent::tearDown();
    }
}
