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
 * Rate limiting on auth/login (spec §18.9).
 *
 * The `auth-login` limiter applies two buckets: 5/min per (email|ip) to block
 * brute force against one account, and 20/min per ip to block credential
 * spraying across many accounts.
 */
final class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    // Note: the base TestCase flushes the cache in setUp(), which clears the
    // auth-login rate-limiter counters between tests.

    private function seedAccountOwnerRole(): \App\Models\Role
    {
        return \App\Models\Role::firstOrCreate(
            ['name' => Role::SLUG_ACCOUNT_OWNER, 'guard_name' => 'api']
        );
    }

    private function makeUserWithTenant(string $email, string $password = 'secret1234'): User
    {
        $this->seedAccountOwnerRole();

        $tenant = Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => 'RL Tenant '.Str::random(4),
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );

        $user = User::create([
            'name' => 'RL',
            'email' => $email,
            'password' => Hash::make($password),
            'auth_active' => true,
        ]);
        $user->tenants()->attach($tenant->id);
        $user->assignRole(Role::SLUG_ACCOUNT_OWNER);

        return $user;
    }

    public function test_login_blocks_after_five_failed_attempts_for_same_email(): void
    {
        $this->makeUserWithTenant('victim@example.com');

        // 5 wrong-password attempts are allowed through (each returns 401).
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'victim@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        // The 6th is throttled, NOT a 401.
        $this->postJson('/api/auth/login', [
            'email' => 'victim@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_login_rate_limit_is_scoped_per_email_not_global(): void
    {
        $this->makeUserWithTenant('account-a@example.com');
        $this->makeUserWithTenant('account-b@example.com');

        // Exhaust the per-account bucket for account A.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'account-a@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }
        $this->postJson('/api/auth/login', [
            'email' => 'account-a@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);

        // Account B (same IP, different email) is still under both buckets → reaches auth (401), not throttled.
        $this->postJson('/api/auth/login', [
            'email' => 'account-b@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_login_ip_bucket_blocks_spray_across_many_emails(): void
    {
        // 20 attempts spread across distinct emails (≤5 each, so the per-email
        // bucket never trips) must still trip the 20/min per-IP bucket.
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => "spray{$i}@example.com",
                'password' => 'wrong-password',
            ]);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'spray-final@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_successful_login_works_under_the_limit(): void
    {
        $this->makeUserWithTenant('valid@example.com', 'secret1234');

        $this->postJson('/api/auth/login', [
            'email' => 'valid@example.com',
            'password' => 'secret1234',
        ])->assertStatus(200);
    }
}
