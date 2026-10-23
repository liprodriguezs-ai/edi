<?php

declare(strict_types=1);

namespace Tests\Feature\UserProfile;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Tenant\Models\Tenant;
use Modules\UserProfile\Models\UserProfile;
use QCH\Core\Models\Role;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

/**
 * UserProfile module — tenant isolation, unique constraint, and CRUD tests.
 */
final class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    private function makeUser(?string $email = null): User
    {
        return User::create([
            'name' => 'Test Resident',
            'email' => $email ?? fake()->unique()->safeEmail(),
            'password' => Hash::make('secret'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Tenant isolation
    // -------------------------------------------------------------------------

    public function test_profile_created_in_tenant_a_is_not_visible_in_tenant_b(): void
    {
        $tenantA = $this->makeTenant('Tenant A');
        $tenantB = $this->makeTenant('Tenant B');
        $user = $this->makeUser();

        $tenantA->run(function () use ($user): void {
            UserProfile::create(['user_id' => $user->id, 'notes' => 'Tenant A resident']);
        });

        $tenantB->run(function (): void {
            $this->assertSame(0, UserProfile::count(), 'Profile from Tenant A must not be visible in Tenant B.');
        });
    }

    public function test_same_user_can_have_a_profile_in_two_different_tenants(): void
    {
        $tenantA = $this->makeTenant('Tenant A');
        $tenantB = $this->makeTenant('Tenant B');
        $user = $this->makeUser();

        $tenantA->run(function () use ($user): void {
            UserProfile::create(['user_id' => $user->id, 'notes' => 'Profile in A']);
        });

        $tenantB->run(function () use ($user): void {
            UserProfile::create(['user_id' => $user->id, 'notes' => 'Profile in B']);
            $this->assertSame(1, UserProfile::count());
        });
    }

    // -------------------------------------------------------------------------
    // Unique constraint: one profile per user per tenant
    // -------------------------------------------------------------------------

    public function test_duplicate_user_profile_within_same_tenant_violates_unique_constraint(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        $tenant = $this->makeTenant();
        $user = $this->makeUser();

        $tenant->run(function () use ($user): void {
            UserProfile::create(['user_id' => $user->id, 'notes' => 'First']);
            UserProfile::create(['user_id' => $user->id, 'notes' => 'Second']); // must throw
        });
    }

    // -------------------------------------------------------------------------
    // Basic CRUD round-trip
    // -------------------------------------------------------------------------

    public function test_user_profile_crud_round_trip(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser();

        $tenant->run(function () use ($user): void {
            $profile = UserProfile::create([
                'user_id' => $user->id,
                'rfc' => 'XAXX010101000',
                'curp' => 'XAXX010101HDFXXX00',
                'birthdate' => '1990-05-15',
                'address' => 'Calle Falsa 123',
                'emergency_contact_name' => 'María',
                'emergency_contact_phone' => '5551234567',
                'notes' => 'Notas del residente',
            ]);

            $this->assertNotNull($profile->id);
            $this->assertSame('XAXX010101000', $profile->rfc);
            $this->assertSame('1990-05-15', $profile->birthdate->format('Y-m-d'));

            $profile->update(['notes' => 'Notas actualizadas']);
            $profile->refresh();
            $this->assertSame('Notas actualizadas', $profile->notes);

            $profile->delete();
            $this->assertSame(0, UserProfile::count(), 'Soft delete should remove from default scope.');
            $this->assertSame(1, UserProfile::withTrashed()->count(), 'Row must be retained after soft delete.');
        });
    }

    public function test_user_profile_user_relation_resolves_correctly(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser('profile_user@example.com');

        $tenant->run(function () use ($user): void {
            $profile = UserProfile::create(['user_id' => $user->id]);
            $profile->refresh();

            $this->assertNotNull($profile->user);
            $this->assertSame($user->id, $profile->user->id);
        });
    }

    // -------------------------------------------------------------------------
    // API endpoint (HTTP level)
    // -------------------------------------------------------------------------

    public function test_user_profile_api_search_returns_200(): void
    {
        $this->seedAccountOwnerRole();
        $tenant = $this->makeTenant('API Tenant');
        $user = User::create([
            'name' => 'API User',
            'email' => 'api@example.com',
            'password' => Hash::make('secret'),
            'auth_active' => true,
        ]);
        $user->tenants()->attach($tenant->id);
        $user->assignRole(Role::SLUG_ACCOUNT_OWNER);

        // Create token with wildcard ability — bypasses Orion tokenCan check.
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $response = $this->withToken($token)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson('/api/user_profile/search', []);

        // The search endpoint should return 200 (empty list — no profiles yet).
        $response->assertStatus(200);
    }
}
