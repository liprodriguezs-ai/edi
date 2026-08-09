<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Modules\Access\Models\Visitor;
use Modules\Announcements\Models\Announcement;
use Modules\Board\Models\BoardPeriod;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Services\ChatService;
use Modules\Collaborator\Models\Collaborator;
use Modules\Collections\Models\Payment;
use Modules\Company\Models\Company;
use Modules\Housing\Models\UnitUser;
use Modules\Projects\Models\CommunityProject;
use Modules\Treasury\Models\Expense;
use Modules\UserProfile\Models\UserProfile;

/**
 * Authorizes private media downloads (findings sec-input-05 / sec-input-06).
 *
 * Two layers:
 *  1. Tenant visibility — tenant-scoped owner models resolve through the
 *     global TenantScope, so a media item whose owner belongs to another
 *     tenant yields a null owner and is denied. Central owners (User) are
 *     checked explicitly against shared tenant membership.
 *  2. Collection rule — a per-owner-type read ability (financial/staff roles)
 *     OR subject ownership (the resident downloading their own receipt, their
 *     unit's visitor photo, their own documents).
 */
class MediaAuthorizationService
{
    private const MEDIA_READ_ABILITIES = [
        User::class => 'read.user',
        Company::class => 'read.company',
        // Payment receipts: financial operators; the paying unit falls back to ownership.
        Payment::class => 'read.bank_account',
        Expense::class => 'read.expense',
        Announcement::class => 'read.announcement',
        // Visitor photos: caseta/board via the access log ability; the visited unit owns its photos.
        Visitor::class => 'read.access_log',
        // Board acta: period managers only.
        BoardPeriod::class => 'create.board_period',
        CommunityProject::class => 'read.community_project',
        // Employee documents (contract/INE) — §7.5: admin, president, treasurer.
        Collaborator::class => 'read.collaborator',
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
     * Ownership fallback: own user/profile documents, or media whose subject
     * belongs to one of the caller's active units (payment receipt, visitor photo).
     */
    private function ownsMediaSubject(User $user, Model $owner): bool
    {
        if ($owner instanceof User) {
            return $owner->getKey() === $user->getKey();
        }

        if ($owner instanceof UserProfile) {
            return $owner->user_id === $user->getKey();
        }

        // Chat media (direct uploads AND cross-posted copies, whose owner becomes
        // the ChatMessage via Media::copy — see design ADR-2/ADR-3): authorization
        // is TARGET-based (per-channel membership), not a Spatie ability, so it is
        // delegated to ChatService::canAccessChannel — the same rule that gates
        // every other chat read. Fail closed on a soft-deleted/edge null channel.
        if ($owner instanceof ChatMessage) {
            $channel = $owner->channel;

            return $channel !== null
                && app(ChatService::class)->canAccessChannel($channel, (string) $user->getKey());
        }

        $unitId = ($owner instanceof Payment || $owner instanceof Visitor)
            ? $owner->unit_id
            : null;

        if ($unitId === null) {
            return false;
        }

        return UnitUser::query()
            ->where('unit_id', $unitId)
            ->where('user_id', $user->getKey())
            ->whereNull('end_date')
            ->exists();
    }
}
