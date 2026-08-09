<?php

namespace Modules\AmenityReservations\Handlers;

use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\AmenityReservations\Models\ReservationStatus;
use Modules\AmenityReservations\Services\AmenityChargeService;
use Modules\AmenityReservations\Support\AmenityReservationsSettings;
use Modules\Collections\Contracts\PaymentEarmarkHandler;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\PaymentStatus;
use Modules\Setting\Models\Setting;
use QCH\Core\Support\TenantMoney;

/**
 * AmenityReservationEarmarkHandler — earmarked payments that fund an amenity
 * reservation's fee/deposit/balance (S8).
 *
 * THE single place that creates an amenity-reservation charge from a payment:
 * every confirmed earmark payment gets its OWN standalone charge, pinned for
 * the full amount (never FIFO toward unrelated unit debt). Registered in
 * AmenityReservationsServiceProvider via PaymentEarmarkRegistry::register
 * (template Modules\Projects\Handlers\ContributionEarmarkHandler). This
 * handler NEVER mutates the reservation's status/amount_outstanding — that is
 * AmenityReservationChargeApplicationObserver's job, wired to
 * PaymentApplication created/updated/deleted, running INSIDE the same
 * transaction right after resolveApplications() returns.
 */
class AmenityReservationEarmarkHandler implements PaymentEarmarkHandler
{
    public const EARMARK_TYPE = 'amenity_reservation';

    /**
     * {@inheritDoc}
     */
    public function validateSubmission(array $data, bool $isSelfSubmission = false): void
    {
        // GA hardening (S17, integration-reviewer W2): payment/submit and
        // payment/register are SHARED Collections routes, not behind
        // EnsureAmenityReservationsEnabled — that middleware only guards the
        // amenity/amenity_reservation route group. Without this check, a
        // tenant with the module disabled could still fund an earmark payment
        // against amenity_reservation through the generic payment endpoints.
        if (Setting::getValue(AmenityReservationsSettings::ENABLED) !== true) {
            throw new \InvalidArgumentException('El módulo de reservaciones está deshabilitado.');
        }

        // Residents self-reporting a payment cannot claim cash: cash never
        // touches Collections' books until an admin/treasurer physically
        // receives and registers it. Admin capture (isSelfSubmission=false,
        // payment/register) keeps cash allowed.
        if ($isSelfSubmission && ($data['payment_method_id'] ?? null) === PaymentMethod::CASH) {
            throw new \InvalidArgumentException('Los residentes no pueden pagar en efectivo; usa transferencia, depósito o cheque.');
        }

        $reservation = AmenityReservation::with('amenity')->find($data['earmark_id'] ?? null);

        if (! $reservation) {
            throw new \InvalidArgumentException('No existe la reservación indicada.');
        }

        if (($data['unit_id'] ?? null) !== $reservation->unit_id) {
            throw new \InvalidArgumentException('La reservación no corresponde a la unidad del pago.');
        }

        $this->assertPayable($reservation);

        $amenity = $reservation->amenity;
        $bankAccountId = $data['bank_account_id'] ?? null;

        if (! $amenity || $amenity->bank_account_id === null || $bankAccountId !== $amenity->bank_account_id) {
            throw new \InvalidArgumentException('El pago debe registrarse en la cuenta bancaria configurada para esta amenidad.');
        }

        $totalAmountRaw = (int) ($data['total_amount'] ?? 0);
        $outstandingRaw = (int) $reservation->getAttributes()['amount_outstanding'];

        if ($totalAmountRaw > $outstandingRaw) {
            throw new \InvalidArgumentException('El monto del pago supera el saldo pendiente de la reservación.');
        }

        // First payment (nothing settled yet — outstanding still equals the
        // frozen cost_amount snapshot): the deposit is the minimum first
        // installment ON the cost (§ deposit = anticipo, never a separate
        // garantía). Gate on the RESERVATION's own deposit_amount SNAPSHOT
        // (S6) being > 0 — NEVER on the amenity's current requires_deposit
        // flag: an admin toggling requires_deposit off after the reservation
        // was placed must not relax the minimum this specific reservation
        // already committed to.
        $costRaw = (int) ($reservation->getAttributes()['cost_amount'] ?? 0);
        $isFirstPayment = $outstandingRaw === $costRaw;
        $depositRaw = (int) ($reservation->getAttributes()['deposit_amount'] ?? 0);

        if ($isFirstPayment && $depositRaw > 0 && $totalAmountRaw < $depositRaw) {
            throw new \InvalidArgumentException(
                'El primer pago debe cubrir al menos el depósito de '.TenantMoney::format($depositRaw).'.'
            );
        }

        // Friendly guard against a duplicate report; the partial unique index
        // payment_one_pending_per_earmark is the race-proof lock behind it.
        $hasPendingReport = Payment::query()
            ->where('earmark_type', self::EARMARK_TYPE)
            ->where('earmark_id', $reservation->id)
            ->where('payment_status_id', PaymentStatus::PENDING_VALIDATION)
            ->exists();

        if ($hasPendingReport) {
            throw new \InvalidArgumentException('Ya hay un pago pendiente de validación para esta reservación. Espera su revisión.');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function resolveApplications(Payment $payment): array
    {
        $reservation = AmenityReservation::query()->lockForUpdate()->findOrFail($payment->earmark_id);
        $reservation->load('amenity');

        // Defensive re-check under lock: the payment↔reservation binding is
        // immutable post-submit today (the payment Orion resource is
        // read-only), but the handler must not depend on that staying true —
        // and the reservation's own payable/outstanding state may have
        // changed since submit (mirrors ContributionEarmarkHandler).
        if ($reservation->unit_id !== $payment->unit_id) {
            throw new \InvalidArgumentException('El pago no corresponde a la unidad de la reservación.');
        }

        $this->assertPayable($reservation);

        $totalAmountRaw = (int) $payment->getAttributes()['total_amount'];
        $outstandingRaw = (int) $reservation->getAttributes()['amount_outstanding'];

        if ($totalAmountRaw > $outstandingRaw) {
            throw new \InvalidArgumentException('El monto del pago supera el saldo pendiente de la reservación.');
        }

        $charge = app(AmenityChargeService::class)->createChargeForReservation($reservation, $totalAmountRaw);

        return [
            ['charge_id' => $charge->id, 'apply_amount' => $totalAmountRaw],
        ];
    }

    /**
     * A reservation only accepts earmarked money while PENDING_PAYMENT or
     * already CONFIRMED with a remaining balance (deposit-only confirmation
     * awaiting the rest). Any other status — including PENDING_APPROVAL (pay
     * comes AFTER approval, spec R3) and every terminal status — rejects the
     * payment.
     *
     * @throws \InvalidArgumentException
     */
    private function assertPayable(AmenityReservation $reservation): void
    {
        $payableStatuses = [ReservationStatus::PENDING_PAYMENT, ReservationStatus::CONFIRMED];

        if (! in_array($reservation->reservation_status_id, $payableStatuses, true)) {
            throw new \InvalidArgumentException('La reservación no admite pagos en su estado actual.');
        }

        $outstandingRaw = (int) $reservation->getAttributes()['amount_outstanding'];

        if ($outstandingRaw <= 0) {
            throw new \InvalidArgumentException('La reservación no tiene saldo pendiente.');
        }
    }
}
