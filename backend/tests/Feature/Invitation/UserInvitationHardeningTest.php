<?php

declare(strict_types=1);

namespace Tests\Feature\Invitation;

use App\Models\Role as AppRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Collaborator\Models\Collaborator;
use Modules\Collaborator\Models\EmployeePosition;
use Modules\Email\Emails\UserInvitationMail;
use Modules\Tenant\Models\Tenant;
use Modules\User\Models\UserInvitation;
use Modules\User\Services\UserInvitationService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Security hardening for user invitations (resident + collaborator share the engine):
 *  - tokens are stored as SHA-256 hashes, never plaintext
 *  - every invitation gets an expiry (TTL_DAYS)
 *  - lookups match by hash, with a legacy fallback to plaintext rows
 */
final class UserInvitationHardeningTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    public function test_invitation_token_is_hashed_at_rest_with_expiry(): void
    {
        Mail::fake();
        $tenant = $this->makeTenant('Hardening Tenant');
        $this->seedTenant($tenant);

        $plainToken = $this->createCollaboratorInvitation($tenant);

        $invitation = UserInvitation::query()->firstOrFail();
        $this->assertNull($invitation->token, 'Plaintext token must not be persisted.');
        $this->assertSame(hash('sha256', $plainToken), $invitation->token_hash);
        $this->assertNotNull($invitation->expires_at, 'Every invitation must have an expiry.');
        $this->assertEqualsWithDelta(
            now()->addDays(UserInvitation::ttlDays())->timestamp,
            $invitation->expires_at->timestamp,
            300,
        );
    }

    public function test_find_valid_by_token_matches_hash_and_rejects_wrong_token(): void
    {
        Mail::fake();
        $tenant = $this->makeTenant('Find Tenant');
        $this->seedTenant($tenant);
        $plainToken = $this->createCollaboratorInvitation($tenant);

        $service = app(UserInvitationService::class);
        $this->assertNotNull($service->findValidByToken($plainToken));
        $this->assertNull($service->findValidByToken('not-the-real-token'));
    }

    public function test_legacy_plaintext_token_still_resolves(): void
    {
        $tenant = $this->makeTenant('Legacy Tenant');
        $this->seedTenant($tenant);

        $legacyPlain = Str::random(64);
        $this->inTenant($tenant, function () use ($legacyPlain, $tenant): void {
            UserInvitation::create([
                'token' => $legacyPlain,
                'token_hash' => null,
                'tenant_id' => $tenant->id,
                'inviteable_type' => Collaborator::class,
                'inviteable_id' => (string) Str::uuid(),
                'email' => 'legacy@test.com',
                'expires_at' => now()->addDay(),
            ]);
        });

        $this->assertNotNull(
            app(UserInvitationService::class)->findValidByToken($legacyPlain),
            'Legacy plaintext-token invitations must remain resolvable.',
        );
    }

    public function test_expired_invitation_is_invalid(): void
    {
        $tenant = $this->makeTenant('Expired Tenant');
        $this->seedTenant($tenant);

        $plain = Str::random(64);
        $this->inTenant($tenant, function () use ($plain, $tenant): void {
            UserInvitation::create([
                'token' => null,
                'token_hash' => hash('sha256', $plain),
                'tenant_id' => $tenant->id,
                'inviteable_type' => Collaborator::class,
                'inviteable_id' => (string) Str::uuid(),
                'email' => 'expired@test.com',
                'expires_at' => now()->subDay(),
            ]);
        });

        $this->assertNull(app(UserInvitationService::class)->findValidByToken($plain));
    }

    private function createCollaboratorInvitation(Tenant $tenant): string
    {
        foreach (['security', 'operations_staff', 'collaborator'] as $slug) {
            AppRole::firstOrCreate(['name' => $slug, 'guard_name' => 'api']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->inTenant($tenant, static fn (): Collaborator => Collaborator::create([
            'name' => 'Inv',
            'last_name' => 'Tee',
            'email' => Str::random(8).'@test.com',
            'employee_position_id' => Catalog::id(EmployeePosition::class, 'Vigilante'),
            'create_as_user' => true,
            'is_active' => true,
        ]));

        $plain = null;
        // System mailables implement ShouldQueue, so under Mail::fake() they land
        // in the queued bucket — assert there, not on assertSent.
        Mail::assertQueued(UserInvitationMail::class, function (UserInvitationMail $mail) use (&$plain): bool {
            $plain = Str::afterLast($mail->acceptUrl, '/');

            return true;
        });

        $this->assertNotNull($plain, 'The invitation email must carry the plaintext token.');

        return $plain;
    }
}
