<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

/**
 * Auth/Onboarding integration tests — Module 1.
 */
final class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Seeds the account.owner role using the application's Role model (uuid PK).
     * Spatie requires the role to exist in the DB before it can be assigned.
     */
    private function seedAccountOwnerRole(): \App\Models\Role
    {
        return \App\Models\Role::firstOrCreate(
            ['name' => Role::SLUG_ACCOUNT_OWNER, 'guard_name' => 'api']
        );
    }

    private function makeTenant(string $name = 'Test Tenant'): Tenant
    {
        return Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid7(),
                'name' => $name,
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );
    }

    private function makeUserWithTenant(?string $email = null, string $password = 'secret1234'): array
    {
        $this->seedAccountOwnerRole();
        $tenant = $this->makeTenant();

        $user = User::create([
            'name' => 'Test',
            'email' => $email ?? fake()->unique()->safeEmail(),
            'password' => Hash::make($password),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);
        $user->assignRole(Role::SLUG_ACCOUNT_OWNER);

        return [$user, $tenant, $password];
    }

    // -------------------------------------------------------------------------
    // Register
    // -------------------------------------------------------------------------

    public function test_register_creates_user_tenant_role_and_returns_token(): void
    {
        $this->seedAccountOwnerRole();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Juan',
            'organization_name' => 'Residencial Las Palmas',
            'email' => 'juan@example.com',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertNotEmpty($data['access_token']);
        $this->assertNotEmpty($data['tenant']);
        $this->assertSame('account.owner', $data['roles'][0]);

        $this->assertDatabaseHas('user', ['email' => 'juan@example.com']);
    }

    /**
     * The organization name typed at signup must land verbatim in tenant.name — that
     * column is the label the whole UI shows as the organization. It used to be an
     * auto-generated slug built from the PERSON's name (e.g. "juan-x7Kq"), which
     * surfaced as the condominium's name everywhere.
     */
    public function test_register_stores_the_submitted_organization_name_as_the_tenant_name(): void
    {
        $this->seedAccountOwnerRole();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Juan Pérez',
            'organization_name' => '  Residencial Las Palmas  ',
            'email' => 'organization.name@example.com',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ]);

        $response->assertStatus(200);

        // Surrounding whitespace is trimmed; the name is otherwise verbatim and
        // carries no slug/suffix derived from the user's name.
        $this->assertSame('Residencial Las Palmas', $response->json('data.tenant.name'));
        $this->assertDatabaseHas('tenant', ['name' => 'Residencial Las Palmas']);
    }

    public function test_register_requires_a_organization_name(): void
    {
        $this->seedAccountOwnerRole();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Juan',
            'email' => 'no.organization@example.com',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('organization_name', $response->json('errors'));
        $this->assertDatabaseMissing('user', ['email' => 'no.organization@example.com']);
    }

    // -------------------------------------------------------------------------
    // Login
    // -------------------------------------------------------------------------

    public function test_login_returns_token_abilities_and_tenants(): void
    {
        [$user, $tenant, $password] = $this->makeUserWithTenant('login@example.com');

        $response = $this->postJson('/api/auth/login', [
            'email' => 'login@example.com',
            'password' => $password,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertNotEmpty($data['access_token']);
        $this->assertIsArray($data['abilities']);
        $this->assertNotEmpty($data['tenant']);
        // Multi-organization: all tenants returned.
        $this->assertArrayHasKey('tenants', $data);
        $this->assertIsArray($data['tenants']);
        $this->assertCount(1, $data['tenants']);
    }

    public function test_user_with_auth_active_false_cannot_login(): void
    {
        [$user,, $password] = $this->makeUserWithTenant('inactive@example.com');

        $user->update(['auth_active' => false]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'inactive@example.com',
            'password' => $password,
        ]);

        $response->assertStatus(403);
    }

    public function test_login_with_wrong_credentials_returns_401(): void
    {
        $this->makeUserWithTenant('wrong@example.com');

        $response = $this->postJson('/api/auth/login', [
            'email' => 'wrong@example.com',
            'password' => 'bad-password',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_with_passwordless_account_returns_generic_401_without_reset_email(): void
    {
        // sec-authn-09: a passwordless account must be indistinguishable from a
        // nonexistent one (no 423 oracle) and must NOT trigger unsolicited
        // password-reset emails.
        Notification::fake();

        User::create([
            'name' => 'Sin Password',
            'email' => 'no-password@example.com',
            'password' => null,
            'auth_active' => true,
        ]);

        $existing = $this->postJson('/api/auth/login', [
            'email' => 'no-password@example.com',
            'password' => 'whatever123',
        ]);

        $unknown = $this->postJson('/api/auth/login', [
            'email' => 'ghost@example.com',
            'password' => 'whatever123',
        ]);

        $existing->assertStatus(401);
        $unknown->assertStatus(401);
        Notification::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Invitations
    // -------------------------------------------------------------------------

    public function test_accept_invitation_with_invalid_token_returns_400(): void
    {
        // sec-authn-06: the service throws InvalidArgumentException for a bad
        // token; the controller must map it to a controlled 400, never a 500.
        $this->postJson('/api/auth/accept-invitation', [
            'token' => 'totally-bogus-token',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ])->assertStatus(400);
    }

    // -------------------------------------------------------------------------
    // Me
    // -------------------------------------------------------------------------

    public function test_me_returns_authenticated_user_with_tenants(): void
    {
        [$user] = $this->makeUserWithTenant('me@example.com');

        $token = $user->createToken('test', ['*'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertSame($user->id, $data['user']['id']);
        $this->assertArrayHasKey('tenants', $data);
        $this->assertArrayHasKey('tenant', $data);
    }

    // -------------------------------------------------------------------------
    // Logout
    // -------------------------------------------------------------------------

    public function test_logout_revokes_token(): void
    {
        [$user] = $this->makeUserWithTenant('logout@example.com');

        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertStatus(200);

        // Verify the token was deleted from the DB (the reliable assertion in RefreshDatabase context).
        $this->assertSame(0, $user->tokens()->count(), 'All tokens should be revoked after logout.');
    }

    // -------------------------------------------------------------------------
    // "Everything is User" — null email rows
    // -------------------------------------------------------------------------

    public function test_user_with_null_email_and_null_password_can_be_created(): void
    {
        $user = User::create([
            'name' => 'Resident Sin Email',
            'email' => null,
            'password' => null,
            'auth_active' => false,
        ]);

        $this->assertNotNull($user->id);
        $this->assertNull($user->email);
        $this->assertNull($user->password);
        $this->assertFalse($user->auth_active);
    }

    public function test_two_null_email_users_can_coexist_partial_unique_allows_it(): void
    {
        // Count null-email users BEFORE creating ours (there may be seed users with null emails).
        $before = User::withoutTenancy()->whereNull('email')->count();

        User::create(['name' => 'Resident A', 'email' => null, 'password' => null, 'auth_active' => false]);
        User::create(['name' => 'Resident B', 'email' => null, 'password' => null, 'auth_active' => false]);

        $this->assertSame($before + 2, User::withoutTenancy()->whereNull('email')->count());
    }

    public function test_duplicate_email_still_fails_for_non_null_emails(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        User::create(['name' => 'User A', 'email' => 'dupe@example.com', 'password' => null]);
        User::create(['name' => 'User B', 'email' => 'dupe@example.com', 'password' => null]);
    }

    // -------------------------------------------------------------------------
    // Multi-organization tenant selection
    // -------------------------------------------------------------------------

    public function test_x_tenant_id_header_for_valid_tenant_scopes_request_and_persists(): void
    {
        $this->seedAccountOwnerRole();

        $tenantA = $this->makeTenant('Organization A');
        $tenantB = $this->makeTenant('Organization B');

        $user = User::create(['name' => 'Multi', 'email' => 'multi@example.com', 'password' => Hash::make('secret')]);
        $user->tenants()->attach([$tenantA->id, $tenantB->id]);
        $user->assignRole(Role::SLUG_ACCOUNT_OWNER);

        $token = $user->createToken('test', ['*'])->plainTextToken;

        // Request scoped to Tenant B via header.
        $this->withToken($token)
            ->withHeader('X-Tenant-Id', $tenantB->id)
            ->getJson('/api/auth/me')
            ->assertStatus(200);

        // last_active_tenant_id should now be Tenant B.
        $user->refresh();
        $this->assertSame($tenantB->id, (string) $user->last_active_tenant_id);
    }

    public function test_x_tenant_id_header_for_tenant_user_does_not_belong_to_is_ignored(): void
    {
        $this->seedAccountOwnerRole();

        $tenantA = $this->makeTenant('Organization A');
        $tenantStranger = $this->makeTenant('Stranger');

        $user = User::create(['name' => 'Scoped', 'email' => 'scoped@example.com', 'password' => Hash::make('secret')]);
        $user->tenants()->attach([$tenantA->id]);
        $user->assignRole(Role::SLUG_ACCOUNT_OWNER);

        $token = $user->createToken('test', ['*'])->plainTextToken;

        // Should not fail — just ignored; falls back to tenantA.
        $response = $this->withToken($token)
            ->withHeader('X-Tenant-Id', $tenantStranger->id)
            ->getJson('/api/auth/me');

        $response->assertStatus(200);

        // last_active_tenant_id must NOT be set to the stranger tenant.
        $user->refresh();
        $this->assertNotSame($tenantStranger->id, (string) $user->last_active_tenant_id);
    }

    public function test_last_active_tenant_id_is_used_on_subsequent_requests(): void
    {
        $this->seedAccountOwnerRole();

        $tenantA = $this->makeTenant('Organization A');
        $tenantB = $this->makeTenant('Organization B');

        $user = User::create(['name' => 'Sticky', 'email' => 'sticky@example.com', 'password' => Hash::make('secret')]);
        $user->tenants()->attach([$tenantA->id, $tenantB->id]);
        $user->update(['last_active_tenant_id' => $tenantB->id]);
        $user->assignRole(Role::SLUG_ACCOUNT_OWNER);

        $token = $user->createToken('test', ['*'])->plainTextToken;

        // No X-Tenant-Id header: should use last_active_tenant_id (B).
        $response = $this->withToken($token)->getJson('/api/auth/me');
        $response->assertStatus(200);

        // The active tenant in response should be B.
        $activeTenant = $response->json('data.tenant');
        $this->assertSame($tenantB->id, $activeTenant['id']);
    }

    // -------------------------------------------------------------------------
    // Refresh — auth_active re-check (security)
    // -------------------------------------------------------------------------

    public function test_deactivated_user_cannot_refresh_and_tokens_are_revoked(): void
    {
        // Regression: refresh() must re-check auth_active, otherwise a deactivated
        // user keeps minting access tokens until the refresh token expires (~7d).
        $user = User::create([
            'name' => 'Disabled',
            'email' => 'disabled@example.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => false,
        ]);
        $refresh = $user->createToken('refresh_token', ['refresh'])->plainTextToken;

        $response = $this->withToken($refresh)->postJson('/api/auth/refresh');

        $response->assertStatus(403);
        $this->assertSame(0, $user->fresh()->tokens()->count(), 'All tokens must be revoked on deactivated refresh.');
    }

    public function test_active_user_can_refresh_token(): void
    {
        $user = User::create([
            'name' => 'Active',
            'email' => 'active@example.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);
        $refresh = $user->createToken('refresh_token', ['refresh'])->plainTextToken;

        $response = $this->withToken($refresh)->postJson('/api/auth/refresh');

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.access_token'));
    }
}
