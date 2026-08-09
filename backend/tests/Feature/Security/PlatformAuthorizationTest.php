<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Role as SpatieRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

/**
 * Platform-level authorization (findings authz-01 / authz-02 / authz-08).
 *
 * Mutating a GLOBAL Spatie role's permissions or a tenant's module/system
 * assignments affects the whole platform, so those endpoints demand the
 * super-admin-exclusive abilities (update.role / update.tenant). A tenant
 * admin token — however broad — must get 403.
 */
final class PlatformAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function makeTenant(): Tenant
    {
        return Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => 'Platform Authz Cluster',
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );
    }

    private function makeUser(Tenant $tenant): User
    {
        $user = User::create([
            'name' => 'Test '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        return $user;
    }

    /**
     * Broad tenant-admin abilities — everything an administrator holds EXCEPT
     * the super-admin exclusives (update.role / update.tenant).
     */
    private function tenantAdminToken(User $user): string
    {
        return $user->createToken('admin', [
            'read.role', 'read.user', 'update.user', 'read.unit', 'update.unit',
            'read.charge', 'create.payment', 'manage.subscription',
        ])->plainTextToken;
    }

    private function superAdminToken(User $user): string
    {
        return $user->createToken('super', ['*'])->plainTextToken;
    }

    private function request(string $token): self
    {
        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        return $this->withToken($token);
    }

    private function makeGlobalRole(): SpatieRole
    {
        return SpatieRole::create([
            'name' => 'authz_probe_'.Str::random(6),
            'guard_name' => 'api',
        ]);
    }

    // =========================================================================
    // authz-01 — role permission management
    // =========================================================================

    public function test_tenant_admin_cannot_mutate_global_role_permissions(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant);
        $role = $this->makeGlobalRole();

        $this->request($this->tenantAdminToken($admin))
            ->postJson("/api/role/permissions/{$role->getKey()}", ['permissions' => []])
            ->assertStatus(403);
    }

    public function test_super_admin_can_mutate_global_role_permissions(): void
    {
        $tenant = $this->makeTenant();
        $super = $this->makeUser($tenant);
        $role = $this->makeGlobalRole();

        $this->request($this->superAdminToken($super))
            ->postJson("/api/role/permissions/{$role->getKey()}", ['permissions' => []])
            ->assertStatus(200);
    }

    public function test_reading_role_permissions_requires_read_role_ability(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        $role = $this->makeGlobalRole();

        // read.role present → allowed (super-admin-exclusive in the seeded matrix).
        $this->request($this->tenantAdminToken($user))
            ->getJson("/api/role/permissions/{$role->getKey()}")
            ->assertStatus(200);

        // Without read.role → denied.
        $bare = $this->makeUser($tenant);
        $bareToken = $bare->createToken('bare', ['read.unit'])->plainTextToken;

        $this->request($bareToken)
            ->getJson("/api/role/permissions/{$role->getKey()}")
            ->assertStatus(403);
    }

    // =========================================================================
    // authz-02 — tenant module/system assignment
    // =========================================================================

    public function test_tenant_admin_cannot_manage_tenant_modules_or_systems(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant);
        $token = $this->tenantAdminToken($admin);

        $this->request($token)
            ->getJson("/api/tenant/{$tenant->id}/modules")
            ->assertStatus(403);

        $this->request($token)
            ->postJson("/api/tenant/{$tenant->id}/modules/sync", ['modules' => []])
            ->assertStatus(403);

        $this->request($token)
            ->getJson("/api/tenant/{$tenant->id}/systems")
            ->assertStatus(403);
    }

    public function test_super_admin_can_read_tenant_modules_and_systems(): void
    {
        $tenant = $this->makeTenant();
        $super = $this->makeUser($tenant);
        $token = $this->superAdminToken($super);

        $this->request($token)
            ->getJson("/api/tenant/{$tenant->id}/modules")
            ->assertStatus(200);

        $this->request($token)
            ->getJson("/api/tenant/{$tenant->id}/systems")
            ->assertStatus(200);
    }

    // =========================================================================
    // authz-08 — dead user/permissions routes are gone
    // =========================================================================

    public function test_user_permissions_routes_no_longer_exist(): void
    {
        $tenant = $this->makeTenant();
        $super = $this->makeUser($tenant);
        $token = $this->superAdminToken($super);

        $this->request($token)
            ->getJson("/api/user/permissions/{$super->id}")
            ->assertStatus(404);

        $this->request($token)
            ->postJson("/api/user/permissions/{$super->id}", [])
            ->assertStatus(404);
    }
}
