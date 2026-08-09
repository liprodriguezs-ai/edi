<?php

namespace Modules\AmenityReservations\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\AmenityReservations\Models\ReservationStatus;
use Modules\AmenityReservations\Services\ReservationService;
use Modules\AmenityReservations\Support\AmenityReservationsSettings;
use Modules\Collections\Models\PaymentStatus;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;

/**
 * amenities:cancel-expired-reservations — daily cron (S11, spec R9).
 *
 * For each tenant with the AmenityReservations module flag ON:
 *   - EXPIRE: auto-cancels reservations whose payment deadline lapsed WITHOUT
 *     full settlement — PENDING_PAYMENT past payment_deadline, or CONFIRMED
 *     with a still-owing balance past balance_due_date — reversing any
 *     CONFIRMED earmark payment via ReservationService::expire() (which
 *     itself re-verifies the deadline under lock, so this command's own query
 *     is only a candidate PREFILTER, never the final authority).
 *   - REMIND: notifies residents whose deadline falls within the reminder
 *     window via ReservationService::notifyPaymentReminder(), naturally
 *     idempotent (a deadline crosses the window on exactly one calendar day,
 *     so a same-day re-run never double-sends).
 *
 * Tenants with the flag OFF are skipped entirely — mirrors
 * EnsureAmenityReservationsEnabled's per-tenant kill-switch.
 *
 * All deadline comparisons run in the TENANT's own timezone (spec R9) — never
 * a bare now()/Carbon::today() — mirroring RefreshChargeStatusesCommand's
 * per-tenant iteration (Tenant::all() + $tenant->run()).
 */
class CancelExpiredReservationsCommand extends Command
{
    /**
     * A reminder fires exactly this many days before the deadline (tenant tz,
     * calendar-day granularity) — a deadline crosses this offset on exactly
     * one calendar day, so the daily cron is naturally idempotent without a
     * separate "reminder sent" marker column.
     */
    private const REMINDER_DAYS_BEFORE = 1;

    protected $signature = 'amenities:cancel-expired-reservations';

    protected $description = 'Daily: auto-cancel amenity reservations past their payment deadline and send payment reminders, per tenant.';

