<?php

namespace Modules\AmenityReservations\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Modules\AmenityReservations\Models\AmenityBlackout;
use Modules\AmenityReservations\Services\AmenityVisibilityService;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;

/**
 * Managed under the `amenity` permission (§S4 tasks) — blackouts are a detail
 * of their owning amenity, not an independently permissioned resource.
 */
class AmenityBlackoutController extends Controller
{
    protected $model = AmenityBlackout::class;

    protected $keyForPermission = 'amenity';

    public function includes(): array
    {
        return ['amenity'];
    }

    public function sortableBy(): array
    {
        return ['start_date', 'end_date'];
    }

    public function filterableBy(): array
    {
        return ['amenity_id'];
    }

    /**
     * A2 (visibility boundary) — blackouts inherit their amenity's target: a
     * caller only lists/shows blackouts of amenities they can ACCESS, so a
     * resident can't read `GET /amenity_blackout?include=amenity` (or
     * ?filter[amenity_id]=<hidden>) to reveal an amenity the AmenityController
     * scope hides. Scoping on the FK also neutralizes the eager include.
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
