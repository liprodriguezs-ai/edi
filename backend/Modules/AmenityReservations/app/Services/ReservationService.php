<?php

namespace Modules\AmenityReservations\Services;

use App\Models\User;
use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\AmenityReservations\Handlers\AmenityReservationEarmarkHandler;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\AmenityReservations\Models\AmenitySlot;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Models\ReservationStatus;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentApplication;
use Modules\Collections\Models\PaymentStatus;
use Modules\Collections\Services\DelinquencyRestrictionService;
use Modules\Collections\Services\PaymentService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitUser;
use Modules\Notification\Services\NotificationSettingService;
use Modules\SubscriptionPlan\Models\PlanQuota;
use Modules\SubscriptionPlan\Services\QuotaService;
use QCH\Core\Support\TenantMoney;

/**
 * S6 (creation) + S7 (FSM transitions: approve/reject/cancel/complete/
 * no_show) + S8 (payment-driven FSM edges: reverseConfirmedEarmarkPayments()
 * on cancel(), syncFromChargeApplication() called by
 * AmenityReservationChargeApplicationObserver) + S11 (expire(): the cron-driven
 * counterpart to cancel(), auto-cancelling reservations past their payment
 * deadline). The earmark handler itself (validation + charge creation) lives
 * in AmenityReservationEarmarkHandler.
 *
 * All business rules run inside ONE DB::transaction: resolving the amenity
 * (tenant-scoped, row-locked) doubles as the atomicity boundary for the
 * by_slot capacity check (lock-then-count-then-insert). Every S7 transition
 * follows the SAME concurrency pattern used across the codebase (Collections
 * PaymentService::validatePayment()/reject()/cancel()): re-read the
 * reservation UNDER LOCK inside the transaction and validate the CURRENT
 * status on the LOCKED copy — never on the (possibly stale) instance the
 * controller passed in — so two concurrent transitions on the same
 * reservation serialize and only one can pass the status check.
 */
class ReservationService
{
    use NotifiesResidents;

    /**
     * Fallback day-count for payment_deadline when the amenity has no
     * deposit_due_days configured (has_cost=true but requires_deposit=false —
     * there is no other "days to pay" field on Amenity to reuse).
     */
    private const DEFAULT_PAYMENT_DEADLINE_DAYS = 3;

    /**
     * Statuses from which a reservation may still be cancelled (spec R3).
     * completed/rejected/cancelled/no_show are terminal — cancelling any of
     * them is a forbidden transition.
     */
    private const ACTIVE_STATUSES = [
        ReservationStatus::PENDING_APPROVAL,
        ReservationStatus::PENDING_PAYMENT,
        ReservationStatus::CONFIRMED,
    ];

    /**
     * S11: the fixed cancellation_reason stamped by expire() — the
     * machine-driven counterpart to cancel()'s free-text $reason. Fixed on
     * purpose: every cron-driven expiry reads identically, unlike a
     * human-entered cancellation reason.
     */
    private const EXPIRATION_REASON = 'Vencimiento de plazo de pago';

    /**
     * A1: the grace window a payment rejection re-arms on top of the
     * reservation's current deadline (never SHORTENED, only ever pushed
     * forward) — protects a resident who resubmits right after a rejection
     * from being expired by a cron run that lands moments later.
     */
    private const REJECTION_GRACE_DAYS = 1;

    /** Relations every transition response loads, mirroring create()'s response shape. */
    private const RESPONSE_RELATIONS = ['amenity', 'unit', 'slot', 'reservation_status'];

    public function __construct(
        private readonly AmenityAvailabilityService $availabilityService,
        private readonly AmenityVisibilityService $visibilityService,
        private readonly DelinquencyRestrictionService $delinquencyRestrictionService,
        private readonly QuotaService $quotaService,
    ) {}

    /**
     * @param  array<string, mixed>  $data  amenity_id, unit_id, reservation_date (Y-m-d),
     *                                      slot_id (nullable, by_slot only), rules_accepted (bool, nullable)
     */
    public function create(array $data): AmenityReservation
    {
        $reservation = DB::transaction(function () use ($data): AmenityReservation {
            // Manual, tenant-scoped resolution + row lock — NOT implicit route
            // binding (which can resolve across tenants before TenantScope is
            // safely applied). The lock is what makes the by_slot
            // count-then-insert capacity check below atomic under concurrency.
            $amenity = Amenity::whereKey($data['amenity_id'])->lockForUpdate()->firstOrFail();

            $this->assertAvailable($amenity, (string) $data['reservation_date']);

            $this->authorizeUnitMembership((string) $data['unit_id']);

            // R-DELQ-4 hook 1: OVERDUE units above threshold cannot create reservations
            // when delinquency.block_amenities is on. Runs AFTER membership so a
            // non-member still gets the plain AuthorizationException, never a
            // debt-shaped 403 (design §5).
            $this->delinquencyRestrictionService->assertNotRestricted(
                (string) $data['unit_id'],
                DelinquencyRestrictionService::FEATURE_AMENITIES,
            );

            // trial-usage-quotas (REQ-CT-01, REQ-CT-02): consume INSIDE this
            // transaction, before the insert, so a later rollback (e.g. the
            // by_day duplicate-booking abort below) unwinds the increment for
            // free — the counter never over-counts a reservation that never
            // actually persisted.
            // trial-usage-quotas (REQ-CT-01, REQ-CT-02): consume INSIDE this
            // transaction, before the insert, so a later rollback (e.g. the
            // by_day duplicate-booking abort below) unwinds the increment for
            // free — the counter never over-counts a reservation that never
            // actually persisted.
            $this->quotaService->assertAndConsume(PlanQuota::RESOURCE_AMENITY_RESERVATION);

            $this->authorizeAmenityTarget($amenity, (string) $data['unit_id']);

            $rulesAcceptedAt = $this->assertRulesAcceptedIfRequired($amenity, $data);

            $isBySlot = $amenity->reservation_mode_id === ReservationMode::BY_SLOT;
            $slot = $isBySlot
                ? $this->resolveAndCheckSlotCapacity($amenity, $data)
                : $this->rejectSlotForByDayAmenity($data);

            $timezone = $this->tenantTimezone();
            [$startAt, $endAt] = $this->materializeWindow($data['reservation_date'], $slot, $timezone);

            [$statusId, $paymentDeadline] = $this->resolveInitialStatus($amenity, $timezone);

            try {
                $reservation = AmenityReservation::create([
                    'amenity_id' => $amenity->id,
                    'unit_id' => $data['unit_id'],
                    'user_id' => $this->currentUser()?->id,
                    'reservation_date' => $data['reservation_date'],
                    'slot_id' => $slot?->id,
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'reservation_status_id' => $statusId,
                    'holds_slot' => true,
                    'rules_accepted_at' => $rulesAcceptedAt,
                    'cost_amount' => $amenity->has_cost ? ($amenity->getAttributes()['cost_amount'] ?? null) : null,
                    'deposit_amount' => $amenity->requires_deposit ? ($amenity->getAttributes()['deposit_amount'] ?? null) : null,
                    'amount_outstanding' => $amenity->has_cost ? (int) ($amenity->getAttributes()['cost_amount'] ?? 0) : 0,
                    'payment_deadline' => $paymentDeadline,
                ]);
            } catch (UniqueConstraintViolationException) {
                // The amenity_reservation_by_day_unique partial index rejected a
                // concurrent by_day booking for the same (tenant, amenity, date).
                abort(409, 'Ese horario ya fue reservado.');
            }

            return $reservation->fresh(self::RESPONSE_RELATIONS);
        });

        // S10: notify AFTER commit — a notification failure must never roll back
        // the reservation. amenity_reservation.created always fires (admin/board
        // heads-up); payment_required fires additionally when the amenity's cost
        // put the reservation straight into PENDING_PAYMENT (no approval required).
        $this->notifyReservationCreated($reservation);

        if ($reservation->reservation_status_id === ReservationStatus::PENDING_PAYMENT) {
            $this->notifyPaymentRequired($reservation);
        }

        return $reservation;
    }

