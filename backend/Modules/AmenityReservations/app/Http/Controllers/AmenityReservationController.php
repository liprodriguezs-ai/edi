<?php

namespace Modules\AmenityReservations\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AmenityReservations\Http\Controllers\Concerns\ScopesToResidentUnits;
use Modules\AmenityReservations\Http\Requests\AmenityReservationCancelRequest;
use Modules\AmenityReservations\Http\Requests\AmenityReservationRejectRequest;
use Modules\AmenityReservations\Http\Requests\AmenityReservationSurchargeRequest;
use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\AmenityReservations\Services\AmenityOccupancyService;
use Modules\AmenityReservations\Services\AmenitySurchargeService;
use Modules\AmenityReservations\Services\ReservationService;
use Modules\Collections\Models\PaymentStatus;
use Modules\Housing\Models\UnitUser;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;

/**
 * S6 — reservation CREATION. S7 — FSM transitions (approve/reject/cancel/
 * complete/no_show). S8 — earmark payment wiring. S9 — admin surcharges
 * (incident charges). Oversight occupancy calendar — anonymized cluster-wide
 * occupancy read (see occupancy() below).
 */
class AmenityReservationController extends Controller
{
    use ScopesToResidentUnits;

    protected $model = AmenityReservation::class;

    protected $keyForPermission = 'amenity_reservation';

    public function includes(): array
    {
        return ['amenity', 'amenity.bank_account', 'unit', 'slot', 'reservation_status', 'surcharges', 'surcharges.charge', 'surcharges.charge_concept'];
    }

    public function sortableBy(): array
    {
        return ['reservation_date', 'created_at'];
    }

    public function filterableBy(): array
    {
        return ['amenity_id', 'unit_id', 'reservation_status_id', 'reservation_date'];
    }

    /**
     * BUG FIX (E2E QA): default Orion cap is 100 — the admin calendar
     * (AmenityReservationCalendarPage) requests up to 300 rows for a visible
     * month range, which legitimately can exceed 100 reservations across a
     * busy cluster. Raised here rather than capping the frontend request,
     * since a month CAN have more than 100 reservations.
     */
    public function maxLimit(): int
    {
        return 500;
    }

    /**
     * Security fix: residents/renters only read THEIR OWN units' reservations
     * (mirrors Collections\Http\Controllers\ChargeController). Board/admin
     * (broad `update.amenity_reservation` / `*`, same marker as
     * authorizeCancel() below) keep full cluster visibility.
     *
     * Also exposes `has_pending_payment` (whether the reservation owns at
     * least one PENDING_VALIDATION earmark payment) as a single boolean
     * EXISTS subquery — no N+1, no accessor. Precedent:
     * ChargePolicyController::has_billed. The frontend uses it to gate the
     * "Pagar"/"Registrar pago" actions and show a "Pago en revisión" pill.
     */
    protected function buildIndexFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        $query = parent::buildIndexFetchQuery($request, $requestedRelations);

        if ($this->lacksBoardReadAccess()) {
            $query->whereIn('unit_id', $this->ownUnitIds());
        }

