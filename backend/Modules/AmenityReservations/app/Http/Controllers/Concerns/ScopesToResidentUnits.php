<?php

namespace Modules\AmenityReservations\Http\Controllers\Concerns;

use Modules\Housing\Models\UnitUser;

/**
 * Resident read-scoping for AmenityReservations resources — mirrors
 * Modules\Collections\Http\Controllers\Concerns\ScopesToResidentUnits (each
 * module keeps its own copy rather than a cross-module dependency, same
 * convention Housing follows for its own variant).
 *
 * Residents/renters hold read.amenity_reservation so their app can list
 * THEIR OWN reservations, but they must never enumerate the whole cluster's
 * reservations. The broad `update.amenity_reservation` ability marks board/
 * administration access (the same marker AmenityReservationController's
 * cancel-authorization already uses); callers without it are constrained to
 * the units they belong to via the unit_user pivot. A foreign show resolves
 * to 404.
 */
trait ScopesToResidentUnits
{
    /**
     * Whether the current caller lacks board-wide read access and must be
     * restricted to their own units.
     */
    protected function lacksBoardReadAccess(): bool
    {
        $user = auth()->guard('sanctum')->user();

        return ! ($user && ($user->tokenCan('update.'.$this->keyForPermission) || $user->tokenCan('*')));
    }

    /**
     * Unit ids the authenticated user belongs to (tenant-scoped via TenantScope).
     *
     * @return array<int, string>
     */
    protected function ownUnitIds(): array
    {
        $user = auth()->guard('sanctum')->user();

        if (! $user) {
            return [];
        }

        return UnitUser::where('user_id', $user->id)
            ->whereNull('end_date')
            ->pluck('unit_id')
            ->unique()
            ->values()
            ->all();
    }
}