    public function handle(ReservationService $reservationService): int
    {
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $label = $tenant->name ?? $tenant->id;

            // ISOLATION (safety net): Stancl's TenantRun::run() has no
            // try/finally — an uncaught exception inside the closure below
            // (e.g. an unexpected race on a single reservation, beyond what
            // the per-reservation try/catch in expireOverdueReservations()
            // already isolates) would both abort every REMAINING tenant in
            // this loop AND leave tenancy() pointed at THIS tenant, since
            // run()'s own tenancy-revert line would never execute. Catching
            // here and force-reverting tenancy (mirroring exactly what
            // run() would have done on success) guarantees one tenant's
            // failure can never skip the rest or leak its scope forward.
            $originalTenant = tenant();

            try {
                $tenant->run(function () use ($reservationService, $label): void {
                    if (Setting::getValue(AmenityReservationsSettings::ENABLED) !== true) {
                        $this->info("[{$label}] AmenityReservations disabled — skipped.");

                        return;
                    }

                    $timezone = $this->tenantTimezone();

                    $expiredCount = $this->expireOverdueReservations($reservationService, $timezone);
                    $remindedCount = $this->sendPaymentReminders($reservationService, $timezone);

                    $this->info("[{$label}] {$expiredCount} reservation(s) expired, {$remindedCount} reminder(s) sent.");
                });
            } catch (\Throwable $e) {
                Log::error("[amenities:cancel-expired-reservations] Tenant {$label} failed — skipped, sweep continues.", [
                    'tenant_id' => $tenant->id,
                    'exception' => $e,
                ]);

                if ($originalTenant) {
                    tenancy()->initialize($originalTenant);
                } elseif (tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        }

        $this->info('amenities:cancel-expired-reservations completed.');

        return self::SUCCESS;
    }

    /**
     * Candidate prefilter (tenant-scoped Eloquent, never withoutGlobalScope):
     * PENDING_PAYMENT past payment_deadline (an absolute instant — no tz
     * ambiguity once expressed in UTC, which is how it is stored/queried), or
     * CONFIRMED with amount_outstanding > 0 past balance_due_date (a plain
     * date — compared as a tenant-tz date string, strictly before today so a
     * due-today reservation is NOT prefiltered out). ReservationService::expire()
     * re-verifies both under lock.
     *
     * A1: excludes any reservation with a PENDING_VALIDATION earmark payment
     * (a resident's payment awaiting treasurer review) — a prefilter mirror
     * of isExpirable()'s own early-return guard, which remains the final
     * authority.
     */
    private function expireOverdueReservations(ReservationService $reservationService, string $timezone): int
    {
        $now = now($timezone);
        $today = $now->toDateString();
        // The query builder binds a Carbon by formatting it in ITS OWN
        // timezone (Laravel does not normalize DateTimeInterface bindings to
        // UTC) — payment_deadline is stored in UTC, so the comparison value
        // must be explicitly converted to UTC, never $now's tenant-tz wall
        // clock, or the bound literal would be off by the tenant's UTC offset.
        $nowUtc = $now->copy()->utc();

        $candidates = AmenityReservation::query()
            ->where(function ($query) use ($nowUtc, $today): void {
                $query->where(function ($query) use ($nowUtc): void {
                    $query->where('reservation_status_id', ReservationStatus::PENDING_PAYMENT)
                        ->whereNotNull('payment_deadline')
                        ->where('payment_deadline', '<', $nowUtc);
                })
                    ->orWhere(function ($query) use ($today): void {
                        $query->where('reservation_status_id', ReservationStatus::CONFIRMED)
                            ->where('amount_outstanding', '>', 0)
                            ->whereNotNull('balance_due_date')
                            ->where('balance_due_date', '<', $today);
                    });
            })
            // A1: this AND-level exclusion must sit OUTSIDE the OR pair above
            // (grouped in the same outer where()) — appending it as a bare
            // top-level clause would bind tighter than the OR via SQL
            // precedence, turning "(A OR B) AND NOT EXISTS" into the wrong
            // "A OR (B AND NOT EXISTS)".
            ->whereDoesntHave('earmark_payments', fn ($query) => $query->where('payment_status_id', PaymentStatus::PENDING_VALIDATION))
            ->get();

        // ISOLATION (safety net): a single raced/unexpected failure on ONE
        // candidate must never abort the transaction-per-reservation sweep
        // for every OTHER candidate in this tenant. expire() opens its own
        // DB::transaction(), so a caught rollback here is already clean —
        // the next iteration starts fresh with no partial state left behind.
        foreach ($candidates as $reservation) {
            try {
                $reservationService->expire($reservation);
            } catch (\Throwable $e) {
                Log::error("[amenities:cancel-expired-reservations] Failed to expire reservation {$reservation->id} — skipped, sweep continues.", [
                    'reservation_id' => $reservation->id,
                    'exception' => $e,
                ]);
            }
        }

        return $candidates->count();
    }

    /**
     * Reminds residents whose reservation is still owing and whose own
     * deadline (payment_deadline for PENDING_PAYMENT, balance_due_date for a
     * CONFIRMED partial) falls exactly REMINDER_DAYS_BEFORE days from today,
     * in the tenant's own calendar.
     */
    private function sendPaymentReminders(ReservationService $reservationService, string $timezone): int
    {
        $reminderDate = now($timezone)->addDays(self::REMINDER_DAYS_BEFORE)->toDateString();

        $sent = 0;

        $pendingPayment = AmenityReservation::query()
            ->with('amenity')
            ->where('reservation_status_id', ReservationStatus::PENDING_PAYMENT)
            ->whereNotNull('payment_deadline')
            ->get();

        $sent += $this->remindMatching($pendingPayment, $reservationService, $reminderDate, $timezone, deadlineIsDatetime: true);

        $confirmedWithBalance = AmenityReservation::query()
            ->with('amenity')
            ->where('reservation_status_id', ReservationStatus::CONFIRMED)
            ->where('amount_outstanding', '>', 0)
            ->whereNotNull('balance_due_date')
            ->get();

        $sent += $this->remindMatching($confirmedWithBalance, $reservationService, $reminderDate, $timezone, deadlineIsDatetime: false);

        return $sent;
    }

    /**
     * @param  Collection<int, AmenityReservation>  $reservations
     */
    private function remindMatching(
        Collection $reservations,
        ReservationService $reservationService,
        string $reminderDate,
        string $timezone,
        bool $deadlineIsDatetime,
    ): int {
        $sent = 0;

        foreach ($reservations as $reservation) {
            $deadline = $deadlineIsDatetime ? $reservation->payment_deadline : $reservation->balance_due_date;

            if ($deadline === null) {
                continue;
            }

            $deadlineDate = $deadlineIsDatetime
                ? Carbon::parse($deadline)->setTimezone($timezone)->toDateString()
                : $deadline->toDateString();

            if ($deadlineDate === $reminderDate) {
                $reservationService->notifyPaymentReminder($reservation);
                $sent++;
            }
        }

        return $sent;
    }

    private function tenantTimezone(): string
    {
        return tenant('timezone') ?: config('app.timezone');
    }
}