    /**
     * Board/admin approves a PENDING_APPROVAL reservation (spec R3, pay-after-
     * approval ordering). Whether the reservation is costed was ALREADY decided
     * at creation time — create()'s cost_amount/deposit_amount snapshot is
     * non-null iff the amenity had_cost when booked, regardless of
     * requires_approval — so approval does NOT re-evaluate the amenity's
     * CURRENT has_cost flag: a later config change never retroactively adds or
     * removes a cost from an already-placed reservation (same snapshot
     * invariant documented on the amenity_reservation migration). Only
     * deposit_due_days (not snapshotted) is read fresh from Amenity to compute
     * payment_deadline.
     *
     * @throws \InvalidArgumentException If the reservation is not pending approval.
     */
    public function approve(AmenityReservation $reservation): AmenityReservation
    {
        $updated = DB::transaction(function () use ($reservation): AmenityReservation {
            $locked = $this->lockReservation($reservation->id);

            if ($locked->reservation_status_id !== ReservationStatus::PENDING_APPROVAL) {
                throw new \InvalidArgumentException('Solo se puede aprobar una reservación pendiente de aprobación.');
            }

            $isCosted = $locked->getAttributes()['cost_amount'] !== null;

            if ($isCosted) {
                $amenity = Amenity::find($locked->amenity_id);
                $days = $amenity?->deposit_due_days ?? self::DEFAULT_PAYMENT_DEADLINE_DAYS;

                $locked->reservation_status_id = ReservationStatus::PENDING_PAYMENT;
                $locked->payment_deadline = now($this->tenantTimezone())->addDays($days)->utc();
            } else {
                $locked->reservation_status_id = ReservationStatus::CONFIRMED;
            }

            $locked->approved_by = $this->currentUser()?->id;
            $locked->approved_at = now();
            $locked->save();

            return $locked->fresh(self::RESPONSE_RELATIONS);
        });

        // S10: notify AFTER commit. amenity_reservation.approved always fires;
        // payment_required additionally fires when approval landed the
        // reservation in PENDING_PAYMENT (the amenity is costed).
        $this->notifyReservationApproved($updated);

        if ($updated->reservation_status_id === ReservationStatus::PENDING_PAYMENT) {
            $this->notifyPaymentRequired($updated);
        }

        return $updated;
    }

    /**
     * Board/admin rejects a PENDING_APPROVAL reservation (spec R3). Frees the
     * slot/date immediately (holds_slot=false) so another resident can book it.
     *
     * @throws \InvalidArgumentException If the reservation is not pending approval.
     */
    public function reject(AmenityReservation $reservation, string $reason): AmenityReservation
    {
        $rejected = DB::transaction(function () use ($reservation, $reason): AmenityReservation {
            $locked = $this->lockReservation($reservation->id);

            if ($locked->reservation_status_id !== ReservationStatus::PENDING_APPROVAL) {
                throw new \InvalidArgumentException('Solo se puede rechazar una reservación pendiente de aprobación.');
            }

            $locked->reservation_status_id = ReservationStatus::REJECTED;
            $locked->rejected_reason = $reason;
            $locked->holds_slot = false;
            $locked->save();

            return $locked->fresh(self::RESPONSE_RELATIONS);
        });

        // S10: notify AFTER commit.
        $this->notifyReservationRejected($rejected, $reason);

        return $rejected;
    }

