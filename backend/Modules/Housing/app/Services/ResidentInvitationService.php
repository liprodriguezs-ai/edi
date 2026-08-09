<?php

namespace Modules\Housing\Services;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitUser;
use Modules\User\Models\UserInvitation;
use Modules\User\Services\UserInvitationService;
use Modules\UserProfile\Models\UserProfile;
use QCH\Core\Models\Role;

/**
 * Resident-side invitation domain logic. Reuses the central UserInvitationService
 * for token/email plumbing; this service owns the housing rules: who may invite,
 * cross-unit warnings, and turning an accepted invitation into a unit_user.
 */
class ResidentInvitationService
{
    /**
     * Relationship types a resident invitation may grant. OWNER is admin-gated:
     * only an inviter holding create.resident_invitation (or *) may invite an
     * OWNER, and only onto a unit with zero active owners (see inviteToUnit's
     * OWNER guard block). Accepting an OWNER invitation still routes through
     * UnitUserService::changeOwner so single-owner/billing invariants hold.
     */
    public const INVITABLE_RELATIONSHIPS = [
        UnitRelationship::OWNER,
        UnitRelationship::FAMILY_RESIDENT,
        UnitRelationship::RENTER,
        UnitRelationship::CO_OWNER,
        UnitRelationship::BILLING_CONTACT,
    ];

    public function __construct(
        private readonly UserInvitationService $invitations,
        private readonly UnitUserService $unitUserService,
    ) {}

    /**
     * Only active owners (owner/co-owner, or the billing owner) may invite to a unit.
     */
    public function isActiveOwner(User $user, Unit $unit): bool
    {
        return UnitUser::where('unit_id', $unit->id)
            ->where('user_id', $user->id)
            ->whereNull('end_date')
            ->where(function ($q) {
                $q->whereIn('unit_relationship_id', [UnitRelationship::OWNER, UnitRelationship::CO_OWNER])
                    ->orWhere('is_billing_owner', true);
            })
            ->exists();
    }

    /**
     * Inspect an email before inviting. Same-tenant other-unit memberships produce a
     * NON-blocking warning; cross-tenant accounts are never exposed (only the
     * boolean email_registered, used to drive the activation screen).
     *
     * @return array{email_registered: bool, already_in_this_unit: bool, has_pending_invitation: bool, same_tenant_other_unit: bool, same_tenant_units: array<int, array{unit_id: string, unit_label: string}>}
     */
    public function checkEmail(string $email, Unit $unit): array
    {
        // Match the rest of the system, which stores and compares emails verbatim
        // (case-sensitive). We only trim surrounding whitespace.
        $email = trim($email);

        $existingUser = User::withoutGlobalScope(UserTenantScope::class)
            ->where('email', $email)
            ->first();

        $alreadyInThisUnit = false;
        $sameTenantUnits = [];

        if ($existingUser !== null) {
            // UnitUser carries TenantScope, so this only sees the CURRENT cluster.
            $activeMemberships = UnitUser::with('unit')
                ->where('user_id', $existingUser->id)
                ->whereNull('end_date')
                ->get();

            foreach ($activeMemberships as $membership) {
                if ($membership->unit_id === $unit->id) {
                    $alreadyInThisUnit = true;

                    continue;
                }

                $sameTenantUnits[] = [
                    'unit_id' => $membership->unit_id,
                    'unit_label' => (string) ($membership->unit?->full_identifier ?? ''),
                ];
            }
        }

        $hasPending = UserInvitation::query()
            ->where('inviteable_type', Unit::class)
            ->where('inviteable_id', $unit->id)
            ->where('tenant_id', tenant()->getTenantKey())
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();

        return [
            'email_registered' => $existingUser !== null && $existingUser->password !== null,
            'already_in_this_unit' => $alreadyInThisUnit,
            'has_pending_invitation' => $hasPending,
            'same_tenant_other_unit' => $sameTenantUnits !== [],
            'same_tenant_units' => $sameTenantUnits,
        ];
    }

    /**
     * Create and send a resident invitation. Blocks only on a real duplicate
     * (already in this unit); other situations return non-blocking warnings.
     *
     * @return array{invitation: UserInvitation, warnings: array<int, string>}
     */
    public function inviteToUnit(User $inviter, string $email, Unit $unit, string $unitRelationshipId): array
    {
        $email = trim($email);

        if (! in_array($unitRelationshipId, self::INVITABLE_RELATIONSHIPS, true)) {
            throw ValidationException::withMessages([
                'unit_relationship_id' => ['El tipo de residente seleccionado no es válido.'],
            ]);
        }

        if ($unitRelationshipId === UnitRelationship::OWNER) {
            $this->assertOwnerInviteAllowed($inviter, $unit, $email);
        }

        $check = $this->checkEmail($email, $unit);

        if ($check['already_in_this_unit']) {
            throw ValidationException::withMessages([
                'email' => ['Esta persona ya es residente de esta unidad.'],
            ]);
        }

        // Renter requires an active owner (mirrors UnitUserService invariant; fail fast).
        if ($unitRelationshipId === UnitRelationship::RENTER && ! $this->unitHasActiveOwner($unit)) {
            throw ValidationException::withMessages([
                'unit_relationship_id' => ['No puedes invitar a un inquilino sin que la unidad tenga al menos un propietario activo.'],
            ]);
        }

        $invitation = $this->invitations->createAndSend($unit, $email, [
            'unit_relationship_id' => $unitRelationshipId,
            'invited_by_user_id' => $inviter->id,
            'mail_context' => $this->mailContext($inviter->name, $unit, $unitRelationshipId),
        ]);

        $warnings = [];
        if ($check['same_tenant_other_unit']) {
            $labels = implode(', ', array_filter(array_map(
                static fn (array $u): string => $u['unit_label'],
                $check['same_tenant_units'],
            )));
            $warnings[] = $labels !== ''
                ? "Esta persona ya es residente de {$labels} en este condominio. También quedará asociada a esta unidad."
                : 'Esta persona ya es residente de otra unidad en este condominio. También quedará asociada a esta unidad.';
        }

        return ['invitation' => $invitation, 'warnings' => $warnings];
    }

