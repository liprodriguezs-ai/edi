<?php

namespace Modules\User\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Collaborator\Models\Collaborator;
use Modules\Collaborator\Services\EmployeeService;
use Modules\Email\Emails\ResidentInvitationMail;
use Modules\Email\Emails\UserInvitationMail;
use Modules\Email\Services\SystemMailer;
use Modules\Housing\Models\Unit;
use Modules\Housing\Services\ResidentInvitationService;
use Modules\Tenant\Models\Tenant;
use Modules\User\Models\UserInvitation;
use QCH\Core\Models\Role;

class UserInvitationService
{
    /**
     * Create (or reuse) an invitation for the given inviteable and send the email.
     * Backwards-compatible entry point used by the Collaborator flow; derives the
     * email from the inviteable and delegates to createAndSend().
     */
    public function createAndSendInvitation(Model $inviteable): ?UserInvitation
    {
        $email = $this->getEmailFromInviteable($inviteable);
        if ($email === null || $email === '') {
            return null;
        }

        if (! function_exists('tenant') || ! tenant()) {
            return null;
        }

        return $this->createAndSend($inviteable, $email);
    }

    /**
     * Core invitation factory: dedups against any still-valid invitation for the
     * same inviteable+tenant+email, otherwise mints a fresh token (hashed at rest),
     * always sets an expiry, persists optional resident metadata, and emails the
     * plaintext token (which is never stored).
     *
     * @param  array{unit_relationship_id?: string|null, invited_by_user_id?: string|null, mail_context?: array<string, mixed>}  $extra
     */
    public function createAndSend(Model $inviteable, string $email, array $extra = []): UserInvitation
    {
        $tenantId = tenant()->getTenantKey();

        $existing = UserInvitation::query()
            ->where('inviteable_type', $inviteable->getMorphClass())
            ->where('inviteable_id', $inviteable->getKey())
            ->where('tenant_id', $tenantId)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($existing !== null) {
            // Reuse the row but rotate the secret so the inviter always gets a fresh,
            // working link (the previous plaintext is unrecoverable from the DB).
            return $this->rotateAndResend($existing, $extra['mail_context'] ?? []);
        }

        $plain = UserInvitation::generateUniqueToken();

        $invitation = UserInvitation::create([
            'token' => null,
            'token_hash' => hash('sha256', $plain),
            'tenant_id' => $tenantId,
            'inviteable_type' => $inviteable->getMorphClass(),
            'inviteable_id' => $inviteable->getKey(),
            'unit_relationship_id' => $extra['unit_relationship_id'] ?? null,
            'email' => $email,
            'invited_by_user_id' => $extra['invited_by_user_id'] ?? null,
            'expires_at' => now()->addDays(UserInvitation::ttlDays()),
        ]);

        $this->sendInvitationEmail($invitation, $plain, $extra['mail_context'] ?? []);

        return $invitation;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function sendInvitationEmail(UserInvitation $invitation, string $plainToken, array $context = []): void
    {
        $acceptUrl = rtrim(config('app.frontend_url'), '/').'/auth/accept-invitation/'.$plainToken;

        $mailable = $invitation->inviteable_type === Unit::class
            ? new ResidentInvitationMail($acceptUrl, $context)
            : new UserInvitationMail($acceptUrl);

        // Invitations are system email: always delivered via the canonical
        // SystemMailer, bypassing the gated notification engine (a tenant toggle
        // or user preference must never suppress an account invitation).
        app(SystemMailer::class)->send($invitation->email, $mailable);
    }

    public function findValidByToken(string $token): ?UserInvitation
    {
        $hash = hash('sha256', $token);

        $invitation = UserInvitation::query()
            ->where(function ($q) use ($hash, $token) {
                // New invitations are looked up by hash; legacy rows still carry the
                // plaintext token and are resolved via the fallback branch.
                $q->where('token_hash', $hash)
                    ->orWhere('token', $token);
            })
            ->first();

        if ($invitation === null || ! $invitation->isValid()) {
            return null;
        }

        return $invitation;
    }

    public function acceptInvitation(string $token, string $password): User
    {
        $invitation = $this->findValidByToken($token);
        if ($invitation === null) {
            throw new \InvalidArgumentException(__('Token inválido o expirado.'), 400);
        }

        $tenant = Tenant::find($invitation->tenant_id);
        if ($tenant === null) {
            throw new \InvalidArgumentException(__('Token inválido o expirado.'), 400);
        }

        tenancy()->initialize($tenant);

        try {
            // Atomic: creating the user/membership/role AND stamping accepted_at must
            // succeed or fail together, otherwise a half-applied accept could leave
            // the token unconsumed and replayable.
            $user = DB::transaction(function () use ($invitation, $password) {
                $inviteableType = $invitation->inviteable_type;

                if ($inviteableType === Collaborator::class) {
                    $resolved = $this->acceptInvitationFromCollaborator($invitation, $password);
                } elseif ($inviteableType === Unit::class) {
                    $resolved = app(ResidentInvitationService::class)->acceptToUnit($invitation, $password, null);
                } else {
                    throw new \InvalidArgumentException(__('Token inválido o expirado.'), 400);
                }

                $invitation->forceFill(['accepted_at' => now()])->save();

                return $resolved;
            });
        } finally {
            tenancy()->end();
        }

        return $user;
    }

    /**
     * Link an ALREADY-AUTHENTICATED user (existing account, possibly from another
     * tenant) to the unit named by a resident invitation. The caller must have
     * verified the session; we additionally require the session email to match the
     * invitation email so mere possession of the token cannot attach a stranger.
     */
    public function acceptInvitationWithAccount(string $token, User $authUser): User
    {
        $invitation = $this->findValidByToken($token);
        if ($invitation === null) {
            throw new \InvalidArgumentException(__('Token inválido o expirado.'), 400);
        }

        if ($invitation->inviteable_type !== Unit::class) {
            throw new \InvalidArgumentException(__('Esta invitación no admite vinculación de cuenta.'), 400);
        }

        if (mb_strtolower(trim((string) $authUser->email)) !== mb_strtolower(trim((string) $invitation->email))) {
            throw new \InvalidArgumentException(__('La invitación pertenece a otro correo.'), 403);
        }

        $tenant = Tenant::find($invitation->tenant_id);
        if ($tenant === null) {
            throw new \InvalidArgumentException(__('Token inválido o expirado.'), 400);
        }

        tenancy()->initialize($tenant);

        try {
            $user = DB::transaction(function () use ($invitation, $authUser) {
                $resolved = app(ResidentInvitationService::class)->acceptToUnit($invitation, null, $authUser);
                $invitation->forceFill(['accepted_at' => now()])->save();

                return $resolved;
            });
        } finally {
            tenancy()->end();
        }

        return $user;
    }

    /**
     * Rotate the token and expiry of an existing invitation in place and re-send the
     * email. Keeps the same row (and its audit trail); clears any revocation.
     *
     * @param  array<string, mixed>  $context
     */
    public function rotateAndResend(UserInvitation $invitation, array $context = []): UserInvitation
    {
        $plain = UserInvitation::generateUniqueToken();

        // Only the secret and expiry rotate. accepted_at/revoked_at are permanent
        // consumption/audit stamps and must NOT be cleared here — callers only ever
        // rotate still-valid invitations (dedup filters out consumed ones).
        $invitation->forceFill([
            'token' => null,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(UserInvitation::ttlDays()),
        ])->save();

        $this->sendInvitationEmail($invitation, $plain, $context);

        return $invitation;
    }

    public function resendInvitation(Model $inviteable): UserInvitation
    {
        if (! function_exists('tenant') || ! tenant()) {
            throw new \RuntimeException('Tenant context required to resend invitation.');
        }

        return DB::transaction(function () use ($inviteable) {
            $email = $this->getEmailFromInviteable($inviteable);
            if ($email === null || $email === '') {
                throw new \RuntimeException('No se pudo determinar el correo de la invitación.');
            }

            return $this->createAndSend($inviteable, $email);
        });
    }

    private function getEmailFromInviteable(Model $inviteable): ?string
    {
        if (method_exists($inviteable, 'getEmailForInvitation')) {
            return $inviteable->getEmailForInvitation();
        }
        if (isset($inviteable->email)) {
            $email = $inviteable->email;

            return is_string($email) ? trim($email) : null;
        }

        return null;
    }

    private function acceptInvitationFromCollaborator(UserInvitation $invitation, string $password): User
    {
        $collaborator = Collaborator::find($invitation->inviteable_id);
        if ($collaborator === null || $collaborator->user_id !== null) {
            throw new \InvalidArgumentException(__('Token inválido o expirado.'), 400);
        }

        $email = $collaborator->email ? trim((string) $collaborator->email) : '';
        if ($email === '') {
            throw new \InvalidArgumentException(__('Token inválido o expirado.'), 400);
        }

        $user = User::create([
            'name' => $collaborator->name ?? '',
            'last_name' => $collaborator->last_name ?? '',
            'second_last_name' => $collaborator->second_last_name,
            'email' => $email,
            'phone' => $collaborator->phone,
            'password' => Hash::make($password),
        ]);

        // §7.4.2: the employee's app access carries the Kivo7 role mapped from
        // the position (security guard → security, administrator → administrator,
        // rest → operations_staff) — NOT the legacy CRM 'collaborator' role, which
        // carries zero Kivo7 permissions. Legacy fallback only if the Kivo7
        // role is not seeded in this environment.
        $veciniaRole = app(EmployeeService::class)
            ->resolveKivo7Role($collaborator);

        if ($veciniaRole !== null) {
            $user->assignRole($veciniaRole);
        } else {
            $user->assignRole(Role::SLUG_COLLABORATOR);
        }

        $user->tenants()->attach(tenant()->getTenantKey());

        $collaborator->user_id = $user->id;
        $collaborator->saveQuietly();

        return $user;
    }
}
