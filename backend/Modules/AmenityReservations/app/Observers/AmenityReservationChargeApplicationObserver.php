<?php

namespace Modules\AmenityReservations\Observers;

use Modules\AmenityReservations\Services\ReservationService;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\PaymentApplication;

/**
 * AmenityReservationChargeApplicationObserver — accounting bridge
 * Collections → AmenityReservations (S8).
 *
 * Registered from AmenityReservationsServiceProvider (Collections code is NOT
 * modified). Every payment application on a charge stamped
 * amenity_reservation_id recomputes-from-sum the reservation's
 * amount_outstanding and advances its payment-driven FSM edge, INSIDE the SAME
 * transaction PaymentService opened — payment, charge and reservation commit
 * or roll back together. Structurally mirrors
 * Modules\Projects\Observers\ProjectChargeApplicationObserver; the actual math
 * is a full recompute-from-sum rather than a signed delta (see
 * ReservationService::syncFromChargeApplication) because the reservation's
 * balance is always relative to its OWN frozen cost_amount snapshot, never a
 * running total.
 *
 * Events covered:
 *   created → recompute (a new earmark payment was applied)
 *   updated → recompute, only on applied_amount change (e.g. credit clawback)
 *   deleted → recompute (a payment cancellation reversed the application)
 */
class AmenityReservationChargeApplicationObserver
{
    public function created(PaymentApplication $application): void
    {
        $this->sync($application);
    }

    public function updated(PaymentApplication $application): void
    {
        if (! $application->wasChanged('applied_amount')) {
            return;
        }

        $this->sync($application);
    }

    /**
     * FIX (reviewer-confirmed HIGH): reversing an earmark payment (payment
     * cancel) deletes its PaymentApplication and zeroes charge.paid_amount, but
     * the charge itself is otherwise a normal OPEN charge again — a FIFO magnet
     * for unrelated maintenance payments, aged by the daily status cron, and
     * re-summed into the unit's worst-wins aggregate. Every amenity-reservation
     * charge is born to serve EXACTLY ONE payment (AmenityChargeService creates
     * one standalone charge per confirmed earmark payment); once that payment
     * is reversed, the charge has no reason to exist as an open charge anymore
     * and must be cancelled. Done BEFORE the sync recompute below so
     * ChargeStatusService::recomputeCharge/recomputeUnitAggregate (both
     * is_cancelled-filtered) never see it as open again.
     */
    public function deleted(PaymentApplication $application): void
    {
        $charge = Charge::find($application->charge_id);

        if (! $charge || $charge->amenity_reservation_id === null) {
            return;
        }

        $expected = (int) $charge->getAttributes()['expected_amount'];
        $paid = (int) $charge->getAttributes()['paid_amount'];

        if (! $charge->is_cancelled && $paid < $expected) {
            $charge->is_cancelled = true;
            $charge->cancellation_reason = 'Reservation earmark payment reversed';
            $charge->save();
        }

        app(ReservationService::class)->syncFromChargeApplication($charge->amenity_reservation_id);
    }

    /**
     * Early-return unless the application's charge was created by an amenity
     * reservation earmark payment — every other charge (maintenance, project
     * contribution, etc.) is none of this observer's business.
     */
    private function sync(PaymentApplication $application): void
    {
        $charge = Charge::find($application->charge_id);

        if (! $charge || $charge->amenity_reservation_id === null) {
            return;
        }

        app(ReservationService::class)->syncFromChargeApplication($charge->amenity_reservation_id);
    }
}
