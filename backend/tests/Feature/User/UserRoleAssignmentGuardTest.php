<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Models\Role as AppRole;
use App\Models\User;
use Database\Seeders\ResetPermissionTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role as RoleCatalog;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Privilege-escalation guard on role assignment (POST /api/user, PUT /api/user/{id}).
 *
 * Before the fix, UserService::create() and UserController::afterUpdate() assigned
 * whatever role the request body named, with NO check against the actor's own tier.
 * `create.user` / `update.user` are held by the `administrator` role a cluster hires,
 * so that role could mint a platform super admin with cross-tenant access to every
 * cluster in the SaaS.
 *
 * The guard lives in QCH\Core\Models\Role::canAssignSlug().
 */
final class UserRoleAssignmentGuardTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Seed the real role/permission catalogue so the slugs under test
     * (admin.super, administrator, resident…) exist exactly as in production.
     */
    private function seedRoles(): void
    {
        Artisan::call('db:seed', ['--class' => ResetPermissionTableSeeder::class, '--force' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function roleBySlug(string $slug): AppRole
    {
        $role = AppRole::where('name', $slug)->first();

        $this->assertNotNull($role, "The seeded catalogue must contain the '{$slug}' role.");

        return $role;
    }

    /**
     * An actor holding the given Spatie role plus a WILDCARD token — i.e. every
     * tokenCan() check passes. This is precisely the attacker profile: ability
     * checks are satisfied, only the role tier should stop them.
     */
    private function actorWithRole(Tenant $tenant, string $slug): array
    {
        $user = $this->makeUser($tenant);
        $user->assignRole($this->roleBySlug($slug));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$user, $user->createToken('actor', ['*'])->plainTextToken];
    }

    /**
     * @return array<string, string>
     */
    private function newUserPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nuevo '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => 'SecurePass123',
        ], $overrides);
    }

    // =========================================================================
    // create — POST /api/user
    // =========================================================================

    public function test_administrator_cannot_create_a_super_admin_by_role_slug(): void
    {
        $this->seedRoles();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        [, $token] = $this->actorWithRole($tenant, RoleCatalog::SLUG_ADMINISTRATOR);

        $payload = $this->newUserPayload(['role' => RoleCatalog::SLUG_SUPER_ADMIN]);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/user', $payload)
            ->assertStatus(403);

        $this->assertDatabaseMissing('user', ['email' => $payload['email']]);
    }

    public function test_administrator_cannot_create_a_super_admin_by_role_id(): void
    {
        $this->seedRoles();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        [, $token] = $this->actorWithRole($tenant, RoleCatalog::SLUG_ADMINISTRATOR);

        $payload = $this->newUserPayload([
            'role_id' => $this->roleBySlug(RoleCatalog::SLUG_SUPER_ADMIN)->uuid,
        ]);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/user', $payload)
            ->assertStatus(403);

        $this->assertDatabaseMissing('user', ['email' => $payload['email']]);
    }

    public function test_administrator_cannot_create_an_account_owner(): void
    {
        $this->seedRoles();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        [, $token] = $this->actorWithRole($tenant, RoleCatalog::SLUG_ADMINISTRATOR);

        $payload = $this->newUserPayload(['role' => RoleCatalog::SLUG_ACCOUNT_OWNER]);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/user', $payload)
            ->assertStatus(403);

        $this->assertDatabaseMissing('user', ['email' => $payload['email']]);
    }

    /** No regression: handing out an ordinary cluster role must still work. */
    public function test_administrator_can_still_create_a_resident(): void
    {
        $this->seedRoles();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        [, $token] = $this->actorWithRole($tenant, RoleCatalog::SLUG_ADMINISTRATOR);

        $payload = $this->newUserPayload(['role' => RoleCatalog::SLUG_RESIDENT]);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/user', $payload)
            ->assertSuccessful();

        $created = User::where('email', $payload['email'])->firstOrFail();

        $this->assertTrue($created->hasRole(RoleCatalog::SLUG_RESIDENT));
    }

    /**
     * The platform tier keeps its ability to create its own kind.
     *
     * `tenant_id` is REQUIRED here: UserService::create only attaches a tenant on
     * the super-admin path when the payload names one, and a user with no tenant
     * is invisible to UserTenantScope — Orion's read-back for the response would
     * then 404. That is pre-existing behaviour of the platform path, unrelated to
     * the role guard under test.
     */
    public function test_super_admin_can_create_a_super_admin(): void
    {
        $this->seedRoles();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        [, $token] = $this->actorWithRole($tenant, RoleCatalog::SLUG_SUPER_ADMIN);

        $payload = $this->newUserPayload([
            'role' => RoleCatalog::SLUG_SUPER_ADMIN,
            'tenant_id' => $tenant->id,
        ]);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/user', $payload)
            ->assertSuccessful();

        $created = User::where('email', $payload['email'])->firstOrFail();

        $this->assertTrue($created->hasRole(RoleCatalog::SLUG_SUPER_ADMIN));
    }

    // =========================================================================
    // update — PUT /api/user/{id}
    // =========================================================================

    public function test_administrator_cannot_promote_an_existing_user_to_super_admin(): void
    {
        $this->seedRoles();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        [, $token] = $this->actorWithRole($tenant, RoleCatalog::SLUG_ADMINISTRATOR);

        $target = $this->makeUser($tenant);
        $target->assignRole($this->roleBySlug(RoleCatalog::SLUG_RESIDENT));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/user/{$target->id}", [
                'role_id' => $this->roleBySlug(RoleCatalog::SLUG_SUPER_ADMIN)->uuid,
            ])
            ->assertStatus(403);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse(
            $target->fresh()->hasRole(RoleCatalog::SLUG_SUPER_ADMIN),
            'A rejected promotion must leave the target role untouched.'
        );
    }

    /** No regression: swapping between ordinary cluster roles still works. */
    public function test_administrator_can_still_change_a_user_between_cluster_roles(): void
    {
        $this->seedRoles();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        [, $token] = $this->actorWithRole($tenant, RoleCatalog::SLUG_ADMINISTRATOR);

        $target = $this->makeUser($tenant);
        $target->assignRole($this->roleBySlug(RoleCatalog::SLUG_RESIDENT));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/user/{$target->id}", [
                'role_id' => $this->roleBySlug(RoleCatalog::SLUG_RENTER)->uuid,
            ])
            ->assertStatus(200);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($target->fresh()->hasRole(RoleCatalog::SLUG_RENTER));
    }
}
