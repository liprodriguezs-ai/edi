<?php

namespace Modules\AmenityReservations\Services;

use App\Models\User;
use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\AmenityReservations\Models\ReservationStatus;
use Modules\AmenityReservations\Models\ReservationSurcharge;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Services\ChargeStatusService;
use QCH\Core\Support\TenantMoney;

/**
 * AmenitySurchargeService — creates an admin-imposed surcharge (incident/
 * damage charge) against a reservation's unit (S9).
 *
 * CRITICAL, unlike AmenityChargeService (S8 — the reservation's OWN cost/
 * deposit charge): the standalone charge created here NEVER sets
 * `amenity_reservation_id`. That column is read exclusively by
 * AmenityReservationChargeApplicationObserver to sync the reservation's
 * `amount_outstanding` — a surcharge is a SEPARATE real open debt, not part
 * of the reservation's own cost snapshot. It is a normal OPEN charge: it
 * enters Collections FIFO/morosidad/statement like any other charge, and can
 * age into overdue. Traceability to the reservation flows ONLY through the
 * `reservation_surcharge` link row created here.
 */
class AmenitySurchargeService
{
    use NotifiesResidents;

    /**
     * A surcharge only makes sense once the amenity was actually used/booked
     * to completion — an incident during use (CONFIRMED) or discovered after
     * use (COMPLETED). Any other state (pending approval/payment, cancelled,
     * rejected, no-show) is rejected.
     */
    private const SURCHARGEABLE_STATUSES = [
        ReservationStatus::CONFIRMED,
        ReservationStatus::COMPLETED,
    ];

    public function __construct(
        private readonly ChargeStatusService $chargeStatusService,
    ) {}

    /**
     * @throws \InvalidArgumentException If the reservation is not confirmed/completed.
     */
    public function create(
        AmenityReservation $reservation,
        string $chargeConceptId,
        int $amountRaw,
        ?string $description = null,
    ): ReservationSurcharge {
        $surcharge = DB::transaction(function () use ($reservation, $chargeConceptId, $amountRaw, $description): ReservationSurcharge {
            // Row-locked, tenant-scoped re-read — mirrors ReservationService's
            // transition guards: never trust the (possibly stale) instance the
            // controller passed in.
            $locked = AmenityReservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->reservation_status_id, self::SURCHARGEABLE_STATUSES, true)) {
                throw new \InvalidArgumentException('Solo se puede aplicar un cargo adicional a una reservación confirmada o completada.');
            }

            $today = now()->toDateString();

            // (a) Real OPEN debt on the unit — no batch, no policy, and
            // deliberately NO amenity_reservation_id (see class docblock).
            // grace_days is omitted (→ the tenant's collection-setting default)
            // ON PURPOSE: unlike AmenityChargeService's charge (born fully paid
            // in the same transaction, where grace is irrelevant), this is a real
            // open charge that should age into overdue like any Collections debt.
            $charge = Charge::create([
                'charge_concept_id' => $chargeConceptId,
                'charge_policy_id' => null,
                'unit_id' => $locked->unit_id,
                'expected_amount' => $amountRaw,
                'paid_amount' => 0,
                'issued_at' => $today,
                'due_date' => $today,
                'charge_status_id' => $this->chargeStatusService->resolveStatusId(ChargeStatus::PENDING),
                'is_cancelled' => false,
            ]);

            // Recompute so it immediately participates in morosidad/FIFO/statement.
            $this->chargeStatusService->recomputeCharge($charge, 0);
            $this->chargeStatusService->recomputeUnitAggregate($locked->unit_id);

            $surcharge = ReservationSurcharge::create([
                'reservation_id' => $locked->id,
                'charge_id' => $charge->id,
                'charge_concept_id' => $chargeConceptId,
                'amount' => $amountRaw,
                'description' => $description,
                // Fail-fast: this path is always authenticated (route requires
                // auth:sanctum + update.amenity_reservation), and created_by is
                // NOT NULL — a null here should surface loudly, not as a DB error.
                'created_by' => $this->currentUser()->id,
            ]);

            return $surcharge->fresh(['charge', 'charge_concept']);
        });

        // S10: notify AFTER commit — a notification failure must never roll back
        // the charge. $reservation's unit_id/amenity_id are immutable, so using
        // the caller's (possibly pre-transaction) instance for context is safe.
        $this->notifySurchargeAdded($reservation, $surcharge, $amountRaw);

        return $surcharge;
    }

    private function notifySurchargeAdded(AmenityReservation $reservation, ReservationSurcharge $surcharge, int $amountRaw): void
    {
        $amenity = Amenity::find($reservation->amenity_id);
        $amountFormatted = TenantMoney::format($amountRaw);

        $context = [
            'amenity_name' => $amenity?->name ?? 'la amenidad',
            'reservation_date' => $this->formatDate($reservation->reservation_date),
            'amount' => $amountFormatted,
            'concept_name' => $surcharge->charge_concept?->name ?? '',
            'description' => $surcharge->description ?? '',
        ];

        $this->notifyEvent(
            'amenity_reservation.surcharge_added',
            $this->unitResidentUserIds($reservation->unit_id),
            $context,
            'Cargo adicional aplicado',
            "Se aplicó un cargo adicional de {$amountFormatted} a tu reservación de {$context['amenity_name']} ({$context['concept_name']}).",
            [
                'category' => NotificationCategory::AMENITIES,
                'click_action' => '/my-reservations',
                'amenity_reservation_id' => $reservation->id,
                'unit_id' => $reservation->unit_id,
            ],
        );
    }

    private function formatDate(mixed $date): string
    {
        if (! $date) {
            return '';
        }

        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $carbon->format('d/m/Y');
    }

    private function currentUser(): ?User
    {
        /** @var User|null $user */
        $user = auth()->guard('sanctum')->user();

        return $user;
    }
}
