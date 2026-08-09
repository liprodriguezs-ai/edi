<?php

namespace Modules\AmenityReservations\Services;

use Modules\AmenityReservations\Models\Amenity;
use Modules\Collections\Services\TargetResolver;
use Modules\Housing\Models\UnitUser;

/**
 * AmenityVisibilityService — read-only resolution of "who can see/reserve
 * this amenity" from its target_type/target_selector, mirroring
 * ChatService's target matching but batched for a browsed catalog instead
 * of resolved per-row (design.md Architecture Decision #1).
 *
 * Vocabulary and short-circuits mirror Chat/Collections exactly:
 *  - null|'all'|'by_share' → always accessible, no resolver call.
 *  - unknown target_type (not in TargetResolver::TARGET_TYPES) → fails
 *    CLOSED (never accessible, never throws) — a badly-persisted row must
 *    not 500 the catalog nor leak to everyone.
 *  - any other TargetResolver type → resolved to a set of unit ids; the
 *    caller's unit must be among them.
 */
class AmenityVisibilityService
{
    public function __construct(private readonly TargetResolver $targetResolver) {}

    /**
     * The ids of every amenity the user can see, resolved with ONE query per
     * DISTINCT non-trivial (target_type, target_selector) pair instead of
     * one resolver call per amenity:
     *  1. Resolve the user's active unit ids ONCE.
     *  2. Load every amenity's (id, target_type, target_selector) — no
     *     relations needed.
     *  3. Short-circuit trivial targets straight into the result.
     *  4. Group the rest by target_type + json_encode(target_selector) and
     *     resolve each distinct group's units ONCE via
     *     TargetResolver::resolveUnitIds(), intersecting against the user's
     *     units.
     *
     * @return array<int, string>
     */
    public function accessibleAmenityIds(string $userId): array
    {
        $userUnitIds = $this->activeUnitIds($userId);

        $amenities = Amenity::query()->get(['id', 'target_type', 'target_selector']);

        $accessibleIds = [];
        $groups = [];

        foreach ($amenities as $amenity) {
            $targetType = $amenity->target_type ?? 'all';

            if ($this->isTrivialTarget($targetType)) {
                $accessibleIds[] = $amenity->id;

                continue;
            }

            $groupKey = $targetType.'|'.json_encode($amenity->target_selector);
            $groups[$groupKey]['type'] ??= $targetType;
            $groups[$groupKey]['selector'] ??= $amenity->target_selector;
            $groups[$groupKey]['amenity_ids'][] = $amenity->id;
        }

        foreach ($groups as $group) {
            if (! in_array($group['type'], TargetResolver::TARGET_TYPES, true)) {
                // Unknown target_type — fail closed, no resolver call.
                continue;
            }

            $targetUnitIds = $this->targetResolver->resolveUnitIds($group['type'], $group['selector'])->all();

            if (array_intersect($userUnitIds, $targetUnitIds) !== []) {
                array_push($accessibleIds, ...$group['amenity_ids']);
            }
        }

        return $accessibleIds;
    }

    /**
     * Single-amenity convenience wrapper for the `availability()` route —
     * avoids materializing the whole tenant's amenities for one check.
     */
    public function userCanSeeAmenity(Amenity $amenity, string $userId): bool
    {
        $targetType = $amenity->target_type ?? 'all';

        if ($this->isTrivialTarget($targetType)) {
            return true;
        }

        if (! in_array($targetType, TargetResolver::TARGET_TYPES, true)) {
            return false;
        }

        $userUnitIds = $this->activeUnitIds($userId);

        if ($userUnitIds === []) {
            return false;
        }

        $targetUnitIds = $this->targetResolver->resolveUnitIds($targetType, $amenity->target_selector)->all();

        return array_intersect($userUnitIds, $targetUnitIds) !== [];
    }

    /**
     * Whether the reserving UNIT falls within the amenity's target — used by
     * ReservationService::create() (Slice B). Same short-circuit and
     * fail-closed-on-unknown-type semantics as the read checks above.
     */
    public function unitInAmenityTarget(Amenity $amenity, string $unitId): bool
    {
        $targetType = $amenity->target_type ?? 'all';

        if ($this->isTrivialTarget($targetType)) {
            return true;
        }

        if (! in_array($targetType, TargetResolver::TARGET_TYPES, true)) {
            return false;
        }

        $targetUnitIds = $this->targetResolver->resolveUnitIds($targetType, $amenity->target_selector)->all();

        return in_array($unitId, $targetUnitIds, true);
    }

    private function isTrivialTarget(string $targetType): bool
    {
        return in_array($targetType, ['all', 'by_share'], true);
    }

    /**
     * @return array<int, string>
     */
    private function activeUnitIds(string $userId): array
    {
        return UnitUser::where('user_id', $userId)
            ->whereNull('end_date')
            ->pluck('unit_id')
            ->all();
    }
}
