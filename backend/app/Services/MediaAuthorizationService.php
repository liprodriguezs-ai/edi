<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Modules\Company\Models\Company;
use Modules\UserProfile\Models\UserProfile;

/**
 * Authorizes private media downloads (findings sec-input-05 / sec-input-06).
 *
 * Two layers:
 *  1. Tenant visibility — tenant-scoped owner models resolve through the
 *     global TenantScope, so a media item whose owner belongs to another
 *     tenant yields a null owner and is denied. Central owners (User) are
 *     checked explicitly against shared tenant membership.
 *  2. Collection rule — a per-owner-type read ability OR subject ownership
 *     (the subject downloading their own documents).
 *
 * The map below only covers the owner types that survive in this project. Every
 * new domain entity that attaches private media MUST register its read ability
 * here, otherwise the ability layer is skipped and only the ownership fallback
 * applies — which fails closed for owners the fallback does not understand.
 */
class MediaAuthorizationService
{
    private const MEDIA_READ_ABILITIES = [
        User::class => 'read.user',
        Company::class => 'read.company',
        UserProfile::class => 'read.user_profile',
    ];

    public function authorizeDownload(Media $mediaItem): void
    {
        $user = auth()->guard('sanctum')->user();
        if (! $user) {
            throw new AuthorizationException('Unauthorized action.');
        }

        if ($user->tokenCan('*')) {
            return;
        }

        $owner = $mediaItem->model;
        if (! $owner instanceof Model) {
            throw new AuthorizationException('Unauthorized action.');
        }

        if (! $this->ownerVisibleToUser($user, $owner)) {
            throw new AuthorizationException('Unauthorized action.');
        }

        $ability = self::MEDIA_READ_ABILITIES[get_class($owner)] ?? null;
        if ($ability !== null && $user->tokenCan($ability)) {
            return;
        }

        if ($this->ownsMediaSubject($user, $owner)) {
            return;
        }

        throw new AuthorizationException('Unauthorized action.');
    }

    /**
     * Tenant-scoped owners are already filtered by TenantScope when tenancy is
     * initialized; central User owners require self or a shared tenant.
     */
    private function ownerVisibleToUser(User $user, Model $owner): bool
    {
        if ($owner instanceof User) {
            if ($owner->getKey() === $user->getKey()) {
                return true;
            }

            $callerTenantIds = $user->tenants()->pluck('tenant.id');

            return $owner->tenants()->whereIn('tenant.id', $callerTenantIds)->exists();
        }

        return true;
    }

    /**
     * Ownership fallback: own user/profile documents.
     *
     * Fails closed for any other owner type. Domain entities that need a
     * subject-ownership rule (e.g. the caregiver downloading their child's
     * screening report) add their branch here.
     */
    private function ownsMediaSubject(User $user, Model $owner): bool
    {
        if ($owner instanceof User) {
            return $owner->getKey() === $user->getKey();
        }

        if ($owner instanceof UserProfile) {
            return $owner->user_id === $user->getKey();
        }

        return false;
    }
}
