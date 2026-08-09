<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

/**
 * Session hardening — authn Wave 0:
 * - refresh tokens are NOT valid as Bearer on protected routes (sec-authn-04)
 * - logout revokes the refresh token too (sec-authn-05)
 * - auth_active is enforced on every request (sec-authn-10)
 * - admin remote session revocation (§5.4 r4, auth-03)
 * - device cap: max 3 concurrent sessions, 4th displaces oldest (§5.4 r6, auth-04)
 */
final class SessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private function seedAccountOwnerRole(): \App\Models\Role
    {
        return \App\Models\Role::firstOrCreate(
            ['name' => Role::SLUG_ACCOUNT_OWNER, 'guard_name' => 'api']
        );
    }

    private function makeTenant(string $name = 'Session Tenant'): Tenant
    {
        return Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => $name.' '.Str::random(4),
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );
    }

    /**
     * @return array{0: User, 1: Tenant, 2: string}
     */
    private function makeUserWithTenant(?string $email = null, string $password = 'secret1234'): array
    {
        $this->seedAccountOwnerRole();
        $tenant = $this->makeTenant();

        $user = User::create([
            'name' => 'Session',
            'email' => $email ?? fake()->unique()->safeEmail(),
            'password' => Hash::make($password),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);
        $user->assignRole(Role::SLUG_ACCOUNT_OWNER);

        return [$user, $tenant, $password];
    }

    /**
     * Feature tests reuse the app instance between requests: the auth manager
     * caches resolved guards and auth()->attempt() (login endpoint) leaves the
     * user in the in-memory session, which Sanctum's stateful fallback would
     * pick up on the next request. Real API clients have neither — this
     * restores that stateless behavior.
     */
    private function resetAuthState(): void
    {
        $this->app['auth']->forgetGuards();
        session()->flush();
    }

    private function makeUserInTenant(Tenant $tenant, ?string $email = null): User
    {
        $user = User::create([
            'name' => 'Target',
            'email' => $email ?? fake()->unique()->safeEmail(),
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        return $user;
    }

    // -------------------------------------------------------------------------
    // sec-authn-04 — refresh token is not an access token
    // -------------------------------------------------------------------------

    public function test_refresh_token_cannot_be_used_as_bearer_on_protected_routes(): void
    {
        [$user] = $this->makeUserWithTenant();

        $refresh = $user->createToken('refresh_token', ['refresh'], now()->addDays(7))->plainTextToken;

        $this->withToken($refresh)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_refresh_token_is_still_accepted_by_the_refresh_endpoint(): void
    {
        [$user] = $this->makeUserWithTenant();

        $refresh = $user->createToken('refresh_token', ['refresh'], now()->addDays(7))->plainTextToken;

        $this->withToken($refresh)->postJson('/api/auth/refresh')->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // sec-authn-05 — logout revokes the refresh token
    // -------------------------------------------------------------------------

    public function test_logout_with_cookie_revokes_that_device_refresh_token(): void
    {
        [$user,, $password] = $this->makeUserWithTenant('cookie-logout@example.com');

        $login = $this->postJson('/api/auth/login', [
            'email' => 'cookie-logout@example.com',
            'password' => $password,
        ]);
        $login->assertStatus(200);

        $cookie = $login->getCookie('refresh_token', false);
        $this->assertNotNull($cookie, 'Login must set the refresh cookie.');
        $refreshPlain = $cookie->getValue();

        // Another device's refresh token must survive a per-device logout.
        $user->createToken('refresh_token', ['refresh'], now()->addDays(7));

        $this->resetAuthState();
        $this->withCredentials()
            ->withToken($login->json('data.access_token'))
            ->withUnencryptedCookie('refresh_token', $refreshPlain)
            ->postJson('/api/auth/logout')
            ->assertStatus(200);

        $this->assertSame(0, $user->tokens()->where('name', 'auth_token')->count(), 'Logout must revoke the access token.');
        $this->assertSame(1, $user->tokens()->where('name', 'refresh_token')->count(), 'Only this device\'s refresh token is revoked.');

        // A replayed refresh cookie must not revive the session.
        $this->postJson('/api/auth/refresh')->assertStatus(401);
    }

    public function test_logout_without_cookie_revokes_all_refresh_tokens(): void
    {
        [$user,, $password] = $this->makeUserWithTenant('bearer-logout@example.com');

        $login = $this->postJson('/api/auth/login', [
            'email' => 'bearer-logout@example.com',
            'password' => $password,
        ]);
        $login->assertStatus(200);

        $this->resetAuthState();
        $this->withToken($login->json('data.access_token'))
            ->postJson('/api/auth/logout')
            ->assertStatus(200);

        $this->assertSame(
            0,
            $user->tokens()->where('name', 'refresh_token')->count(),
            'No refresh token may survive an explicit logout.'
        );
    }

    // -------------------------------------------------------------------------
    // sec-authn-10 — auth_active enforced per request
    // -------------------------------------------------------------------------

    public function test_deactivated_user_with_live_access_token_is_rejected_per_request(): void
    {
        [$user] = $this->makeUserWithTenant();

        $token = $user->createToken('auth_token', ['*'], now()->addHour())->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(200);

        $user->update(['auth_active' => false]);

        $this->resetAuthState();
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // auth-03 / §5.4 r4 — admin remote session revocation
    // -------------------------------------------------------------------------

    public function test_admin_with_update_user_permission_can_revoke_all_sessions(): void
    {
        [$admin, $tenant] = $this->makeUserWithTenant('admin-revoke@example.com');
        $target = $this->makeUserInTenant($tenant);

        $targetAccess = $target->createToken('auth_token', ['*'], now()->addHour())->plainTextToken;
        $target->createToken('refresh_token', ['refresh'], now()->addDays(7));

        $adminToken = $admin->createToken('auth_token', ['update.user'], now()->addHour())->plainTextToken;

        $this->withToken($adminToken)
            ->postJson("/api/user/{$target->id}/revoke-sessions")
            ->assertStatus(200);

        $this->assertSame(0, $target->tokens()->count(), 'All target tokens must be deleted.');

        // The revoked access token must stop working immediately.
        $this->resetAuthState();
        $this->withToken($targetAccess)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_user_without_update_user_permission_cannot_revoke_sessions(): void
    {
        [$actor, $tenant] = $this->makeUserWithTenant('no-perm@example.com');
        $target = $this->makeUserInTenant($tenant);

        $target->createToken('auth_token', ['*'], now()->addHour());

        $actorToken = $actor->createToken('auth_token', ['read.user'], now()->addHour())->plainTextToken;

        $this->withToken($actorToken)
            ->postJson("/api/user/{$target->id}/revoke-sessions")
            ->assertStatus(403);

        $this->assertSame(1, $target->tokens()->count(), 'Target tokens must remain intact.');
    }

    public function test_admin_cannot_revoke_sessions_of_a_user_from_another_tenant(): void
    {
        [$admin] = $this->makeUserWithTenant('isolated-admin@example.com');

        $strangerTenant = $this->makeTenant('Other Cluster');
        $stranger = $this->makeUserInTenant($strangerTenant);
        $stranger->createToken('auth_token', ['*'], now()->addHour());

        $adminToken = $admin->createToken('auth_token', ['update.user'], now()->addHour())->plainTextToken;

        $this->withToken($adminToken)
            ->postJson("/api/user/{$stranger->id}/revoke-sessions")
            ->assertStatus(404);

        $this->assertSame(1, $stranger->tokens()->count(), 'Cross-tenant revocation must not happen.');
    }

    // -------------------------------------------------------------------------
    // auth-04 / §5.4 r6 — device cap
    // -------------------------------------------------------------------------

    public function test_fourth_login_displaces_the_oldest_session(): void
    {
        [$user,, $password] = $this->makeUserWithTenant('devices@example.com');

        $credentials = ['email' => 'devices@example.com', 'password' => $password];

        $firstAccess = $this->postJson('/api/auth/login', $credentials)->assertStatus(200)->json('data.access_token');

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/login', $credentials)->assertStatus(200);
        }

        $this->assertSame(3, $user->tokens()->where('name', 'auth_token')->count());
        $this->assertSame(3, $user->tokens()->where('name', 'refresh_token')->count());

        // The first (oldest) session was displaced by the 4th login.
        $this->resetAuthState();
        $this->withToken($firstAccess)->getJson('/api/auth/me')->assertStatus(401);
    }
}
