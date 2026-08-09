<?php

namespace Modules\AmenityReservations\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Services\AmenityAvailabilityService;
use Modules\AmenityReservations\Services\AmenityService;
use Modules\AmenityReservations\Services\AmenityVisibilityService;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;

class AmenityController extends Controller
{
    protected $model = Amenity::class;

    protected $keyForPermission = 'amenity';

    public function includes(): array
    {
        return ['reservation_mode', 'bank_account', 'slots', 'blackouts', 'media'];
    }

    public function searchableBy(): array
    {
        return ['name', 'description'];
    }

    public function sortableBy(): array
    {
        return ['name', 'capacity', 'created_at'];
    }

    public function filterableBy(): array
    {
        return ['out_of_service', 'has_cost', 'requires_approval'];
    }

    /**
     * BUG FIX (E2E QA): default Orion cap is 100 — the amenity filter dropdown
     * (useAmenityFilterOptions, shared by the calendar + reservation list
     * filters panel) requests up to 200 rows to list every amenity in the
     * cluster. Kept sane (not the reservation controller's higher cap) since
     * this is a bounded catalog-style read, not a growing transactional log.
     */
    public function maxLimit(): int
    {
        return 200;
    }

    /**
     * A2 — a caller only lists/shows the amenities they can ACCESS (by
     * target_type/target_selector). Mirrors ChatChannelController's
     * scopeToAccessibleChannels (§13.2).
     */
    protected function buildIndexFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return $this->scopeToAccessibleAmenities(parent::buildIndexFetchQuery($request, $requestedRelations));
    }

    protected function buildShowFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return $this->scopeToAccessibleAmenities(parent::buildShowFetchQuery($request, $requestedRelations));
    }

    /**
     * GET /amenity/{id}/availability?date=YYYY-MM-DD[&end_date=YYYY-MM-DD]
     *
     * S5 — structural (out_of_service / available_weekdays / amenity_blackout)
     * + occupancy (existing amenity_reservation rows) availability. Residents
     * hold `read.amenity` (S2) so they can consult this before reserving.
     *
     * A2 — this route resolves the Amenity directly via findOrFail(), bypassing
     * Orion's builder (and therefore buildShowFetchQuery's scoping), so it
     * needs its own explicit visibility check for non-bypass callers. 404, not
     * 403 — same response as a nonexistent amenity, no existence leak
     * (Collections foreign-read precedent).
     */
    public function availability(string $id, Request $request, AmenityAvailabilityService $availabilityService): JsonResponse
    {
        $this->authorize('read.amenity', Amenity::class);

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $amenity = Amenity::findOrFail($id);

        $user = $request->user();

        if ($user !== null && ! $user->tokenCan('*') && ! $user->tokenCan('update.amenity')
            && ! app(AmenityVisibilityService::class)->userCanSeeAmenity($amenity, (string) $user->id)) {
            abort(404);
        }

        try {
            $availability = $availabilityService->forRange($amenity, $validated['date'], $validated['end_date'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $availability]);
    }

    /**
     * POST /amenity/{id}/media
     * Upload a gallery photo (up to MAX_GALLERY_IMAGES, max 10 MB each, images only).
     */
    public function uploadMedia(string $id, Request $request): JsonResponse
    {
        $this->authorize('update.amenity', Amenity::class);

        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
        ]);

        $amenity = Amenity::findOrFail($id);

        try {
            $media = app(AmenityService::class)->attachGalleryImage($amenity, $request->file('file'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $media], 201);
    }

    /**
     * DELETE /amenity/{id}/media/{mediaId}
     * The media must belong to this amenity's gallery collection (404 otherwise).
     */
    public function deleteMedia(string $id, string $mediaId): JsonResponse
    {
        $this->authorize('update.amenity', Amenity::class);

        $amenity = Amenity::findOrFail($id);

        app(AmenityService::class)->detachGalleryImage($amenity, $mediaId);

        return response()->json(['data' => true]);
    }

    private function scopeToAccessibleAmenities(Builder $query): Builder
    {
        $user = auth()->guard('sanctum')->user();

        if ($user === null || $user->tokenCan('*') || $user->tokenCan('update.amenity')) {
            return $query;
        }

        return $query->whereIn(
            'id',
            app(AmenityVisibilityService::class)->accessibleAmenityIds((string) $user->id),
        );
    }
}