    /**
     * Board/admin OR the owning resident (see Controller::authorizeCancel)
     * cancels a reservation that is still ACTIVE (spec R3: pending_approval,
     * pending_payment, or confirmed — completed/rejected/cancelled/no_show are
     * terminal, cancelling any of them is a forbidden transition).
     *
     * @throws \InvalidArgumentException If the reservation is not in an active status.
     */
    public function cancel(AmenityReservation $reservation, string $reason): AmenityReservation
    {
        $cancelled = DB::transaction(function () use ($reservation, $reason): AmenityReservation {
            $locked = $this->lockReservation($reservation->id);

            if (! in_array($locked->reservation_status_id, self::ACTIVE_STATUSES, true)) {
                throw new \InvalidArgumentException('Esta reservación ya no se puede cancelar.');
            }

            // FIX (E2E QA — stale amount_outstanding on a terminal reservation):
            // the terminal status/fields are written FIRST, BEFORE reversing
            // earmark payments. reverseConfirmedEarmarkPayments() → PaymentService::
            // cancel() cascades into AmenityReservationChargeApplicationObserver,
            // which calls syncFromChargeApplication() against a SEPARATE, freshly-
            // locked read of this SAME row. That method already early-returns for
            // any non-ACTIVE status (see its guard at the top) — writing CANCELLED
            // first makes that guard actually fire, so the cascade can never
            // recompute/resurrect amount_outstanding back up to the frozen
            // cost_amount snapshot on an already-dead reservation. Before this fix,
            // CANCELLED was written LAST: the cascade ran while this row still
            // read as an ACTIVE status, recomputed amount_outstanding to the full
            // cost, and that stale value survived the final save (which only
            // touches status/reason/cancelled_* — not amount_outstanding).
            $locked->reservation_status_id = ReservationStatus::CANCELLED;
            $locked->cancellation_reason = $reason;
            $locked->cancelled_by = $this->currentUser()?->id;
            $locked->cancelled_at = now();
            $locked->holds_slot = false;
            $locked->save();

            $this->reverseConfirmedEarmarkPayments($locked, $reason);
            $this->rejectPendingEarmarkPayments($locked, $reason);

            return $locked->fresh(self::RESPONSE_RELATIONS);
        });

        // S10: notify AFTER commit.
        $this->notifyReservationCancelled($cancelled, $reason);

        return $cancelled;
    }

    /**
     * S11 cron entry point (spec R9): auto-cancels a reservation whose payment
     * deadline lapsed WITHOUT full settlement — either PENDING_PAYMENT past its
     * payment_deadline, or CONFIRMED with an unpaid balance past its
     * balance_due_date. Mirrors cancel() (same reversal + re-lock-after-
     * reversal dance, see its docblock) but fires notifyExpired() instead of
     * the admin-driven amenity_reservation.cancelled event.
     *
     * IDEMPOTENT: CancelExpiredReservationsCommand selects candidates via its
     * own query, but isExpirable() re-verifies BOTH the status AND the
     * deadline on the LOCKED copy — never trusting the caller's possibly-
     * stale read. A reservation that was already resolved (paid off,
     * cancelled by an admin, or simply no longer past its deadline once
     * re-checked under lock) is a silent no-op, so a second cron run — or a
     * race with a concurrent action — never double-processes it.
     */
    public function expire(AmenityReservation $reservation): void
    {
        $expired = DB::transaction(function () use ($reservation): ?AmenityReservation {
            $locked = $this->lockReservation($reservation->id);

            if (! $this->isExpirable($locked)) {
                return null;
            }

            // FIX (E2E QA — stale amount_outstanding on a terminal reservation):
            // same reordering as cancel() above — write CANCELLED FIRST so
            // syncFromChargeApplication's terminal-status guard blocks the
            // reverseConfirmedEarmarkPayments() cascade from resurrecting
            // amount_outstanding back up to the full cost snapshot.
            $locked->reservation_status_id = ReservationStatus::CANCELLED;
            $locked->cancellation_reason = self::EXPIRATION_REASON;
            $locked->cancelled_by = $this->currentUser()?->id;
            $locked->cancelled_at = now();
            $locked->holds_slot = false;
            $locked->save();

            $this->reverseConfirmedEarmarkPayments($locked, self::EXPIRATION_REASON);
            $this->rejectPendingEarmarkPayments($locked, self::EXPIRATION_REASON);

            return $locked->fresh(self::RESPONSE_RELATIONS);
        });

        // S10 hook: notify AFTER commit — a notification failure must never
        // roll back the expiry. Skipped entirely on the idempotent no-op path.
        if ($expired !== null) {
            $this->notifyExpired($expired);
        }
    }

    /**
     * Whether a reservation is STILL past its own deadline right now,
     * re-verified on the LOCKED copy (spec R9). Tenant-tz aware throughout
     * (never a bare now()/Carbon::today()) — see class docblock's TIMEZONE note.
     *
     * balance_due_date is a plain DATE (no time-of-day): comparing the two
     * date STRINGS (today's tenant-tz date vs. the stored due date) is exactly
     * equivalent to "the whole due day has elapsed in the tenant's own
     * calendar" — correctly NOT expiring a reservation whose due date is
     * still today, even when a bare UTC comparison would already show a later
     * calendar date (e.g. tenant tz behind UTC, evening due-date instants).
     */
    private function isExpirable(AmenityReservation $reservation): bool
    {
        // A1: a payment awaiting treasurer review holds off expiry entirely —
        // re-verified under the SAME lock expire() already took, so this is
        // race-safe the same way the deadline checks below are. Rejecting that
        // payment (AmenityReservationPaymentRejectionObserver) both clears
        // this guard AND re-arms the deadline via
        // extendDeadlineAfterPaymentRejection(), so expiry resumes on the
        // NEXT cron run rather than firing immediately against a deadline
        // that may have already lapsed while the payment was pending.
        if ($reservation->earmark_payments()->where('payment_status_id', PaymentStatus::PENDING_VALIDATION)->exists()) {
            return false;
        }

        $timezone = $this->tenantTimezone();

        if ($reservation->reservation_status_id === ReservationStatus::PENDING_PAYMENT) {
            return $reservation->payment_deadline !== null
                && now($timezone)->greaterThan($reservation->payment_deadline);
        }

        if ($reservation->reservation_status_id === ReservationStatus::CONFIRMED) {
            $outstandingRaw = (int) $reservation->getAttributes()['amount_outstanding'];

            return $outstandingRaw > 0
                && $reservation->balance_due_date !== null
                && now($timezone)->toDateString() > $reservation->balance_due_date->toDateString();
        }

        return false;
    }

