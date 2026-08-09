<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Models\Role as AppRole;
use App\Models\User;
use Database\Seeders\ResetPermissionTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role as RoleCatalog;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Regression tests for plan 019:
 *  1. updatePassword rejects missing / short new_password (was: always 200).
 *  2. afterUpdate role guard fires on non-superadmin trying to assign ACCOUNT_OWNER
 *     (was: guard inverted — fired on superadmin changing any common role).
 */
final class UserPasswordAndRoleGuardTest extends TestCase
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

    private function makeUser(Tenant $tenant, string $password = 'secret12345'): User
    {
        $user = User::create([
            'name' => 'Test '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make($password),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        return $user;
    }

    /** Wildcard token — passes every tokenCan() check (simulates admin). */
    private function adminToken(User $user): string
    {
        return $user->createToken('admin', ['*'])->plainTextToken;
    }

    /** Narrow token — simulates a resident with limited scope. */
    private function residentToken(User $user, array $abilities = []): string
    {
        return $user->createToken('resident', $abilities)->plainTextToken;
    }

    /**
     * Build a tenant-scoped, token-authenticated request.
     * Flushes resolved guards so each call re-resolves its own bearer token.
     */
    // =========================================================================
    // updatePassword — password validation
    // =========================================================================

    /**
     * Bug case: updatePassword with no new_password must return 422, not 200.
     *
     * Before the fix, only `password` was validated; `new_password` was silently
     * accepted as null and Hash::make(null) was saved as the new password.
     */
    public function test_update_password_without_new_password_returns_422(): void
    {
        $password = 'CurrentPass123';
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $user = $this->makeUser($tenant, $password);
        $token = $this->adminToken($user);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/updatePassword', [
                'password' => $password,
                // new_password intentionally omitted
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['new_password']]);
    }

    /**
     * new_password shorter than 8 characters must be rejected with 422.
     */
    public function test_update_password_with_short_new_password_returns_422(): void
    {
        $password = 'CurrentPass123';
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $user = $this->makeUser($tenant, $password);
        $token = $this->adminToken($user);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/updatePassword', [
                'password' => $password,
                'new_password' => 'abc', // 3 chars — below the 8-character minimum
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['new_password']]);
    }

    /**
     * Happy path: valid current password + valid new_password → 200, password changed,
     * tokens revoked.
     */
    public function test_update_password_happy_path_changes_password_and_revokes_tokens(): void
    {
        $currentPassword = 'CurrentPass123';
        $newPassword = 'NewSecurePass456';
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $user = $this->makeUser($tenant, $currentPassword);

        // Pre-existing extra token (simulates "other device").
        $user->createToken('other-device', ['*']);
        $this->assertCount(1, $user->fresh()->tokens);

        $token = $this->adminToken($user);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/updatePassword', [
                'password' => $currentPassword,
                'new_password' => $newPassword,
            ])
            ->assertStatus(200)
            ->assertJson(['message' => 'Contraseña actualizada exitosamente. Por favor, inicie sesión nuevamente.']);

        $fresh = $user->fresh();

        $this->assertTrue(
            Hash::check($newPassword, $fresh->password),
            'The new password must be hashed and saved correctly'
        );

        $this->assertCount(
            0,
            $fresh->tokens,
            'All tokens must be revoked after a password change'
        );
    }

    // =========================================================================
    // afterUpdate — role guard
    // =========================================================================

    /**
     * Bug case: a non-superadmin (Administrator) must be blocked from assigning
     * ACCOUNT_OWNER to a user.
     *
     * Before the fix, the guard was inverted: it fired on superadmins changing
     * common roles (wrong), but never fired for non-superadmins assigning owner (wrong).
     */
    public function test_non_superadmin_cannot_assign_account_owner_role(): void
    {
        Artisan::call('db:seed', ['--class' => ResetPermissionTableSeeder::class, '--force' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $target = $this->makeUser($tenant);

        // Assign a common resident role so there is a current role on the target.
        $residentRole = AppRole::where('name', 'resident')->first()
            ?? AppRole::create(['name' => 'resident', 'guard_name' => 'api']);
        $target->assignRole($residentRole);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The acting admin has wildcard ability but is NOT a superadmin Spatie role.
        $token = $this->adminToken($admin);

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/user/{$target->id}", [
                'role_id' => RoleCatalog::ACCOUNT_OWNER,
            ])
            ->assertStatus(403);
    }

    /**
     * Bug case: a superadmin changing a user's role from one common role to another
     * must succeed (200). Before the fix the inverted guard returned 403.
     */
    public function test_superadmin_can_change_common_role_without_403(): void
    {
        Artisan::call('db:seed', ['--class' => ResetPermissionTableSeeder::class, '--force' => true]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        // Ensure common roles exist.
        $residentRole = AppRole::firstOrCreate(['name' => 'resident', 'guard_name' => 'api']);
        $renterRole = AppRole::firstOrCreate(['name' => 'renter', 'guard_name' => 'api']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $superAdmin = $this->makeUser($tenant);
        $superAdmin->assignRole(RoleCatalog::SUPER_ADMIN);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $target = $this->makeUser($tenant);
        $target->assignRole($residentRole);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $token = $this->adminToken($superAdmin);

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/user/{$target->id}", [
                'role_id' => $renterRole->uuid,
            ])
            ->assertStatus(200);
    }
}
