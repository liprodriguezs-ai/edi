<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\TermsAcceptance;
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
 * Terms & Conditions acceptance (§18.10, §5).
 */
final class TermsAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private function makeUserWithTenant(string $email = 'terms@example.com'): array
    {
        Role::query(); // ensure model autoload
        \App\Models\Role::firstOrCreate(['name' => Role::SLUG_ACCOUNT_OWNER, 'guard_name' => 'api']);

        $tenant = Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => 'T '.Str::random(4),
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );

        $user = User::create([
            'name' => 'Terms User',
            'email' => $email,
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);
        $user->tenants()->attach($tenant->id);
        $user->assignRole(Role::SLUG_ACCOUNT_OWNER);

        return [$user, $tenant];
    }

    public function test_me_shows_terms_not_accepted_before_acceptance(): void
    {
        [$user] = $this->makeUserWithTenant('before@example.com');
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/auth/me')->assertStatus(200);

        $this->assertFalse($response->json('data.terms_accepted'));
        $this->assertSame((string) config('legal.terms_version', '1.0'), $response->json('data.terms_version'));
    }

    public function test_accept_terms_records_acceptance_and_me_reflects_it(): void
    {
        [$user] = $this->makeUserWithTenant('accept@example.com');
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/accept-terms')
            ->assertStatus(200)
            ->assertJsonPath('data.terms_accepted', true);

        $this->assertDatabaseHas('terms_acceptance', [
            'user_id' => $user->id,
            'version' => (string) config('legal.terms_version', '1.0'),
        ]);

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.terms_accepted', true);
    }

    public function test_accept_terms_is_idempotent_for_same_version(): void
    {
        [$user] = $this->makeUserWithTenant('idem@example.com');
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/accept-terms')->assertStatus(200);
        $this->withToken($token)->postJson('/api/auth/accept-terms')->assertStatus(200);

        $count = TermsAcceptance::where('user_id', $user->id)->count();
        $this->assertSame(1, $count, 'Re-accepting the same version must not create a second row');
    }

    public function test_new_version_requires_re_acceptance(): void
    {
        [$user] = $this->makeUserWithTenant('newver@example.com');
        $token = $user->createToken('test', ['*'])->plainTextToken;

        // Accept v1.0.
        config(['legal.terms_version' => '1.0']);
        $this->withToken($token)->postJson('/api/auth/accept-terms')->assertStatus(200);
        $this->withToken($token)->getJson('/api/auth/me')->assertJsonPath('data.terms_accepted', true);

        // Bump the current version → user has NOT accepted it yet.
        config(['legal.terms_version' => '2.0']);
        $this->withToken($token)->getJson('/api/auth/me')->assertJsonPath('data.terms_accepted', false);

        // Accept v2.0 → now accepted, and a SECOND row exists.
        $this->withToken($token)->postJson('/api/auth/accept-terms')->assertStatus(200);
        $this->withToken($token)->getJson('/api/auth/me')->assertJsonPath('data.terms_accepted', true);

        $this->assertSame(2, TermsAcceptance::where('user_id', $user->id)->count());
    }

    public function test_accept_terms_requires_authentication(): void
    {
        $this->postJson('/api/auth/accept-terms')->assertStatus(401);
    }
}