    /**
     * Board/admin marks a CONFIRMED reservation as COMPLETED (spec R3) once the
     * reserved date/slot has passed uneventfully. Deposit RETURN is an S8/S9
     * concern (earmark reversal against the confirmed deposit payment) — not
     * implemented here; holds_slot is left unchanged (the date has already
     * passed, so it can never collide with a future booking).
     *
     * @throws \InvalidArgumentException If the reservation is not confirmed.
     */
    public function complete(AmenityReservation $reservation): AmenityReservation
    {
        return DB::transaction(function () use ($reservation): AmenityReservation {
            $locked = $this->lockReservation($reservation->id);

            if ($locked->reservation_status_id !== ReservationStatus::CONFIRMED) {
                throw new \InvalidArgumentException('Solo se puede completar una reservación confirmada.');
            }

            $locked->reservation_status_id = ReservationStatus::COMPLETED;
            $locked->save();

            return $locked->fresh(self::RESPONSE_RELATIONS);
        });
    }

    /**
     * Board/admin marks a CONFIRMED reservation as NO_SHOW (spec R3): the
     * resident never showed up. Deposit FORFEITURE is an S8/S9 concern — not
     * implemented here; holds_slot is left unchanged (same reasoning as
     * complete()).
     *
     * @throws \InvalidArgumentException If the reservation is not confirmed.
     */
    public function noShow(AmenityReservation $reservation): AmenityReservation
    {
        return DB::transaction(function () use ($reservation): AmenityReservation {
            $locked = $this->lockReservation($reservation->id);

            if ($locked->reservation_status_id !== ReservationStatus::CONFIRMED) {
                throw new \InvalidArgumentException('Solo se puede marcar como no-show una reservación confirmada.');
            }

            $locked->reservation_status_id = ReservationStatus::NO_SHOW;
            $locked->save();

            return $locked->fresh(self::RESPONSE_RELATIONS);
        });
    }

    // =========================================================================
    // S10 — Notifications
    // =========================================================================

    /**
     * S11 hook: the future payment-reminder cron calls this for each reservation
     * whose payment_deadline/balance_due_date is approaching. Defined here (S10)
     * so the event/config/template pipeline already exists before S11 wires the
     * actual schedule/query that selects which reservations are due.
     */
    public function notifyPaymentReminder(AmenityReservation $reservation): void
    {
        $context = $this->reservationContext($reservation);
        $outstandingRaw = (int) $reservation->getAttributes()['amount_outstanding'];
        $context['amount_outstanding'] = $this->formatMoney($outstandingRaw);
        $deadline = $reservation->balance_due_date ?? $reservation->payment_deadline;
        $context['deadline'] = $this->formatDate($deadline);

        $this->notifyEvent(
            'amenity_reservation.payment_reminder',
            $this->unitResidentUserIds($reservation->unit_id),
            $context,
            'Recordatorio de pago de reservación',
            "Tu reservación de {$context['amenity_name']} para el {$context['reservation_date']} tiene un saldo pendiente de {$context['amount_outstanding']}, vence el {$context['deadline']}.",
            [
                'category' => NotificationCategory::AMENITIES,
                'click_action' => '/my-reservations',
                'amenity_reservation_id' => $reservation->id,
                'unit_id' => $reservation->unit_id,
            ],
        );
    }

    /**
     * S11 hook: the future expiration cron calls this when a reservation's
     * payment deadline lapses without full settlement. This method ONLY fires
     * the notification — S11 owns the actual FSM transition (e.g. auto-cancel +
     * earmark payment reversal) and decides when relative to that to call this.
     */
    public function notifyExpired(AmenityReservation $reservation): void
    {
        $context = $this->reservationContext($reservation);

        $this->notifyEvent(
            'amenity_reservation.expired',
            $this->unitResidentUserIds($reservation->unit_id),
            $context,
            'Reservación expirada',
            "Tu reservación de {$context['amenity_name']} para el {$context['reservation_date']} expiró por falta de pago.",
            [
                'category' => NotificationCategory::AMENITIES,
                'click_action' => '/my-reservations',
                'amenity_reservation_id' => $reservation->id,
                'unit_id' => $reservation->unit_id,
            ],
        );
    }

    /**
     * amenity_reservation.created — admin/board heads-up on every new reservation.
     * Recipients merge the CONFIGURABLE board-role picker (default President +
     * Treasurer, seeded by TenantNotificationSettingSeeder) with the cluster's
     * FIXED 'administrator' Spatie role — administrator isn't a board_role catalog
     * row, so it can never come from boardRecipientsForEvent() and is merged in
     * directly (mirrors BillingNotificationService::notifyTrialExpired's precedent
     * of resolving fixed roles alongside a cosmetic audience=board).
     */
    private function notifyReservationCreated(AmenityReservation $reservation): void
    {
        $resolved = app(NotificationSettingService::class)->resolve('amenity_reservation.created');
        $context = $this->reservationContext($reservation);

        // Board readers can't tell "whose reservation is this?" from amenity + date
        // alone, so we additively enrich the shared $context with unit and resident
        // identity. These keys ride the SAME $context notifyEvent() passes to both
        // the email template and the in-app TemplateRenderer — there's no separate
        // email-only context — but no in-app template references these tokens today,
        // so this is harmless for the in-app/push channels. Both keys are non-null
        // (empty-string fallback): this flow passes context straight to Blade, so a
        // missing key would render an empty {{ $var }} instead of throwing.
        $unit = $reservation->relationLoaded('unit')
            ? $reservation->unit
            : Unit::find($reservation->unit_id);
        $context['unit_label'] = $unit?->full_identifier ?? '';
        $context['resident_name'] = User::find($reservation->user_id)?->name ?? '';

        $recipients = array_values(array_unique(array_merge(
            $this->boardRecipientsForEvent('amenity_reservation.created', $resolved),
            $this->userIdsWithRole('administrator'),
        )));

        $this->notifyEvent(
            'amenity_reservation.created',
            $recipients,
            $context,
            'Nueva reservación de amenidad',
            "Se creó una nueva reservación de {$context['amenity_name']} para el {$context['reservation_date']}.",
            [
                'category' => NotificationCategory::AMENITIES,
                'click_action' => '/amenity-reservation',
                'amenity_reservation_id' => $reservation->id,
                'unit_id' => $reservation->unit_id,
            ],
            $resolved,
        );
    }

