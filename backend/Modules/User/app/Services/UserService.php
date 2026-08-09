<?php

namespace Modules\User\Services;

use App\Models\Role as ModelsRole;
use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Email\Jobs\SendAccountOwnerNotificationJob;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Audit;
use QCH\Core\Models\Role;

class UserService
{
    public function create(array $request): Model
    {
        $user = auth()->user();

        $roleNameToAssign = $this->resolveRequestedRoleSlug($request);

        // SECURITY: the role comes straight from the request body, so it must be
        // checked against the actor's tier BEFORE anything is written — otherwise
        // any holder of create.user (the `administrator` role a cluster hires)
        // could mint a platform super admin. Validating first also guarantees a
        // rejected assignment never leaves a half-created user behind.
        if ($roleNameToAssign !== null && ! Role::canAssignSlug($user, $roleNameToAssign)) {
            throw new AuthorizationException('No tienes permiso para asignar ese rol.');
        }

        // No explicit role in the payload: inherit the creator's own role. This is
        // replication, never elevation (the actor already holds it), so it is left
        // outside the guard above to avoid breaking existing creation flows.
        $roleNameToAssign ??= $user?->getRoleNames()?->first();

        $entity = new User;
        $entity->fill($request);
        $entity->save();

        if ($user->hasRole(Role::SLUG_SUPER_ADMIN)) {
            if ($request['tenant_id'] ?? false) {

                // remove current tenants
                $entity->tenants()->detach();
                $tenant = Tenant::find($request['tenant_id']);
                if ($tenant) {
                    $entity->tenants()->syncWithoutDetaching([$tenant->id]);
                }
            } else {

            }
        } else {
            $tenant = $user->tenants()->first();
            $entity->tenants()->syncWithoutDetaching([$tenant->id]);
        }

        if ($roleNameToAssign) {
            $entity->assignRole($roleNameToAssign);
        }

        if ($roleNameToAssign === Role::SLUG_ACCOUNT_OWNER) {
            SendAccountOwnerNotificationJob::dispatch($entity);
        }

        $entity->load('roles');

        return $entity;
    }

    /**
     * Resolve the role slug the payload is explicitly asking for, accepting either
     * `role_id` (the role's uuid) or `role` (the slug stored in `roles.name`).
     *
     * Returns null when the payload names no role, or names one that does not
     * exist — both keep the previous behaviour of creating the user without a
     * role rather than failing the request.
     *
     * @param  array<string, mixed>  $request
     */
    private function resolveRequestedRoleSlug(array $request): ?string
    {
        if ($request['role_id'] ?? false) {
            return ModelsRole::find($request['role_id'])?->name;
        }

        if ($request['role'] ?? false) {
            return is_string($request['role']) ? $request['role'] : null;
        }

        return null;
    }

    /**
     * Right to be forgotten (§18.3.4 — global-06): replace the user's PII with a
     * neutral placeholder while PRESERVING the row (same UUID) so the ledger —
     * charges, payments, audits, unit_user, access logs — keeps its referential
     * integrity. The user can never authenticate again (auth_active=false,
     * credentials nulled, every token revoked).
     *
     * Scope decision (documented): the user_profile purge targets the CURRENT
     * tenant only — RTBF requests arrive per cluster, and an admin of cluster A
     * must not wipe the profile the person still uses in cluster B. The central
     * identity columns (name/email/phone) are global by nature and are wiped.
     */
    public function anonymize(User $target): User
    {
        return DB::transaction(function () use ($target): User {
            $target->forceFill([
                'name' => 'Usuario eliminado',
                'last_name' => null,
                'second_last_name' => null,
                'email' => null,
                'phone' => null,
                'birthdate' => null,
                'password' => null,
                'verification_code' => null,
                'token_sender' => null,
                'auth_active' => false,
            ])->save();

            $target->clearMediaCollection('profile_image');

            $this->purgeCurrentTenantProfile($target);

            $target->tokens()->delete();

            $this->recordAnonymizationAudit($target);

            return $target->refresh();
        });
    }

    /**
     * Guard for anonymize: a tenant must never be left without an account owner.
     * Checked across EVERY tenant the target belongs to (anonymization is global
     * for the central identity, so being the last owner anywhere blocks it).
     */
    public function isLastAccountOwnerOfAnyTenant(User $target): bool
    {
        if (! $target->hasRole(Role::SLUG_ACCOUNT_OWNER)) {
            return false;
        }

        $tenantIds = $target->tenants()->pluck('tenant.id');

        foreach ($tenantIds as $tenantId) {
            $ownersInTenant = User::query()
                ->withoutGlobalScope(UserTenantScope::class)
                ->whereHas('tenants', function (Builder $query) use ($tenantId): void {
                    $query->where('tenant.id', $tenantId);
                })
                ->whereHas('roles', function (Builder $query): void {
                    $query->where('name', Role::SLUG_ACCOUNT_OWNER);
                })
                ->count();

            if ($ownersInTenant <= 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Null the per-cluster PII and soft-delete the profile row of the CURRENT
     * tenant. The columns are nulled BEFORE the soft delete so no PII survives
     * in the retained row ($auditExclude keeps the old values out of audits).
     */
    private function purgeCurrentTenantProfile(User $target): void
    {
        if (! tenancy()->initialized || ! tenant()) {
            return;
        }

        $profile = $target->profiles()
            ->where('tenant_id', tenant()->getTenantKey())
            ->first();

        if ($profile === null) {
            return;
        }

        $profile->forceFill([
            'rfc' => null,
            'curp' => null,
            'birthdate' => null,
            'address' => null,
            'emergency_contact_name' => null,
            'emergency_contact_phone' => null,
            'notes' => null,
        ])->save();

        $profile->delete();
    }

    /**
     * Manual audit row (§18.2): User is deliberately NOT Auditable (its column
     * changes would persist PII in old_values), so the RTBF operation records an
     * explicit, value-free 'anonymized' event with the acting admin.
     */
    private function recordAnonymizationAudit(User $target): void
    {
        (new Audit)->forceFill([
            'user_type' => User::class,
            'user_id' => auth()->id(),
            'event' => 'anonymized',
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
            'old_values' => [],
            'new_values' => [],
            'url' => request()->fullUrl(),
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 1023),
            'tags' => 'rtbf',
        ])->save();
    }

    public static function generateCode($code = null)
    {
        if ($code == null) {
            // get last code
            $lastCode = 0;
            $lastUser = \Modules\User\Models\User::where(function ($query) {
                $query->whereNotNull('code')->where('code', '!=', '');
            })
                ->orderByRaw('CAST(user.code AS UNSIGNED) DESC')->select('code')->first();
            if ($lastUser) {
                $lastCode = $lastUser->code;
            }

            $code = $lastCode + 1;
            Log::info('code:', ['code' => $code]);
        }

        return $code;
    }
}