    public function resend(UserInvitation $invitation): UserInvitation
    {
        // accepted_at is an irreversible consumption stamp; a consumed invitation
        // must never be re-armed with a fresh token.
        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages([
                'invitation' => ['Esta invitación ya fue aceptada y no puede reenviarse.'],
            ]);
        }

        return $this->invitations->rotateAndResend($invitation, $this->mailContextFromInvitation($invitation));
    }

    public function revoke(UserInvitation $invitation): void
    {
        $invitation->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * Turn an accepted invitation into an active unit_user membership. Assumes the
     * tenant context is ALREADY initialized by the caller. Resolves the user from
     * $authUser (existing account) or by email (new/passwordless account), links the
     * tenant, creates the membership (idempotent), assigns the role and ensures a
     * profile shell. Does not stamp accepted_at — the caller owns that.
     */
    public function acceptToUnit(UserInvitation $invitation, ?string $password, ?User $authUser = null): User
    {
        $email = trim((string) $invitation->email);
        $relationshipId = $invitation->unit_relationship_id ?: UnitRelationship::FAMILY_RESIDENT;

        $unit = Unit::find($invitation->inviteable_id);
        if ($unit === null) {
            throw new \InvalidArgumentException(__('La unidad de la invitación ya no existe.'), 400);
        }

        $user = $authUser
            ?? User::withoutGlobalScope(UserTenantScope::class)->where('email', $email)->first();

        // Defense-in-depth: an existing account WITH a password must prove control
        // by logging in (the authenticated confirm flow), not merely possess the
        // invitation link. The public password flow is only for new/passwordless
        // accounts. The frontend already branches on account_exists.
        if ($authUser === null && $user !== null && $user->password !== null) {
            throw new \InvalidArgumentException(
                __('Ya tienes una cuenta. Inicia sesión para unirte a la unidad.'),
                409
            );
        }

        if ($user === null && $password === null) {
            throw new \InvalidArgumentException(__('Se requiere una contraseña para crear la cuenta.'), 400);
        }

        // All writes (create user, link tenant, membership, role, profile) are atomic.
        return DB::transaction(function () use ($password, $user, $unit, $email, $relationshipId): User {
            $resolved = $user;

            if ($resolved === null) {
                $resolved = User::create([
                    'name' => Str::before($email, '@'),
                    'email' => $email,
                    'password' => Hash::make((string) $password),
                    'auth_active' => true,
                ]);
            } elseif ($password !== null && $resolved->password === null) {
                // Passwordless account (e.g. created by an admin) completing its setup.
                $resolved->forceFill(['password' => Hash::make($password), 'auth_active' => true])->save();
            }

            $tenantId = tenant()->getTenantKey();
            $resolved->tenants()->syncWithoutDetaching([$tenantId]);

            $alreadyActive = UnitUser::where('unit_id', $unit->id)
                ->where('user_id', $resolved->id)
                ->whereNull('end_date')
                ->exists();

            if ($relationshipId === UnitRelationship::OWNER) {
                // D4 mutex: lock the unit row so a concurrent accept serializes behind
                // this one and re-checks vacancy against the committed state.
                Unit::whereKey($unit->id)->lockForUpdate()->first();

                if ($this->unitHasActiveOwner($unit)) {
                    throw new \InvalidArgumentException(
                        __('La unidad ya tiene un propietario activo. Contacta a la administración de tu condominio.'),
                        409
                    );
                }

                // Routes through changeOwner (not a plain create) so single-owner and
                // billing-takeover invariants hold and the notification fires once.
                $this->unitUserService->changeOwner($unit, ['user_id' => $resolved->id]);
            } elseif (! $alreadyActive) {
                $this->unitUserService->create([
                    'unit_id' => $unit->id,
                    'user_id' => $resolved->id,
                    'unit_relationship_id' => $relationshipId,
                    'is_billing_owner' => $relationshipId === UnitRelationship::BILLING_CONTACT,
                    'is_legal_representative' => false,
                    'start_date' => today(),
                    'end_date' => null,
                ]);
            }

            $roleSlug = $relationshipId === UnitRelationship::RENTER ? Role::SLUG_RENTER : Role::SLUG_RESIDENT;
            if (! $resolved->hasRole($roleSlug)) {
                $resolved->assignRole($roleSlug);
            }

            UserProfile::firstOrCreate(['user_id' => $resolved->id]);

            $resolved->forceFill([
                'last_active_tenant_id' => $tenantId,
                'onboarding_completed_at' => $resolved->onboarding_completed_at ?? now(),
            ])->save();

            return $resolved;
        });
    }

    private function unitHasActiveOwner(Unit $unit): bool
    {
        return UnitUser::where('unit_id', $unit->id)
            ->where('unit_relationship_id', UnitRelationship::OWNER)
            ->whereNull('end_date')
            ->exists();
    }

    /**
     * Authorization + state guards for an OWNER invite, in order: admin-only
     * permission, unit already has an active owner, unit already has a pending
     * OWNER invitation (for a different email — same-email re-invites dedup and
     * rotate via createAndSend), and the invitee email already has a pending
     * invitation of a DIFFERENT relationship on this unit.
     */
    private function assertOwnerInviteAllowed(User $inviter, Unit $unit, string $email): void
    {
        if (! ($inviter->tokenCan('create.resident_invitation') || $inviter->tokenCan('*'))) {
            throw ValidationException::withMessages([
                'unit_relationship_id' => ['Solo la administración puede invitar a un propietario.'],
            ]);
        }

        if ($this->unitHasActiveOwner($unit)) {
            throw ValidationException::withMessages([
                'unit_relationship_id' => ['Esta unidad ya tiene un propietario activo. Usa "Cambiar propietario" para reemplazarlo.'],
            ]);
        }

        if ($this->unitHasPendingOwnerInvitation($unit, $email)) {
            throw ValidationException::withMessages([
                'unit_relationship_id' => ['Ya hay una invitación de propietario pendiente para esta unidad. Revócala o espera a que venza.'],
            ]);
        }

        if ($this->unitHasPendingInvitationForOtherRelationship($unit, $email, UnitRelationship::OWNER)) {
            throw ValidationException::withMessages([
                'unit_relationship_id' => ['Ya existe una invitación pendiente para este correo con otro tipo de residente. Revócala o espera a que se resuelva antes de invitarla como propietario.'],
            ]);
        }
    }

    /**
     * Whether the unit has a pending (unaccepted, unrevoked, unexpired) OWNER
     * invitation for an email OTHER than the one being invited — a same-email
     * re-invite is a dedup/rotate, not a duplicate (D5).
     */
    private function unitHasPendingOwnerInvitation(Unit $unit, string $email): bool
    {
        return UserInvitation::query()
            ->where('inviteable_type', Unit::class)
            ->where('inviteable_id', $unit->id)
            ->where('tenant_id', tenant()->getTenantKey())
            ->where('unit_relationship_id', UnitRelationship::OWNER)
            ->where('email', '!=', $email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    /**
     * [Directive 2] Whether this exact email already has a pending invitation for
     * this unit under a DIFFERENT relationship than the one being requested —
     * switching relationships must go through revoke/resend, not silently rotate.
     */
    private function unitHasPendingInvitationForOtherRelationship(Unit $unit, string $email, string $unitRelationshipId): bool
    {
        return UserInvitation::query()
            ->where('inviteable_type', Unit::class)
            ->where('inviteable_id', $unit->id)
            ->where('tenant_id', tenant()->getTenantKey())
            ->where('email', $email)
            ->where('unit_relationship_id', '!=', $unitRelationshipId)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function mailContext(?string $inviterName, Unit $unit, string $relationshipId): array
    {
        return [
            'inviter_name' => $inviterName ? trim($inviterName) : null,
            'unit_label' => $unit->full_identifier,
            'cluster_name' => tenant()?->name,
            'relationship_name' => $this->relationshipName($relationshipId),
            'ttl_days' => UserInvitation::ttlDays(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mailContextFromInvitation(UserInvitation $invitation): array
    {
        $unit = Unit::find($invitation->inviteable_id);
        $inviter = $invitation->invited_by_user_id
            ? User::withoutGlobalScope(UserTenantScope::class)->find($invitation->invited_by_user_id)
            : null;

        return [
            'inviter_name' => $inviter?->name ? trim((string) $inviter->name) : null,
            'unit_label' => $unit?->full_identifier,
            'cluster_name' => tenant()?->name,
            'relationship_name' => $invitation->unit_relationship_id
                ? $this->relationshipName($invitation->unit_relationship_id)
                : null,
            'ttl_days' => UserInvitation::ttlDays(),
        ];
    }

    private function relationshipName(string $relationshipId): string
    {
        return match ($relationshipId) {
            UnitRelationship::OWNER => 'propietario',
            UnitRelationship::FAMILY_RESIDENT => 'residente familiar',
            UnitRelationship::RENTER => 'inquilino',
            UnitRelationship::CO_OWNER => 'co-propietario',
            UnitRelationship::BILLING_CONTACT => 'contacto de cobranza',
            default => 'residente',
        };
    }
}
