<?php

declare(strict_types=1);

namespace Tests\Feature\Housing;

use App\Models\Role as AppRole;
use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Email\Emails\ResidentInvitationMail;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitUser;
use Modules\Housing\Services\ResidentInvitationService;
use Modules\Notification\Models\NotificationUser;
use Modules\Tenant\Models\Tenant;
use Modules\User\Models\UserInvitation;
use ReflectionMethod;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * OWNER invite/accept for a vacant unit: admin-only + vacancy guards at invite
 * time, and routing acceptance through UnitUserService::changeOwner so single-
 * owner/billing invariants hold and its notification fires exactly once.
 *
 * Uses $connectionsToTransact = [] (no wrapping test transaction) so the
 * NotifiesResidents DB::afterCommit callbacks in changeOwner() actually fire —
 * same convention as NotificationWiringTest.php. TestCase::tearDown() truncates
 * the accumulating central tables (user, model_has_roles, model_has_permissions)
 * between tests; tenant-scoped rows never collide since every test mints its
 * own tenant.
 */
final class ResidentInvitationOwnerTest extends TestCase
{
    use HousingTestHelpers;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    public function test_admin_invites_owner_to_vacant_unit_succeeds(): void
    {
        Mail::fake();
        [$tenant, , $adminToken, $unit] = $this->vacantUnitWithAdmin();

        $email = 'owner+'.Str::random(6).'@vecinia.mx';

        $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $email,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ])
            ->assertCreated();

        $invitation = UserInvitation::query()->where('email', $email)->firstOrFail();
        $this->assertSame(UnitRelationship::OWNER, $invitation->unit_relationship_id);
        $this->assertNull($invitation->accepted_at);
    }

    public function test_self_service_owner_invite_via_active_owner_path_is_rejected(): void
    {
        Mail::fake();
        $tenant = $this->makeTenant('Owner Self Service');
        $this->seedTenant($tenant);

        $coOwner = $this->makeUser($tenant);
        $unit = $this->makeUnit($tenant);
        $this->attachUnitUser($tenant, $unit, $coOwner, UnitRelationship::CO_OWNER);
        $coOwnerToken = $this->tokenFor($coOwner, []);

        $email = 'blocked+'.Str::random(6).'@vecinia.mx';

        $this->tenantRequest($coOwnerToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $email,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unit_relationship_id']);

        $this->assertFalse(UserInvitation::query()->where('email', $email)->exists());
    }

    public function test_admin_owner_invite_to_occupied_unit_is_rejected(): void
    {
        Mail::fake();
        [$tenant, , $adminToken, $unit] = $this->vacantUnitWithAdmin();

        $existingOwner = $this->makeUser($tenant);
        $this->attachUnitUser($tenant, $unit, $existingOwner, UnitRelationship::OWNER);

        $email = 'second-owner+'.Str::random(6).'@vecinia.mx';

        $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $email,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ])
            ->assertStatus(422);

        $this->assertFalse(UserInvitation::query()->where('email', $email)->exists());
        $this->inTenant($tenant, function () use ($unit, $existingOwner): void {
            $this->assertTrue(
                UnitUser::where('unit_id', $unit->id)
                    ->where('user_id', $existingOwner->id)
                    ->where('unit_relationship_id', UnitRelationship::OWNER)
                    ->whereNull('end_date')
                    ->exists()
            );
        });
    }

    public function test_second_pending_owner_invite_different_email_is_rejected(): void
    {
        Mail::fake();
        [$tenant, , $adminToken, $unit] = $this->vacantUnitWithAdmin();

        $emailA = 'ownerA+'.Str::random(6).'@vecinia.mx';
        $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $emailA,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ])->assertCreated();

        $invitationA = UserInvitation::where('email', $emailA)->firstOrFail();

        $emailB = 'ownerB+'.Str::random(6).'@vecinia.mx';
        $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $emailB,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ])
            ->assertStatus(422);

        $this->assertFalse(UserInvitation::where('email', $emailB)->exists());
        $fresh = $invitationA->fresh();
        $this->assertNull($fresh->revoked_at);
        $this->assertNull($fresh->accepted_at);
    }

    public function test_second_pending_owner_invite_same_email_rotates_token(): void
    {
        Mail::fake();
        [$tenant, , $adminToken, $unit] = $this->vacantUnitWithAdmin();

        $email = 'rotate+'.Str::random(6).'@vecinia.mx';
        $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $email,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ])->assertCreated();

        $invitation = UserInvitation::where('email', $email)->firstOrFail();
        $firstTokenHash = $invitation->token_hash;

        $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $email,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ])
            ->assertCreated();

        $this->assertSame(1, UserInvitation::where('email', $email)->count());
        $this->assertNotSame($firstTokenHash, $invitation->fresh()->token_hash);
    }

    public function test_owner_invite_blocked_by_pending_invitation_of_different_relationship_same_email(): void
    {
        Mail::fake();
        [$tenant, , $adminToken, $unit] = $this->vacantUnitWithAdmin();

        $email = 'switch+'.Str::random(6).'@vecinia.mx';
        $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $email,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::FAMILY_RESIDENT,
            ])->assertCreated();

        $invitation = UserInvitation::where('email', $email)->firstOrFail();

        $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $email,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ])
            ->assertStatus(422);

        $fresh = $invitation->fresh();
        $this->assertSame(UnitRelationship::FAMILY_RESIDENT, $fresh->unit_relationship_id);
        $this->assertNull($fresh->accepted_at);
        $this->assertNull($fresh->revoked_at);
    }

    public function test_pending_owner_invite_does_not_block_family_resident_invite(): void
    {
        Mail::fake();
        [$tenant, , $adminToken, $unit] = $this->vacantUnitWithAdmin();

        $ownerEmail = 'owner+'.Str::random(6).'@vecinia.mx';
        $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $ownerEmail,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ])->assertCreated();

        $familyEmail = 'family+'.Str::random(6).'@vecinia.mx';
        $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $familyEmail,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::FAMILY_RESIDENT,
            ])->assertCreated();

        $this->assertTrue(UserInvitation::where('email', $familyEmail)->exists());
    }

    public function test_revoked_owner_invitation_frees_the_unit_for_a_new_invite(): void
    {
        Mail::fake();
        [$tenant, , $adminToken, $unit] = $this->vacantUnitWithAdmin();

        $firstEmail = 'revoked-owner+'.Str::random(6).'@vecinia.mx';
        $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $firstEmail,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ])->assertCreated();

        $invitation = UserInvitation::where('email', $firstEmail)->firstOrFail();

        $this->tenantRequest($adminToken, $tenant)
            ->deleteJson('/api/resident-invitation/'.$invitation->id)
            ->assertOk();

        $this->assertNotNull($invitation->fresh()->revoked_at);

        $secondEmail = 'newowner+'.Str::random(6).'@vecinia.mx';
        $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $secondEmail,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ])
            ->assertCreated();

        $this->assertTrue(UserInvitation::where('email', $secondEmail)->exists());
    }

    public function test_expired_owner_invitation_frees_the_unit_for_a_new_invite(): void
    {
        Mail::fake();
        [$tenant, $admin, $adminToken, $unit] = $this->vacantUnitWithAdmin();

        $expiredEmail = 'expired-owner+'.Str::random(6).'@vecinia.mx';
        $this->inTenant($tenant, function () use ($tenant, $admin, $unit, $expiredEmail): void {
            UserInvitation::create([
                'token' => null,
                'token_hash' => hash('sha256', Str::random(64)),
                'tenant_id' => $tenant->id,
                'inviteable_type' => Unit::class,
                'inviteable_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'email' => $expiredEmail,
                'invited_by_user_id' => $admin->id,
                'expires_at' => now()->subDay(),
            ]);
        });

        $newEmail = 'newowner+'.Str::random(6).'@vecinia.mx';
        $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $newEmail,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ])
            ->assertCreated();

        $this->assertTrue(UserInvitation::where('email', $newEmail)->exists());
    }

    /**
     * Mail::fake() cannot observe this: ResidentInvitationMail's context isn't
     * persisted anywhere (mail_context is ephemeral, built right before send),
     * so the cheapest honest assertion is a direct unit-style check on the
     * service method that feeds relationship_name into the mail context.
     */
    public function test_owner_relationship_name_used_in_mail_context_is_propietario(): void
    {
        $method = new ReflectionMethod(ResidentInvitationService::class, 'relationshipName');
        $method->setAccessible(true);

        $result = $method->invoke(app(ResidentInvitationService::class), UnitRelationship::OWNER);

        $this->assertSame('propietario', $result);
    }

    public function test_owner_accept_happy_path_promotes_via_change_owner(): void
    {
        Mail::fake();
        $this->seedResidentRoles();
        [$tenant, , $adminToken, $unit] = $this->vacantUnitWithAdmin();

        $email = 'newowner+'.Str::random(6).'@vecinia.mx';
        $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $email,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ])->assertCreated();

        $plain = $this->latestResidentToken();

        $this->publicRequest()
            ->postJson('/api/auth/accept-invitation', [
                'token' => $plain,
                'password' => 'secret1234',
                'password_confirmation' => 'secret1234',
            ])
            ->assertOk();

        $this->inTenant($tenant, function () use ($email, $unit): void {
            $newOwner = User::withoutGlobalScope(UserTenantScope::class)->where('email', $email)->firstOrFail();

            $membership = UnitUser::where('unit_id', $unit->id)
                ->where('user_id', $newOwner->id)
                ->whereNull('end_date')
                ->first();
            $this->assertNotNull($membership);
            $this->assertSame(UnitRelationship::OWNER, $membership->unit_relationship_id);
            $this->assertTrue($membership->is_billing_owner);

            // changeOwner's notification must fire exactly once — no duplicate
            // from a fallthrough to the plain create() "Asignación a unidad" path.
            $notifications = NotificationUser::with('notification')->where('user_id', $newOwner->id)->get();
            $this->assertCount(1, $notifications);
            $this->assertSame('Cambio de propietario', $notifications->first()->notification->title);
        });

        $invitation = UserInvitation::where('email', $email)->firstOrFail();
        $this->assertNotNull($invitation->accepted_at);
    }

    public function test_owner_stale_accept_via_public_flow_is_rejected_when_owner_appeared_meanwhile(): void
    {
        Mail::fake();
        [$tenant, , $adminToken, $unit] = $this->vacantUnitWithAdmin();

        $email = 'stale+'.Str::random(6).'@vecinia.mx';
        $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $email,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ])->assertCreated();

        $plain = $this->latestResidentToken();
        $invitation = UserInvitation::where('email', $email)->firstOrFail();

        // Someone else becomes the active owner before the invitee accepts.
        $someoneElse = $this->makeUser($tenant);
        $this->attachUnitUser($tenant, $unit, $someoneElse, UnitRelationship::OWNER);

        $this->publicRequest()
            ->postJson('/api/auth/accept-invitation', [
                'token' => $plain,
                'password' => 'secret1234',
                'password_confirmation' => 'secret1234',
            ])
            ->assertStatus(409);

        $this->assertNull($invitation->fresh()->accepted_at);
        $this->assertFalse(User::withoutGlobalScope(UserTenantScope::class)->where('email', $email)->exists());
    }

    public function test_owner_stale_accept_via_confirm_flow_is_rejected_when_owner_appeared_meanwhile(): void
    {
        Mail::fake();
        [$tenant, , $adminToken, $unit] = $this->vacantUnitWithAdmin();

        $invitee = $this->makeUser($tenant);

        $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/resident-invitation', [
                'email' => $invitee->email,
                'unit_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ])->assertCreated();

        $plain = $this->latestResidentToken();
        $invitation = UserInvitation::where('email', $invitee->email)->firstOrFail();

        $someoneElse = $this->makeUser($tenant);
        $this->attachUnitUser($tenant, $unit, $someoneElse, UnitRelationship::OWNER);

        $this->authRequest($this->tokenFor($invitee, []))
            ->postJson('/api/auth/accept-invitation/confirm', ['token' => $plain])
            ->assertStatus(400);

        $this->assertNull($invitation->fresh()->accepted_at);
        $this->inTenant($tenant, function () use ($unit, $invitee): void {
            $this->assertFalse(
                UnitUser::where('unit_id', $unit->id)
                    ->where('user_id', $invitee->id)
                    ->where('unit_relationship_id', UnitRelationship::OWNER)
                    ->exists()
            );
        });
    }

    public function test_owner_accept_with_preexisting_family_membership_promotes_and_keeps_both_rows(): void
    {
        Mail::fake();
        $this->seedResidentRoles();
        [$tenant, $admin, , $unit] = $this->vacantUnitWithAdmin();

        $person = $this->makeUser($tenant);
        $this->attachUnitUser($tenant, $unit, $person, UnitRelationship::FAMILY_RESIDENT, [
            'is_billing_owner' => false,
            'is_legal_representative' => false,
        ]);

        // Bypass inviteToUnit's already_in_this_unit guard on purpose: this
        // simulates an OWNER invitation minted BEFORE the family membership
        // existed, to isolate acceptToUnit's OWNER branch behaviour.
        $plain = Str::random(64);
        $this->inTenant($tenant, function () use ($plain, $tenant, $admin, $unit, $person): void {
            UserInvitation::create([
                'token' => null,
                'token_hash' => hash('sha256', $plain),
                'tenant_id' => $tenant->id,
                'inviteable_type' => Unit::class,
                'inviteable_id' => $unit->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'email' => $person->email,
                'invited_by_user_id' => $admin->id,
                'expires_at' => now()->addDays(7),
            ]);
        });

        $this->authRequest($this->tokenFor($person, []))
            ->postJson('/api/auth/accept-invitation/confirm', ['token' => $plain])
            ->assertOk();

        $this->inTenant($tenant, function () use ($unit, $person): void {
            $ownerRow = UnitUser::where('unit_id', $unit->id)
                ->where('user_id', $person->id)
                ->where('unit_relationship_id', UnitRelationship::OWNER)
                ->whereNull('end_date')
                ->first();
            $this->assertNotNull($ownerRow);
            $this->assertTrue($ownerRow->is_billing_owner);

            $familyRow = UnitUser::where('unit_id', $unit->id)
                ->where('user_id', $person->id)
                ->where('unit_relationship_id', UnitRelationship::FAMILY_RESIDENT)
                ->whereNull('end_date')
                ->first();
            $this->assertNotNull($familyRow);
        });
    }

    // ----------------------------------------------------------------------

    /**
     * Fresh tenant with one vacant unit and an admin token (create.resident_invitation).
     *
     * @return array{0: Tenant, 1: User, 2: string, 3: Unit}
     */
    private function vacantUnitWithAdmin(): array
    {
        $tenant = $this->makeTenant('Owner Invite Tenant');
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $unit = $this->makeUnit($tenant);
        $adminToken = $this->tokenFor($admin, ['create.resident_invitation']);

        return [$tenant, $admin, $adminToken, $unit];
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
