<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use QCH\Core\Models\Role;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * A 7-day refresh token must never work as a Bearer access token on a
 * clinical route, and a deactivated user's still-valid access token must be
 * rejected on every authenticated request (plan 002, Part A).
 *
 * The `permission:` middleware only checks Spatie role permissions, never
 * token abilities, so the administrator here carries REAL permissions
 * (mirrors `WithScreeningFixtures::administratorToken()`). A pass on the
 * leaked refresh token can therefore only mean `rejectRefreshToken` is
 * missing from the route -- never a permission gap standing in for it.
 */
final class RefreshTokenRejectionTest extends TestCase
{
    use RefreshDatabase;
    use WithScreeningFixtures;
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

    public function test_refresh_token_cannot_list_or_score_screenings(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $admin->assignRole(Role::SLUG_ADMINISTRATOR);
        $adminToken = $admin->createToken('admin', $admin->getPermissionsViaRoles()->pluck('name')->toArray())->plainTextToken;

        $version = $this->defaultVersion();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));

        $screeningId = $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id])
            ->assertCreated()
            ->json('data.id');

        $answers = $this->g10GreenAnswers($version);
        $this->tenantRequest($adminToken, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])
            ->assertOk();

        // A stolen/leaked refresh token for the SAME administrator. Ability
        // list and token name mirror exactly how AuthController::generateTokens()
        // issues one.
        $leaked = $admin->createToken('refresh_token', ['refresh'])->plainTextToken;

        $this->tenantRequest($leaked, $tenant)
            ->getJson('/api/screening')
            ->assertStatus(401);

        $this->tenantRequest($leaked, $tenant)
            ->postJson("/api/screening/{$screeningId}/score")
            ->assertStatus(401);
    }

    public function test_deactivated_user_access_token_cannot_read_children(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $user = $this->makeUser($tenant);
        $token = $user->createToken('auth_token', ['*'])->plainTextToken;

        $user->update(['auth_active' => false]);

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/child')
            ->assertStatus(403);
    }

    /**
     * Guard rail: the refresh endpoint itself must keep accepting refresh
     * tokens -- Part A must never touch `backend/routes/api.php`.
     */
    public function test_refresh_endpoint_still_accepts_refresh_token(): void
    {
        $user = User::create([
            'name' => 'Refresh Smoke',
            'email' => 'refresh-smoke@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $refresh = $user->createToken('refresh_token', ['refresh'])->plainTextToken;

        $this->withToken($refresh)
            ->postJson('/api/auth/refresh')
            ->assertOk()
            ->assertJsonPath('data.access_token', fn ($value) => is_string($value) && $value !== '');
    }
}
