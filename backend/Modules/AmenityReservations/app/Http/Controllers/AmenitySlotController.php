<?php

namespace Modules\AmenityReservations\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Modules\AmenityReservations\Models\AmenitySlot;
use Modules\AmenityReservations\Services\AmenityVisibilityService;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;

/**
 * Managed under the `amenity` permission (§S4 tasks) — slots are a detail of
 * their owning amenity, not an independently permissioned resource.
 */
class AmenitySlotController extends Controller
{
    protected $model = AmenitySlot::class;

    protected $keyForPermission = 'amenity';

    public function includes(): array
    {
        return ['amenity'];
    }

    public function sortableBy(): array
    {
        return ['weekday', 'start_time'];
    }

    public function filterableBy(): array
    {
        return ['amenity_id', 'weekday'];
    }

    /**
     * A2 (visibility boundary) — slots inherit their amenity's target: a
     * caller only lists/shows slots of amenities they can ACCESS. Without this
     * a resident could read `GET /amenity_slot?include=amenity` (or
     * ?filter[amenity_id]=<hidden>) and see the schedule + full parent of an
     * amenity the AmenityController scope hides. Scoping on the FK also
     * neutralizes the eager `include=amenity`.
     */
    protected function buildIndexFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return $this->scopeToAccessibleAmenities(parent::buildIndexFetchQuery($request, $requestedRelations));
    }

    protected function buildShowFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return $this->scopeToAccessibleAmenities(parent::buildShowFetchQuery($request, $requestedRelations));
    }

    private function scopeToAccessibleAmenities(Builder $query): Builder
    {
        $user = auth()->guard('sanctum')->user();

        if ($user === null || $user->tokenCan('*') || $user->tokenCan('update.amenity')) {
            return $query;
        }

        return $query->whereIn(
            'amenity_id',
            app(AmenityVisibilityService::class)->accessibleAmenityIds((string) $user->id),
        );
    }
}