    private function notifyReservationApproved(AmenityReservation $reservation): void
    {
        $context = $this->reservationContext($reservation);

        $this->notifyEvent(
            'amenity_reservation.approved',
            $this->unitResidentUserIds($reservation->unit_id),
            $context,
            'Reservación aprobada',
            "Tu reservación de {$context['amenity_name']} para el {$context['reservation_date']} fue aprobada.",
            [
                'category' => NotificationCategory::AMENITIES,
                'click_action' => '/my-reservations',
                'amenity_reservation_id' => $reservation->id,
                'unit_id' => $reservation->unit_id,
            ],
        );
    }

    private function notifyReservationRejected(AmenityReservation $reservation, string $reason): void
    {
        $context = $this->reservationContext($reservation);
        $context['reason'] = $reason;

        $this->notifyEvent(
            'amenity_reservation.rejected',
            $this->unitResidentUserIds($reservation->unit_id),
            $context,
            'Reservación rechazada',
            "Tu reservación de {$context['amenity_name']} para el {$context['reservation_date']} fue rechazada: {$reason}",
            [
                'category' => NotificationCategory::AMENITIES,
                'click_action' => '/my-reservations',
                'amenity_reservation_id' => $reservation->id,
                'unit_id' => $reservation->unit_id,
            ],
        );
    }

    /**
     * amenity_reservation.cancelled — "resident (+ board)": TWO notifyEvent calls
     * sharing ONE resolve() (arch W1 — avoid a double resolve) so the same
     * is_active/channel config governs both audiences, each with copy and
     * click_action tailored to its reader (resident → /my-reservations, board →
     * /amenity-reservation). Board here is the SAME fixed role set as
     * notifyReservationCreated (president/treasurer/administrator) — a secondary
     * heads-up, not the configurable board-role picker.
     */
    private function notifyReservationCancelled(AmenityReservation $reservation, string $reason): void
    {
        $resolved = app(NotificationSettingService::class)->resolve('amenity_reservation.cancelled');
        $context = $this->reservationContext($reservation);
        $context['reason'] = $reason;

        $this->notifyEvent(
            'amenity_reservation.cancelled',
            $this->unitResidentUserIds($reservation->unit_id),
            $context,
            'Reservación cancelada',
            "Tu reservación de {$context['amenity_name']} para el {$context['reservation_date']} fue cancelada. Motivo: {$reason}",
            [
                'category' => NotificationCategory::AMENITIES,
                'click_action' => '/my-reservations',
                'amenity_reservation_id' => $reservation->id,
                'unit_id' => $reservation->unit_id,
            ],
            $resolved,
        );

        $adminRecipients = $this->userIdsWithRole(['president', 'treasurer', 'administrator']);

        $this->notifyEvent(
            'amenity_reservation.cancelled',
            $adminRecipients,
            $context,
            'Reservación cancelada',
            "La reservación de {$context['amenity_name']} para el {$context['reservation_date']} fue cancelada. Motivo: {$reason}",
            [
                'category' => NotificationCategory::AMENITIES,
                'click_action' => '/amenity-reservation',
                'amenity_reservation_id' => $reservation->id,
                'unit_id' => $reservation->unit_id,
            ],
            $resolved,
        );
    }

    /**
     * amenity_reservation.payment_required — fired both when create() lands a
     * costed amenity straight into PENDING_PAYMENT and when approve() moves a
     * PENDING_APPROVAL reservation into PENDING_PAYMENT. The amount due is the
     * deposit when one is required, otherwise the full outstanding balance.
     */
    private function notifyPaymentRequired(AmenityReservation $reservation): void
    {
        $context = $this->reservationContext($reservation);
        $outstandingRaw = (int) $reservation->getAttributes()['amount_outstanding'];
        $depositRaw = (int) ($reservation->getAttributes()['deposit_amount'] ?? 0);
        $amountDueRaw = $depositRaw > 0 ? $depositRaw : $outstandingRaw;
        $amountFormatted = $this->formatMoney($amountDueRaw);
        $deadlineFormatted = $this->formatDate($reservation->payment_deadline);

        $context['amount'] = $amountFormatted;
        $context['deadline'] = $deadlineFormatted;

        $this->notifyEvent(
            'amenity_reservation.payment_required',
            $this->unitResidentUserIds($reservation->unit_id),
            $context,
            'Pago requerido para tu reservación',
            "Tu reservación de {$context['amenity_name']} para el {$context['reservation_date']} requiere un pago de {$amountFormatted} antes del {$deadlineFormatted}.",
            [
                'category' => NotificationCategory::AMENITIES,
                'click_action' => '/my-reservations',
                'amenity_reservation_id' => $reservation->id,
                'unit_id' => $reservation->unit_id,
            ],
        );
    }

