<?php

namespace Modules\AmenityReservations\Observers;

use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Carbon\Carbon;
use Modules\AmenityReservations\Handlers\AmenityReservationEarmarkHandler;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\AmenityReservations\Services\ReservationService;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentStatus;
use QCH\Core\Support\TenantMoney;

/**
 * AmenityReservationPaymentRejectionObserver — S10: fires
 * amenity_reservation.payment_rejected when the treasurer rejects a resident's
 * amenity earmark payment via Collections\PaymentService::reject(). A1: also
 * re-arms the reservation's own deadline via
 * ReservationService::extendDeadlineAfterPaymentRejection() so a rejection
 * doesn't leave the resident with zero time to resubmit — that method's own
 * ACTIVE-status guard makes it a no-op when this same rejection is cascading
 * FROM ReservationService::cancel()/expire() itself (status already flipped
 * to CANCELLED before rejectPendingEarmarkPayments() runs).
 *
 * Registered on the Payment model (mirrors how AmenityReservationsServiceProvider
 * already registers AmenityReservationChargeApplicationObserver on
 * PaymentApplication) so Collections stays completely untouched — no code in
 * PaymentService/PaymentController changes for this event to exist.
 *
 * PaymentService::reject() ALREADY fires the generic Collections 'payment_rejected'
 * event to the unit's billing owner (financial-language copy). This observer adds
 * a SECOND, amenity-specific notification with reservation context (amenity name,
 * date) — a resident rejecting/resubmitting an amenity payment sees both. That is
 * intentional, not a bug: the two events are independently configurable per tenant
 * (an admin can disable one without disabling the other).
 *
 * Scoped to a STRAIGHT reject (PENDING_VALIDATION → CANCELLED) via the original
 * status check: a CONFIRMED → CANCELLED transition is a reservation-driven
 * reversal (ReservationService::cancel()'s reverseConfirmedEarmarkPayments(), or a
 * direct treasurer /payment/{id}/cancel) — already covered by
 * amenity_reservation.cancelled, or intentionally has no dedicated event.
 */
class AmenityReservationPaymentRejectionObserver
{
    use NotifiesResidents;

    public function updated(Payment $payment): void
    {
        if ($payment->earmark_type !== AmenityReservationEarmarkHandler::EARMARK_TYPE) {
            return;
        }

        if (! $payment->wasChanged('payment_status_id') || $payment->payment_status_id !== PaymentStatus::CANCELLED) {
            return;
        }

        if ($payment->getOriginal('payment_status_id') !== PaymentStatus::PENDING_VALIDATION) {
            return;
        }

        $reservation = AmenityReservation::find($payment->earmark_id);

        if (! $reservation) {
            return;
        }

        app(ReservationService::class)->extendDeadlineAfterPaymentRejection($reservation->id);

        $amenity = Amenity::find($reservation->amenity_id);
        $amountRaw = (int) $payment->getAttributes()['total_amount'];
        $reason = (string) ($payment->getAttributes()['cancellation_reason'] ?? '');
        $amountFormatted = TenantMoney::format($amountRaw);

        $context = [
            'amenity_name' => $amenity?->name ?? 'la amenidad',
            'reservation_date' => $this->formatDate($reservation->reservation_date),
            'amount' => $amountFormatted,
            'reason' => $reason,
        ];

        $this->notifyEvent(
            'amenity_reservation.payment_rejected',
            $this->unitResidentUserIds($reservation->unit_id),
            $context,
            'Pago de reservación rechazado',
            "Tu pago de {$amountFormatted} para la reservación de {$context['amenity_name']} fue rechazado: {$reason}",
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
}
