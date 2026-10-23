<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * `POST /api/user` must strip the same protected attributes the update path
 * already strips (sec-input-08). Before the fix, `UserController::performStore`
 * passed the raw request body straight into `UserService::create()`, which
 * `fill()`s it onto a new `User` -- every attribute below is `$fillable`, so
 * none of this was blocked by Eloquent guarding (plan 002, Part B).
 */
final class UserCreateMassAssignmentTest extends TestCase
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

    public function test_create_user_ignores_protected_attributes_in_the_payload(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $actor = $this->makeUser($tenant);
        $token = $actor->createToken('admin', ['create.user'])->plainTextToken;

        $otherUserId = $this->makeUser($tenant)->id;

        // `auth_active` defaults to true at the DB column level (see
        // 2026_05_31_062907_make_user_credentials_optional_and_add_auth_fields),
        // so a malicious `true` would be indistinguishable from the default.
        // Sending `false` instead makes the assertion meaningful: honoring it
        // would deactivate a brand-new account the attacker fully controls.
        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/user', [
                'name' => 'Cuenta Forjada',
                'email' => 'forjada@test.com',
                'password' => 'secret1234',
                'email_verified_at' => now()->toDateTimeString(),
                'auth_active' => false,
                'verification_code' => 'HACK',
                'user_created_id' => $otherUserId,
            ])
            ->assertCreated();

        $created = User::findOrFail($response->json('data.id'));

        $this->assertNull($created->email_verified_at, 'email_verified_at must never be settable through create.');
        $this->assertTrue($created->auth_active, 'auth_active must fall back to its column default, never the payload.');
        $this->assertNull($created->verification_code, 'verification_code must never be settable through create.');
        $this->assertSame(
            $actor->id,
            $created->user_created_id,
            'user_created_id must be stamped by the acting user, never accepted from the payload.'
        );
    }
}