        return $query->withExists([
            'earmark_payments as has_pending_payment' => fn ($q) => $q->where('payment_status_id', PaymentStatus::PENDING_VALIDATION),
        ]);
    }

    /**
     * A foreign unit's reservation resolves to 404 for residents (no
     * information leak), same convention as ChargeController::buildShowFetchQuery.
     * Same `has_pending_payment` exposure as buildIndexFetchQuery() above.
     */
    protected function buildShowFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        $query = parent::buildShowFetchQuery($request, $requestedRelations);

        if ($this->lacksBoardReadAccess()) {
            $query->whereIn('unit_id', $this->ownUnitIds());
        }

        return $query->withExists([
            'earmark_payments as has_pending_payment' => fn ($q) => $q->where('payment_status_id', PaymentStatus::PENDING_VALIDATION),
        ]);
    }

    /**
     * ReservationService::create() owns every business rule (availability,
     * unit-membership authorization, rules acceptance, double-booking/capacity,
     * initial FSM status, money snapshots) inside its own DB transaction —
     * mirrors Access\Http\Controllers\VisitorController::performStore.
     */
    protected function performStore(OrionRequest $request, Model $entity, array $attributes): void
    {
        $reservation = app(ReservationService::class)->create($attributes);
        $entity->setRawAttributes($reservation->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }

    /**
     * GET amenity_reservation/occupancy?from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * Read-only, ANONYMIZED cluster-wide occupancy calendar for the oversight
     * committee (transparency/awareness — see AmenityOccupancyService's
     * docblock for the exact PII-exclusion guarantee: only amenity_id,
     * amenity_name, reservation_date, slot_id, start_at, end_at, occupied and
     * capacity ever cross this boundary — NEVER unit_id, user_id or the
     * reservation's own id). Deliberately does NOT call
     * buildIndexFetchQuery()/ScopesToResidentUnits: this is a hand-rolled
     * aggregate query, cluster-wide by design (safe precisely because it is
     * anonymized), gated only by `read.amenity_reservation` — the same
     * ability residents already hold for their own reservations, and the one
     * granted to oversight_committee.
     */
    public function occupancy(Request $request, AmenityOccupancyService $occupancyService): JsonResponse
    {
        $this->authorize('read.'.$this->keyForPermission, AmenityReservation::class);

        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        try {
            $occupancy = $occupancyService->forRange($validated['from'], $validated['to']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $occupancy]);
    }

    /**
     * POST amenity_reservation/{id}/approve — board/admin only.
     */
    public function approve(string $id): JsonResponse
    {
        $this->authorize('update.'.$this->keyForPermission, AmenityReservation::class);

        $reservation = AmenityReservation::findOrFail($id);

        try {
            $updated = app(ReservationService::class)->approve($reservation);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $updated]);
    }

    /**
     * POST amenity_reservation/{id}/reject — board/admin only.
     */
    public function reject(AmenityReservationRejectRequest $request, string $id): JsonResponse
    {
        $this->authorize('update.'.$this->keyForPermission, AmenityReservation::class);

        $reservation = AmenityReservation::findOrFail($id);

        try {
            $updated = app(ReservationService::class)->reject($reservation, $request->validated('rejected_reason'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $updated]);
    }

    /**
     * POST amenity_reservation/{id}/cancel — board/admin (any reservation) OR
     * the owning resident of the reservation's unit (their OWN reservation
     * only). Mirrors Projects\ProjectContributionController's mixed
     * manage-ability-OR-unit-membership authorization.
     */
    public function cancel(AmenityReservationCancelRequest $request, string $id): JsonResponse
    {
        $reservation = AmenityReservation::findOrFail($id);
        $this->authorizeCancel($reservation);

        try {
            $updated = app(ReservationService::class)->cancel($reservation, $request->validated('cancellation_reason'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $updated]);
    }

    /**
     * POST amenity_reservation/{id}/complete — board/admin only.
     */
    public function complete(string $id): JsonResponse
    {
        $this->authorize('update.'.$this->keyForPermission, AmenityReservation::class);

        $reservation = AmenityReservation::findOrFail($id);

        try {
            $updated = app(ReservationService::class)->complete($reservation);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $updated]);
    }

    /**
     * POST amenity_reservation/{id}/no-show — board/admin only.
     */
    public function noShow(string $id): JsonResponse
    {
        $this->authorize('update.'.$this->keyForPermission, AmenityReservation::class);

        $reservation = AmenityReservation::findOrFail($id);

        try {
            $updated = app(ReservationService::class)->noShow($reservation);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $updated]);
    }

    /**
     * POST amenity_reservation/{id}/surcharges — board/admin only. Creates an
     * OPEN real debt on the reservation's unit for an incident (damage,
     * etc.), allowed only once the reservation is CONFIRMED or COMPLETED.
     */
    public function surcharges(AmenityReservationSurchargeRequest $request, string $id): JsonResponse
    {
        $this->authorize('update.'.$this->keyForPermission, AmenityReservation::class);

        $reservation = AmenityReservation::findOrFail($id);

        try {
            $surcharge = app(AmenitySurchargeService::class)->create(
                $reservation,
                (string) $request->validated('charge_concept_id'),
                (int) $request->validated('amount'),
                $request->validated('description'),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $surcharge], 201);
    }

    /**
     * Board/admin (broad update.amenity_reservation) may cancel any
     * reservation; otherwise only a member of the reservation's unit (owner/
     * renter/etc, unit_user pivot, active membership) may cancel their OWN.
     * Mirrors Projects\ProjectContributionController::authorizeContributionAction.
     *
     * @throws AuthorizationException
     */
    private function authorizeCancel(AmenityReservation $reservation): void
    {
        $user = auth()->guard('sanctum')->user();
        $hasManageAccess = $user && ($user->tokenCan('update.'.$this->keyForPermission) || $user->tokenCan('*'));

        if ($hasManageAccess) {
            return;
        }

        $belongsToUnit = $user && UnitUser::where('unit_id', $reservation->unit_id)
            ->where('user_id', $user->id)
            ->whereNull('end_date')
            ->exists();

        if (! $belongsToUnit) {
            throw new AuthorizationException('Unauthorized action.');
        }
    }
}
