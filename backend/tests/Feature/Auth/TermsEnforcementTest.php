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
 * Server-side T&C enforcement (§18.10 — global-08), `termsAccepted` middleware.
 *
 * When config legal.terms_version is bumped, a user that accepted an OLDER
 * version gets 426 TERMS_OUTDATED on WRITE endpoints of the User module until
 * they re-accept. Reads always pass, the acceptance endpoint always passes, and
 * users with NO recorded acceptance are deliberately not blocked (first-time
 * acceptance is funneled by registration/onboarding + the /me flag).
 */
final class TermsEnforcementTest extends TestCase
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

    /**
     * @return array{0: User, 1: string} user + plain-text token
     */
    private function makeOwnerWithToken(string $email): array
    {
        \App\Models\Role::firstOrCreate(['name' => Role::SLUG_ACCOUNT_OWNER, 'guard_name' => 'api']);

        $tenant = Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid7(),
                'name' => 'Terms '.Str::random(4),
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );

        $user = User::create([
            'name' => 'Enforcement User',
            'email' => $email,
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);
        $user->tenants()->attach($tenant->id);
        $user->assignRole(Role::SLUG_ACCOUNT_OWNER);

        return [$user, $user->createToken('test', ['*'])->plainTextToken];
    }

    private function freshRequest(string $token): self
    {
        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        return $this->withToken($token);
    }

    public function test_write_with_outdated_acceptance_returns_426(): void
    {
        [, $token] = $this->makeOwnerWithToken('outdated-post@example.com');

        config(['legal.terms_version' => '1.0']);
        $this->freshRequest($token)->postJson('/api/auth/accept-terms')->assertStatus(200);

        config(['legal.terms_version' => '2.0']);

        $this->freshRequest($token)
            ->postJson('/api/user', ['name' => 'Nuevo'])
            ->assertStatus(426)
            ->assertJsonPath('code', 'TERMS_OUTDATED')
            ->assertJsonPath('current_version', '2.0');
    }

    public function test_read_passes_with_outdated_acceptance(): void
    {
        [, $token] = $this->makeOwnerWithToken('outdated-get@example.com');

        config(['legal.terms_version' => '1.0']);
        $this->freshRequest($token)->postJson('/api/auth/accept-terms')->assertStatus(200);

        config(['legal.terms_version' => '2.0']);

        $this->freshRequest($token)->getJson('/api/user')->assertStatus(200);
    }

    public function test_acceptance_endpoint_passes_and_unblocks_writes(): void
    {
        [, $token] = $this->makeOwnerWithToken('reaccept@example.com');

        config(['legal.terms_version' => '1.0']);
        $this->freshRequest($token)->postJson('/api/auth/accept-terms')->assertStatus(200);

        config(['legal.terms_version' => '2.0']);

        $this->freshRequest($token)
            ->postJson('/api/user', ['name' => 'Nuevo'])
            ->assertStatus(426);

        $this->freshRequest($token)->postJson('/api/auth/accept-terms')->assertStatus(200);

        $response = $this->freshRequest($token)->postJson('/api/user', ['name' => 'Nuevo']);

        $this->assertNotSame(426, $response->getStatusCode());
    }

    public function test_write_passes_when_acceptance_is_current(): void
    {
        [, $token] = $this->makeOwnerWithToken('current@example.com');

        config(['legal.terms_version' => '1.0']);
        $this->freshRequest($token)->postJson('/api/auth/accept-terms')->assertStatus(200);

        $response = $this->freshRequest($token)->postJson('/api/user', ['name' => 'Nuevo']);

        $this->assertNotSame(426, $response->getStatusCode());
    }

    public function test_user_without_any_recorded_acceptance_is_not_blocked(): void
    {
        [, $token] = $this->makeOwnerWithToken('legacy@example.com');

        config(['legal.terms_version' => '2.0']);

        $response = $this->freshRequest($token)->postJson('/api/user', ['name' => 'Nuevo']);

        $this->assertNotSame(
            426,
            $response->getStatusCode(),
            'Enforcement targets stale acceptances; pre-feature accounts must not be locked out',
        );
    }
}
