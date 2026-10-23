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
 * Welcome onboarding completion flag (§5.3.5, auth-08).
 *
 * `user.onboarding_completed_at` is the canonical EDI flag for "the user
 * saw the welcome slides"; it is set once via POST auth/complete-onboarding.
 */
final class OnboardingCompletionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /**
     * @return array{0: User, 1: string}
     */
    private function makeAuthenticatedUser(): array
    {
        \App\Models\Role::firstOrCreate(['name' => Role::SLUG_ACCOUNT_OWNER, 'guard_name' => 'api']);

        $tenant = Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid7(),
                'name' => 'Onboarding '.Str::random(4),
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );

        $user = User::create([
            'name' => 'Onboard',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);
        $user->tenants()->attach($tenant->id);
        $user->assignRole(Role::SLUG_ACCOUNT_OWNER);

        $token = $user->createToken('auth_token', ['*'], now()->addHour())->plainTextToken;

        return [$user, $token];
    }

    public function test_complete_onboarding_sets_the_timestamp(): void
    {
        [$user, $token] = $this->makeAuthenticatedUser();

        $this->assertNull($user->onboarding_completed_at);

        $this->withToken($token)->postJson('/api/auth/complete-onboarding')->assertStatus(200);

        $user->refresh();
        $this->assertNotNull($user->onboarding_completed_at);
    }

    public function test_complete_onboarding_is_idempotent_first_timestamp_wins(): void
    {
        [$user, $token] = $this->makeAuthenticatedUser();

        $this->withToken($token)->postJson('/api/auth/complete-onboarding')->assertStatus(200);
        $first = $user->refresh()->onboarding_completed_at;

        $this->travel(5)->minutes();
        $this->withToken($token)->postJson('/api/auth/complete-onboarding')->assertStatus(200);
        $this->travelBack();

        $this->assertTrue($first->equalTo($user->refresh()->onboarding_completed_at));
    }
}