    /**
     * amenity_reservation.deposit_confirmed / amenity_reservation.payment_confirmed
     * — fired from syncFromChargeApplication() with the event key it already
     * decided based on the resulting amount_outstanding (0 → full settle;
     * >0 & deposit met → deposit-only settle).
     *
     * NOTE (title collision, deliberate — mirrors the payment_rejected precedent
     * in AmenityReservationPaymentRejectionObserver): every earmark payment
     * validation ALSO fires Collections' generic 'payment_accepted' event titled
     * "Pago confirmado" (PaymentService::validatePayment() does not check
     * earmark_type). The amenity 'payment_confirmed' title below is
     * deliberately DIFFERENT ("Reservación liquidada") so residents — and
     * admins reading in-app titles — can tell the two notifications apart
     * instead of seeing two rows that look identical.
     */
    private function notifyPaymentSettlement(string $eventKey, AmenityReservation $reservation, int $settledRaw, int $outstandingRaw): void
    {
        $context = $this->reservationContext($reservation);
        $context['amount_paid'] = $this->formatMoney($settledRaw);
        $context['amount_outstanding'] = $this->formatMoney($outstandingRaw);

        $isDeposit = $eventKey === 'amenity_reservation.deposit_confirmed';

        $title = $isDeposit ? 'Depósito confirmado' : 'Reservación liquidada';
        $body = $isDeposit
            ? "Tu depósito para {$context['amenity_name']} ({$context['reservation_date']}) fue confirmado. Saldo pendiente: {$context['amount_outstanding']}."
            : "Tu pago para {$context['amenity_name']} ({$context['reservation_date']}) fue confirmado. Tu reservación está completamente liquidada.";

        $this->notifyEvent(
            $eventKey,
            $this->unitResidentUserIds($reservation->unit_id),
            $context,
            $title,
            $body,
            [
                'category' => NotificationCategory::AMENITIES,
                'click_action' => '/my-reservations',
                'amenity_reservation_id' => $reservation->id,
                'unit_id' => $reservation->unit_id,
            ],
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    private function reservationContext(AmenityReservation $reservation): array
    {
        $amenity = $reservation->relationLoaded('amenity')
            ? $reservation->amenity
            : Amenity::find($reservation->amenity_id);

        return [
            'amenity_name' => $amenity?->name ?? 'la amenidad',
            'reservation_date' => $this->formatDate($reservation->reservation_date),
        ];
    }

    private function formatMoney(int $amountRaw): string
    {
        return TenantMoney::format($amountRaw);
    }

    private function formatDate(mixed $date): string
    {
        if (! $date) {
            return '';
        }

        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $carbon->format('d/m/Y');
    }

    /**
     * S8: reverse every CONFIRMED earmarked Payment for this reservation (bank
     * debit + applications + folio) BEFORE the status flips to CANCELLED in
     * cancel() above, via the SAME reversal path Collections already uses for
     * project contributions. Each cancel() runs inside PaymentService's own
     * nested DB::transaction (a Postgres SAVEPOINT under the reservation's
     * transaction) — safe to call from here.
     *
     * lockForUpdate() serializes against a CONCURRENT direct payment action
     * (e.g. /payment/{id}/cancel on the SAME row): whichever side's SELECT ...
     * FOR UPDATE acquires the row first wins and the other side re-checks the
     * WHERE clause against the post-commit row version — Postgres either
     * silently drops the row from THIS result set (the other side already
     * moved it out of CONFIRMED) or lets the other side's own lockForUpdate()
     * block until we commit and then see it as already CANCELLED. Either way
     * the exception (if any) surfaces on the OTHER caller's own request, never
     * inside this cascade.
     */
    private function reverseConfirmedEarmarkPayments(AmenityReservation $reservation, string $reason): void
    {
        Payment::where('earmark_type', AmenityReservationEarmarkHandler::EARMARK_TYPE)
            ->where('earmark_id', $reservation->id)
            ->where('payment_status_id', PaymentStatus::CONFIRMED)
            ->lockForUpdate()
            ->get()
            ->each(fn (Payment $payment) => app(PaymentService::class)->cancel($payment, $reason));
    }

    /**
     * A2: reject every PENDING_VALIDATION earmarked Payment for this
     * reservation (no orphans left un-validatable on a now-dead reservation)
     * — the pending-payment counterpart to reverseConfirmedEarmarkPayments()
     * above, called right after it from cancel()/expire(), AFTER the status
     * has already flipped to CANCELLED (same ordering rationale as that
     * call's comment: the observer's terminal-status guard in
     * extendDeadlineAfterPaymentRejection() must see CANCELLED first so a
     * rejection cascading from HERE never re-arms a deadline on a reservation
     * that is already dead).
     *
     * lockForUpdate() (same discipline as reverseConfirmedEarmarkPayments()
     * above) closes the race against a treasurer concurrently validating or
     * rejecting the SAME payment (/payment/{id}/validate|/reject): the row is
     * locked HERE, inside cancel()/expire()'s own transaction, before
     * PaymentService::reject() ever runs. A concurrent treasurer action
     * blocks on ITS OWN lockForUpdate() read until we commit, then re-reads
     * and finds the payment already CANCELLED — their own request throws and
     * handles it (PaymentController already catches InvalidArgumentException
     * on validate/reject/cancel), never ours. Conversely, if the treasurer's
     * transaction already committed a status change before we get here, our
     * lockForUpdate() query simply excludes that row (no longer matches
     * PENDING_VALIDATION) and we never call reject() on it at all.
     */
    private function rejectPendingEarmarkPayments(AmenityReservation $reservation, string $reason): void
    {
        Payment::where('earmark_type', AmenityReservationEarmarkHandler::EARMARK_TYPE)
            ->where('earmark_id', $reservation->id)
            ->where('payment_status_id', PaymentStatus::PENDING_VALIDATION)
            ->lockForUpdate()
            ->get()
            ->each(fn (Payment $payment) => app(PaymentService::class)->reject($payment, $reason));
    }

    /**
     * A1: called by AmenityReservationPaymentRejectionObserver right after a
     * resident's earmark payment is rejected — re-arms the reservation's own
     * deadline by REJECTION_GRACE_DAYS so a resubmission window survives even
     * if the deadline had already lapsed while that payment sat PENDING_VALIDATION
     * (isExpirable()'s guard above was the only thing holding off the cron
     * during that window). Never SHORTENS an existing later deadline — only
     * ever pushes it forward. A no-op on any non-ACTIVE reservation: this
     * also fires when rejectPendingEarmarkPayments() (above) rejects a
     * payment as part of cancel()/expire() itself, where the status has
     * ALREADY flipped to CANCELLED before that rejection runs — nothing to
     * re-arm on an already-dead reservation.
     */
    public function extendDeadlineAfterPaymentRejection(string $reservationId): void
    {
        DB::transaction(function () use ($reservationId): void {
            $locked = $this->lockReservation($reservationId);

            if (! in_array($locked->reservation_status_id, self::ACTIVE_STATUSES, true)) {
                return;
            }

            $timezone = $this->tenantTimezone();

            if ($locked->reservation_status_id === ReservationStatus::PENDING_PAYMENT) {
                $current = $locked->payment_deadline;
                $extended = now($timezone)->addDays(self::REJECTION_GRACE_DAYS)->utc();

                $locked->payment_deadline = $current !== null && $current->greaterThan($extended)
                    ? $current
                    : $extended;
                $locked->save();

                return;
            }

            if ($locked->reservation_status_id === ReservationStatus::CONFIRMED) {
                $outstandingRaw = (int) $locked->getAttributes()['amount_outstanding'];

                if ($outstandingRaw <= 0) {
                    return;
                }

                $currentDate = $locked->balance_due_date;
                $extendedDate = now($timezone)->addDays(self::REJECTION_GRACE_DAYS)->toDateString();

                $locked->balance_due_date = $currentDate !== null && $currentDate->toDateString() > $extendedDate
                    ? $currentDate
                    : $extendedDate;
                $locked->save();
            }
        });
    }

    /**
     * S8: recompute this reservation's amount_outstanding from scratch — the
     * SUM of applied_amount across ALL of its non-cancelled earmark charges —
     * and advance the payment-driven FSM edge. A full recompute-from-sum
     * (NOT a signed delta accumulation): the reservation's balance is always
     * relative to its OWN frozen cost_amount snapshot (S6), so re-deriving it
     * from the ledger is both simpler and self-healing against any missed/
     * duplicate observer event.
     *
     *   outstanding == 0 (fully settled) & {PENDING_PAYMENT, CONFIRMED} → CONFIRMED, balance_due_date cleared.
     *   outstanding > 0 & PENDING_PAYMENT & settled >= deposit_amount (deposit
     *     satisfied — or no deposit required at all, snapshot 0/null then,
     *     which validateSubmission's own guard already guarantees for the
     *     first payment) → CONFIRMED + a fresh balance_due_date.
     *   outstanding > 0 & CONFIRMED & settled == 0 (its only earmark payment
     *     was fully reversed) → revert to PENDING_PAYMENT.
     * Terminal reservations (anything outside ACTIVE_STATUSES — rejected,
     * cancelled, completed, no_show) are NEVER touched: a late reversal
     * against a historical reservation must not resurrect its FSM.
     *
     * Called by AmenityReservationChargeApplicationObserver (PaymentApplication
     * created/updated/deleted on a charge stamped amenity_reservation_id). MUST
     * run inside the payment's own transaction — mirrors
     * Modules\Projects\Services\ContributionService::syncFromChargeApplication.
     */
    public function syncFromChargeApplication(string $reservationId): void
    {
        $locked = AmenityReservation::where('id', $reservationId)->lockForUpdate()->first();

        if (! $locked || ! in_array($locked->reservation_status_id, self::ACTIVE_STATUSES, true)) {
            return;
        }

        // S10: captured BEFORE any mutation below — used only to decide whether
        // this recompute represents a genuine payment-settlement transition (so a
        // no-op recompute never re-fires deposit_confirmed/payment_confirmed).
        $originalOutstandingRaw = (int) $locked->getAttributes()['amount_outstanding'];

        $costRaw = (int) ($locked->getAttributes()['cost_amount'] ?? 0);
        $settledRaw = (int) PaymentApplication::query()
            ->whereHas('charge', fn ($query) => $query
                ->where('amenity_reservation_id', $reservationId)
                ->where('is_cancelled', false))
            ->sum('applied_amount');

        $outstandingRaw = max(0, $costRaw - $settledRaw);
        $locked->amount_outstanding = $outstandingRaw;

        // S10: which notification (if any) this recompute should fire, decided
        // alongside the FSM transition below and dispatched AFTER save().
        $eventToFire = null;

        if ($outstandingRaw === 0) {
            $locked->reservation_status_id = ReservationStatus::CONFIRMED;
            $locked->balance_due_date = null;

            if ($originalOutstandingRaw > 0) {
                $eventToFire = 'amenity_reservation.payment_confirmed';
            }
        } elseif ($locked->reservation_status_id === ReservationStatus::PENDING_PAYMENT) {
            $depositRaw = (int) ($locked->getAttributes()['deposit_amount'] ?? 0);

            // A3: the deposit-confirm branch applies ONLY when a deposit is
            // actually configured (depositRaw > 0). With NO deposit snapshot,
            // this reservation can confirm ONLY through the outstanding===0
            // branch above — never on a token partial payment (previously,
            // $settledRaw >= 0 was trivially true for any partial, confirming
            // on the very first peso paid). Already-CONFIRMED rows are never
            // touched by this change — it only gates FUTURE recomputes.
            if ($depositRaw > 0 && $settledRaw >= $depositRaw) {
                $amenity = Amenity::find($locked->amenity_id);
                $days = $amenity?->deposit_due_days ?? self::DEFAULT_PAYMENT_DEADLINE_DAYS;

                $locked->reservation_status_id = ReservationStatus::CONFIRMED;
                $locked->balance_due_date = now($this->tenantTimezone())->addDays($days)->toDateString();

                $eventToFire = 'amenity_reservation.deposit_confirmed';
            }
        } elseif ($locked->reservation_status_id === ReservationStatus::CONFIRMED && $settledRaw === 0) {
            $locked->reservation_status_id = ReservationStatus::PENDING_PAYMENT;
            $locked->balance_due_date = null;
        } elseif (
            $locked->reservation_status_id === ReservationStatus::CONFIRMED
            && $locked->getAttributes()['balance_due_date'] === null
        ) {
            // FIX (reviewer-confirmed LOW): a PARTIAL reversal can leave the
            // reservation CONFIRMED with outstanding > 0 but no due date — e.g.
            // the deposit already confirmed the reservation (clearing
            // balance_due_date via the branch above), then the SETTLING
            // payment gets reversed directly (/payment/{id}/cancel) instead of
            // through the reservation's own cancel(). Re-establish a fresh
            // balance_due_date so the balance doesn't silently go undated.
            $amenity = Amenity::find($locked->amenity_id);
            $days = $amenity?->deposit_due_days ?? self::DEFAULT_PAYMENT_DEADLINE_DAYS;

            $locked->balance_due_date = now($this->tenantTimezone())->addDays($days)->toDateString();
        }

        $locked->save();

        // S10: dispatched AFTER save() — notifyEvent() itself defers the actual
        // channel dispatch via DB::afterCommit, so firing here (inside the
        // payment's own transaction, mirroring the observer's calling contract)
        // is safe and never rolls back the payment on a notification failure.
        if ($eventToFire !== null) {
            $this->notifyPaymentSettlement($eventToFire, $locked, $settledRaw, $outstandingRaw);
        }
    }

    /**
     * Re-read + row-lock the reservation UNDER the transaction, tenant-scoped
     * via the model's BelongsToTenant global scope (never withoutGlobalScope).
     */
    private function lockReservation(string $id): AmenityReservation
    {
        return AmenityReservation::where('id', $id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Reuses AmenityAvailabilityService — never re-implements the structural
     * rules (out_of_service / available_weekdays / blackout) or the past-date
     * guard it already owns.
     */
    private function assertAvailable(Amenity $amenity, string $date): void
    {
        try {
            $availability = $this->availabilityService->forDate($amenity, $date);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        // forDate() delegates to forRange() — a single-day range — so the
        // structural verdict for THIS date is always days[0].
        $day = $availability['days'][0];

        if (! $day['open']) {
            abort(422, match ($day['reason']) {
                'out_of_service' => 'La amenidad está fuera de servicio.',
                'closed_weekday' => 'La amenidad no opera ese día de la semana.',
                'blackout' => 'La amenidad no está disponible en esa fecha (bloqueo programado).',
                default => 'La amenidad no está disponible en la fecha seleccionada.',
            });
        }
    }

    /**
     * A resident/renter may only reserve for a unit they actively belong to
     * (unit_user pivot, no end_date). A caller holding update.amenity_reservation
     * (board/admin — see RolePermissionTableSeeder's writeNoDelete($amenities)
     * grant) manages the whole cluster's amenities and may reserve for any unit.
     * Mirrors Access\Http\Controllers\VisitorController::authorizeUnitMembership.
     */
    private function authorizeUnitMembership(string $unitId): void
    {
        $user = $this->currentUser();

        if ($user && ($user->tokenCan('update.amenity_reservation') || $user->tokenCan('*'))) {
            return;
        }

        $isMember = $user !== null && UnitUser::where('unit_id', $unitId)
            ->where('user_id', $user->id)
            ->whereNull('end_date')
            ->exists();

        if (! $isMember) {
            throw new AuthorizationException('Solo puedes reservar para tu propia unidad.');
        }
    }

    /**
     * The reserving UNIT must fall within the amenity's target (spec
     * amenity-visibility: "Server-Side Enforcement"). Deliberately NO bypass
     * for update.amenity_reservation holders here — unlike
     * authorizeUnitMembership()'s bypass (which lets board/admin reserve for
     * ANY unit they manage), reserving for a unit outside the amenity's own
     * target is a booking mistake regardless of who makes it (see
     * design.md Architecture Decision #3).
     */
    private function authorizeAmenityTarget(Amenity $amenity, string $unitId): void
    {
        if (! $this->visibilityService->unitInAmenityTarget($amenity, $unitId)) {
            abort(422, 'Esta amenidad no está disponible para la unidad seleccionada.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return \Illuminate\Support\Carbon|null the acceptance timestamp to stamp, or null when not required
     */
    private function assertRulesAcceptedIfRequired(Amenity $amenity, array $data): ?Carbon
    {
        if (empty($amenity->rules_text)) {
            return null;
        }

        if (empty($data['rules_accepted'])) {
            abort(422, 'Debes aceptar el reglamento de la amenidad para reservar.');
        }

        return now();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveAndCheckSlotCapacity(Amenity $amenity, array $data): AmenitySlot
    {
        if (empty($data['slot_id'])) {
            abort(422, 'Debes elegir un horario para esta amenidad.');
        }

        $slot = AmenitySlot::where('amenity_id', $amenity->id)->find($data['slot_id']);

        if ($slot === null) {
            abort(422, 'El horario seleccionado no existe para esta amenidad.');
        }

        $weekday = (int) Carbon::parse((string) $data['reservation_date'])->dayOfWeek;

        if ((int) $slot->weekday !== $weekday) {
            abort(422, 'El horario seleccionado no aplica para ese día de la semana.');
        }

        // The amenity row is already locked (create()'s lockForUpdate) — every
        // concurrent create for THIS amenity serializes here, so count-then-
        // insert is atomic: no two transactions can both read "capacity free"
        // and both insert past the limit.
        $capacity = $slot->capacity_override ?? $amenity->capacity;

        $occupied = AmenityReservation::where('amenity_id', $amenity->id)
            ->where('slot_id', $slot->id)
            ->where('reservation_date', $data['reservation_date'])
            ->where('holds_slot', true)
            ->count();

        if ($occupied >= $capacity) {
            abort(409, 'Ese horario ya alcanzó su capacidad máxima.');
        }

        return $slot;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function rejectSlotForByDayAmenity(array $data): ?AmenitySlot
    {
        if (! empty($data['slot_id'])) {
            abort(422, 'Esta amenidad no opera por horarios.');
        }

        return null;
    }

    /**
     * @return array{0: \Illuminate\Support\Carbon|null, 1: \Illuminate\Support\Carbon|null}
     */
    private function materializeWindow(string $date, ?AmenitySlot $slot, string $timezone): array
    {
        if ($slot === null) {
            return [null, null];
        }

        return [
            Carbon::parse($date.' '.$slot->start_time, $timezone)->utc(),
            Carbon::parse($date.' '.$slot->end_time, $timezone)->utc(),
        ];
    }

    /**
     * FSM entry point (spec R1): approval gates payment; otherwise a costed
     * amenity awaits its earmarked payment (S8); a free, no-approval amenity
     * is born confirmed.
     *
     * @return array{0: string, 1: \Illuminate\Support\Carbon|null}
     */
    private function resolveInitialStatus(Amenity $amenity, string $timezone): array
    {
        if ($amenity->requires_approval) {
            return [ReservationStatus::PENDING_APPROVAL, null];
        }

        if ($amenity->has_cost) {
            $days = $amenity->deposit_due_days ?? self::DEFAULT_PAYMENT_DEADLINE_DAYS;

            return [ReservationStatus::PENDING_PAYMENT, now($timezone)->addDays($days)->utc()];
        }

        return [ReservationStatus::CONFIRMED, null];
    }

    private function currentUser(): ?User
    {
        /** @var User|null $user */
        $user = auth()->guard('sanctum')->user();

        return $user;
    }

    private function tenantTimezone(): string
    {
        return tenant('timezone') ?: config('app.timezone');
    }
}
