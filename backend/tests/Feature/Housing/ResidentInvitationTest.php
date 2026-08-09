<?php

declare(strict_types=1);

namespace Tests\Feature\Housing;

use App\Models\Role as AppRole;
use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Access\Models\BlacklistEntry;
use Modules\Email\Emails\ResidentInvitationMail;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitUser;
use Modules\Tenant\Models\Tenant;
use Modules\User\Models\UserInvitation;
use Modules\UserProfile\Models\UserProfile;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Resident invitation flow: owners/admins invite by email, the invitee activates
 * an account (or links an existing one), cross-unit warnings are non-blocking, and
 * admins can block residents. Security: owner gate, IDOR, hashed tokens.
 */
final class ResidentInvitationTest extends TestCase
{
    use HousingTestHelpers;
    use RefreshDatabase;

    public function test_owner_invites_resident_new_account_happy_path(): void
    {
        Mail::fake();
        [$tenant, $owner, $ownerToken, $unit] = $this->ownerWithUnit();
        $this->seedResidentRoles();

        $email = 'nuevo+'.Str::random(6).'@vecinia.mx';

        $response = $this->tenantRequest($ownerToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $email,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::FAMILY_RESIDENT,
            ]);

        $response->assertCreated();
        $this->assertSame([], $response->json('warnings'));
        $this->assertArrayNotHasKey('token', $response->json('data'));

        $invitation = UserInvitation::query()->where('email', $email)->firstOrFail();
        $this->assertSame(Unit::class, $invitation->inviteable_type);
        $this->assertNull($invitation->token);
        $this->assertNotNull($invitation->token_hash);
        $this->assertSame($owner->id, $invitation->invited_by_user_id);
        $this->assertSame(UnitRelationship::FAMILY_RESIDENT, $invitation->unit_relationship_id);

        $plain = $this->latestResidentToken();

        $this->publicRequest()
            ->postJson('/api/auth/accept-invitation', [
                'token' => $plain,
                'password' => 'secret1234',
                'password_confirmation' => 'secret1234',
            ])
            ->assertOk();

        $this->inTenant($tenant, function () use ($email, $unit): void {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $user = User::withoutGlobalScope(UserTenantScope::class)->where('email', $email)->firstOrFail();
            $this->assertTrue((bool) $user->auth_active);
            $this->assertTrue($user->hasRole('resident'));
            $this->assertTrue(UserProfile::where('user_id', $user->id)->exists());

            $membership = UnitUser::where('unit_id', $unit->id)
                ->where('user_id', $user->id)
                ->whereNull('end_date')
                ->first();
            $this->assertNotNull($membership);
            $this->assertSame(UnitRelationship::FAMILY_RESIDENT, $membership->unit_relationship_id);
        });

        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_non_owner_cannot_invite(): void
    {
        Mail::fake();
        [$tenant, , , $unit] = $this->ownerWithUnit();

        $stranger = $this->makeUser($tenant);
        $strangerToken = $this->tokenFor($stranger, []);

        $this->tenantRequest($strangerToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => 'x@vecinia.mx',
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::FAMILY_RESIDENT,
            ])
            ->assertForbidden();
    }

    public function test_duplicate_in_same_unit_is_rejected(): void
    {
        Mail::fake();
        [$tenant, , $ownerToken, $unit] = $this->ownerWithUnit();

        $existing = $this->makeUser($tenant);
        $this->attachUnitUser($tenant, $unit, $existing, UnitRelationship::FAMILY_RESIDENT, [
            'is_billing_owner' => false,
            'is_legal_representative' => false,
        ]);

        $this->tenantRequest($ownerToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $existing->email,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::FAMILY_RESIDENT,
            ])
            ->assertStatus(422);
    }

    public function test_same_tenant_other_unit_produces_non_blocking_warning(): void
    {
        Mail::fake();
        [$tenant, , $ownerToken, $unitB] = $this->ownerWithUnit();

        $unitA = $this->makeUnit($tenant);
        $person = $this->makeUser($tenant);
        $this->attachUnitUser($tenant, $unitA, $person, UnitRelationship::FAMILY_RESIDENT, [
            'is_billing_owner' => false,
            'is_legal_representative' => false,
        ]);

        $response = $this->tenantRequest($ownerToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $person->email,
                'unit_id' => $unitB->id,
                'unit_relationship_id' => UnitRelationship::FAMILY_RESIDENT,
            ]);

        $response->assertCreated();
        $this->assertNotEmpty($response->json('warnings'));
    }

    public function test_expired_invitation_cannot_be_accepted(): void
    {
        Mail::fake();
        [$tenant, $owner, , $unit] = $this->ownerWithUnit();

        $plain = Str::random(64);
        $this->inTenant($tenant, function () use ($plain, $tenant, $owner, $unit): void {
            UserInvitation::create([
                'token' => null,
                'token_hash' => hash('sha256', $plain),
                'tenant_id' => $tenant->id,
                'inviteable_type' => Unit::class,
                'inviteable_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::FAMILY_RESIDENT,
                'email' => 'late@vecinia.mx',
                'invited_by_user_id' => $owner->id,
                'expires_at' => now()->subDay(),
            ]);
        });

        $this->publicRequest()
            ->postJson('/api/auth/verify-invitation-token', ['token' => $plain])
            ->assertOk()
            ->assertJson(['valid' => false]);

        $this->publicRequest()
            ->postJson('/api/auth/accept-invitation', [
                'token' => $plain,
                'password' => 'secret1234',
                'password_confirmation' => 'secret1234',
            ])
            ->assertStatus(400);
    }

    public function test_owner_can_revoke_pending_invitation(): void
    {
        Mail::fake();
        [$tenant, , $ownerToken, $unit] = $this->ownerWithUnit();

        $email = 'revoke+'.Str::random(5).'@vecinia.mx';
        $this->tenantRequest($ownerToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $email,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::FAMILY_RESIDENT,
            ])->assertCreated();

        $invitation = UserInvitation::where('email', $email)->firstOrFail();
        $plain = $this->latestResidentToken();

        $this->tenantRequest($ownerToken, $tenant)
            ->deleteJson('/api/resident-invitation/'.$invitation->id)
            ->assertOk();

        $this->assertNotNull($invitation->fresh()->revoked_at);

        $this->publicRequest()
            ->postJson('/api/auth/accept-invitation', [
                'token' => $plain,
                'password' => 'secret1234',
                'password_confirmation' => 'secret1234',
            ])
            ->assertStatus(400);
    }

    public function test_resend_rotates_the_token(): void
    {
        Mail::fake();
        [$tenant, , $ownerToken, $unit] = $this->ownerWithUnit();

        $email = 'resend+'.Str::random(5).'@vecinia.mx';
        $this->tenantRequest($ownerToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $email,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::FAMILY_RESIDENT,
            ])->assertCreated();

        $firstToken = $this->latestResidentToken();
        $invitation = UserInvitation::where('email', $email)->firstOrFail();

        $this->tenantRequest($ownerToken, $tenant)
            ->postJson('/api/resident-invitation/'.$invitation->id.'/resend')
            ->assertOk();

        $secondToken = $this->latestResidentToken();
        $this->assertNotSame($firstToken, $secondToken);

        $this->publicRequest()
            ->postJson('/api/auth/verify-invitation-token', ['token' => $firstToken])
            ->assertOk()->assertJson(['valid' => false]);

        $this->publicRequest()
            ->postJson('/api/auth/verify-invitation-token', ['token' => $secondToken])
            ->assertOk()->assertJson(['valid' => true]);
    }

    public function test_owner_can_deactivate_membership(): void
    {
        Mail::fake();
        [$tenant, , $ownerToken, $unit] = $this->ownerWithUnit();

        $resident = $this->makeUser($tenant);
        $membership = $this->attachUnitUser($tenant, $unit, $resident, UnitRelationship::FAMILY_RESIDENT, [
            'is_billing_owner' => false,
            'is_legal_representative' => false,
        ]);

        $this->tenantRequest($ownerToken, $tenant)
            ->postJson('/api/unit-membership/'.$membership->id.'/deactivate')
            ->assertOk();

        $this->inTenant($tenant, function () use ($membership): void {
            $this->assertNotNull(UnitUser::findOrFail($membership->id)->end_date);
        });
    }

    public function test_admin_block_disables_account_and_blacklists(): void
    {
        Mail::fake();
        [$tenant, , , $unit] = $this->ownerWithUnit();

        $admin = $this->makeUser($tenant);
        $adminToken = $this->tokenFor($admin, ['block.resident']);

        $target = $this->makeUser($tenant);
        $this->attachUnitUser($tenant, $unit, $target, UnitRelationship::FAMILY_RESIDENT, [
            'is_billing_owner' => false,
            'is_legal_representative' => false,
        ]);

        $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/resident/'.$target->id.'/block', [
                'reason' => 'Conducta reportada por la mesa directiva.',
            ])
            ->assertOk();

        $this->assertFalse((bool) User::withoutGlobalScope(UserTenantScope::class)->findOrFail($target->id)->auth_active);
        $this->inTenant($tenant, function (): void {
            $this->assertTrue(BlacklistEntry::query()->where('is_active', true)->exists());
        });

        // Non-admin cannot block.
        $stranger = $this->makeUser($tenant);
        $strangerToken = $this->tokenFor($stranger, []);
        $this->tenantRequest($strangerToken, $tenant)
            ->postJson('/api/resident/'.$target->id.'/block', ['reason' => 'intento no autorizado.'])
            ->assertForbidden();
    }

    public function test_existing_account_confirm_links_and_rejects_email_mismatch(): void
    {
        Mail::fake();
        $this->seedResidentRoles();
        [$tenant, $owner, $ownerToken, $unit] = $this->ownerWithUnit();

        // Existing account (already has a password) belonging elsewhere.
        $existing = $this->makeUser($tenant);

        $this->tenantRequest($ownerToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $existing->email,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::FAMILY_RESIDENT,
            ])->assertCreated();

        $plain = $this->latestResidentToken();

        // Wrong identity → 403.
        $stranger = $this->makeUser($tenant);
        $this->authRequest($this->tokenFor($stranger, []))
            ->postJson('/api/auth/accept-invitation/confirm', ['token' => $plain])
            ->assertForbidden();

        // Correct identity links the existing account to the unit.
        $this->authRequest($this->tokenFor($existing, []))
            ->postJson('/api/auth/accept-invitation/confirm', ['token' => $plain])
            ->assertOk();

        $this->inTenant($tenant, function () use ($existing, $unit): void {
            $this->assertTrue(
                UnitUser::where('unit_id', $unit->id)
                    ->where('user_id', $existing->id)
                    ->whereNull('end_date')
                    ->exists()
            );
        });
    }

    public function test_owner_cannot_manage_invitation_of_another_unit(): void
    {
        Mail::fake();
        [$tenant, , $ownerAToken, $unitA] = $this->ownerWithUnit();

        // A different owner + unit in the SAME tenant.
        $ownerB = $this->makeUser($tenant);
        $unitB = $this->makeUnit($tenant);
        $this->attachUnitUser($tenant, $unitB, $ownerB, UnitRelationship::OWNER);

        $email = 'cross+'.Str::random(5).'@vecinia.mx';
        $this->tenantRequest($ownerAToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $email,
                'unit_id' => $unitA->id,
                'unit_relationship_id' => UnitRelationship::FAMILY_RESIDENT,
            ])->assertCreated();

        $invitation = UserInvitation::where('email', $email)->firstOrFail();
        $ownerBToken = $this->tokenFor($ownerB, []);

        // Owner B is not an owner of unit A → cannot revoke A's invitation.
        $this->tenantRequest($ownerBToken, $tenant)
            ->deleteJson('/api/resident-invitation/'.$invitation->id)
            ->assertForbidden();
    }

    public function test_public_accept_rejects_existing_password_account(): void
    {
        Mail::fake();
        [$tenant, , $ownerToken, $unit] = $this->ownerWithUnit();

        // makeUser creates an account WITH a password.
        $existing = $this->makeUser($tenant);

        $this->tenantRequest($ownerToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $existing->email,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::FAMILY_RESIDENT,
            ])->assertCreated();

        $plain = $this->latestResidentToken();

        // The public password flow must refuse an existing-password account: the
        // person has to log in (authenticated confirm flow) to prove control.
        $this->publicRequest()
            ->postJson('/api/auth/accept-invitation', [
                'token' => $plain,
                'password' => 'secret1234',
                'password_confirmation' => 'secret1234',
            ])
            ->assertStatus(409);
    }

    public function test_resend_rejected_after_acceptance(): void
    {
        Mail::fake();
        $this->seedResidentRoles();
        [$tenant, , $ownerToken, $unit] = $this->ownerWithUnit();

        $email = 'accepted+'.Str::random(5).'@vecinia.mx';
        $this->tenantRequest($ownerToken, $tenant)->postJson('/api/resident-invitation', [
            'email' => $email,
            'unit_id' => $unit->id,
            'unit_relationship_id' => UnitRelationship::FAMILY_RESIDENT,
        ])->assertCreated();

        $invitation = UserInvitation::where('email', $email)->firstOrFail();
        $plain = $this->latestResidentToken();

        $this->publicRequest()->postJson('/api/auth/accept-invitation', [
            'token' => $plain,
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ])->assertOk();

        // A consumed invitation cannot be re-armed via resend (would revive the token).
        $this->tenantRequest($ownerToken, $tenant)
            ->postJson('/api/resident-invitation/'.$invitation->id.'/resend')
            ->assertNotFound();
    }

    public function test_block_rejects_user_from_another_tenant(): void
    {
        Mail::fake();
        [$tenantA] = $this->ownerWithUnit();

        $admin = $this->makeUser($tenantA);
        $adminToken = $this->tokenFor($admin, ['block.resident']);

        // An outsider who belongs ONLY to another cluster.
        $tenantB = $this->makeTenant('Other Tenant');
        $this->seedTenant($tenantB);
        $outsider = $this->makeUser($tenantB);

        $this->tenantRequest($adminToken, $tenantA)
            ->postJson('/api/resident/'.$outsider->id.'/block', [
                'reason' => 'No debería poder bloquear a alguien de otro condominio.',
            ])
            ->assertNotFound();
    }

    // ----------------------------------------------------------------------

    /**
     * Fresh tenant with one unit and an active owner (+ owner access token).
     *
     * @return array{0: Tenant, 1: User, 2: string, 3: Unit}
     */
    private function ownerWithUnit(): array
    {
        $tenant = $this->makeTenant('Invitation Tenant');
        $this->seedTenant($tenant);

        $owner = $this->makeUser($tenant);
        $unit = $this->makeUnit($tenant);
        $this->attachUnitUser($tenant, $unit, $owner, UnitRelationship::OWNER);

        return [$tenant, $owner, $this->tokenFor($owner, []), $unit];
    }

    private function seedResidentRoles(): void
    {
        foreach (['resident', 'renter'] as $slug) {
            AppRole::firstOrCreate(['name' => $slug, 'guard_name' => 'api']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function latestResidentToken(): string
    {
        // System mailables implement ShouldQueue, so under Mail::fake() they land
        // in the queued bucket — read from there, not from Mail::sent().
        $mail = Mail::queued(ResidentInvitationMail::class)->last();
        $this->assertNotNull($mail, 'A resident invitation email must have been queued.');

        return Str::afterLast($mail->acceptUrl, '/');
    }

    private function publicRequest(): static
    {
        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        return $this;
    }

    private function authRequest(string $token): static
    {
        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        $this->withToken($token);

        return $this;
    }
}
