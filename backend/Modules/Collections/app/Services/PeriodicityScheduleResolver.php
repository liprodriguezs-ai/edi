<?php

namespace Modules\Collections\Services;

use Carbon\Carbon;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Support\CollectionSettings;

/**
 * Pure date-logic resolver for charge-generation scheduling — ANCHORED BILLING PERIODS.
 *
 * Given a periodicity ID and the date a period STARTS, periodFor() returns the PeriodRun
 * describing that period (start, end/due date, generation/issued date). The command chains
 * calls: each call's periodStart is the prior call's due_date + 1 day (or a policy's
 * valid_from for a virgin policy), so periods generate gaplessly regardless of day-of-month
 * drift, cron downtime, or how far behind a policy has fallen.
 *
 * Design: this class is PURE (no DB calls, no tenant scope) so it can be unit-tested
 * without booting a tenant. The command orchestrates; this class only answers "given this
 * start, what does the period look like".
 *
 * Period-start-to-end math by periodicity (canonical contract): ONE formula for all six
 * automatic cadences — end = (start + interval) − 1 day. The interval map is the only thing
 * that varies (+1 month, +2 months, +1 year, +7 days, +15 days, +2 days), and the month/year
 * intervals use Carbon's *NoOverflow methods so day 31 never rolls forward. Eventual has no
 * automatic period (manual; admin sets dates) — periodFor() returns null.
 *
 * NO CADENCE MAY START BEFORE ITS ANCHOR. The pre-anchored calendar-grid shapes (BIWEEKLY
 * calendar halves; EVERY_TWO_DAYS epoch-aligned windows) were removed on 2026-07-25: both
 * could open a period BEFORE the policy's valid_from (a 1st-of-July valid_from landing in a
 * window that started on 30 June), which contradicts the product rule "the start date is the
 * start date". Chained periods are unaffected — see periodFor() PHPDoc.
 */
class PeriodicityScheduleResolver
{
    /**
     * ANCHORED BILLING PERIODS — pure date math for the period that STARTS at $periodStart
     * (not the period containing an arbitrary anchor). This is the engine behind chained
     * generation: the cron seeds the next call with the prior batch's due_date + 1 day, so
     * periods chain gaplessly regardless of day-of-month drift.
     *
     * Interval map: MONTHLY = +1 month, BIMONTHLY = +2 months, YEARLY = +1 year (all via the
     * *NoOverflow Carbon methods so day 31 never rolls into the next-next month), WEEKLY =
     * +7 days, BIWEEKLY = +15 days, EVERY_TWO_DAYS = +2 days. End = (start + interval) − 1
     * day, so by construction the NEXT period's start equals THIS period's end + 1 day
     * (start(n+1) = end(n) + 1) — gapless and overlap-free, for every cadence.
     *
     * CLAMP-THEN-DRIFT: a high day-of-month anchor (e.g. 31) clamps down when the target
     * month is shorter, and the chain then continues from the CLAMPED day — the original
     * anchor is not restored later. For YEARLY this means a policy anchored on Feb 29 (leap)
     * loses that exact anchor after its first non-leap year: it becomes Feb 28 permanently,
     * including in future leap years. This is an accepted architecture tradeoff (Stripe-style
     * anniversary clamp), not a bug.
     *
     * BIWEEKLY = 15 DAYS, NOT CALENDAR HALVES: "quincenal" literally means a 15-day span, and
     * an anchored period cannot reproduce the Mexican semi-monthly shape (1→15 / 16→EOM)
     * without starting before the anchor. The accepted cost is calendar drift — 24.33 periods
     * per year instead of exactly 24 — which is the same tradeoff WEEKLY already carries
     * (52 or 53 periods depending on the year). Zero live BIWEEKLY policies today.
     *
     * cycle is informational only (the idempotency key is period_start): null for the
     * month-or-longer cadences whose month already identifies the period, the ISO week for
     * WEEKLY, and the start day-of-month for the sub-monthly cadences, which need it to tell
     * two periods of the same month apart.
     *
     * @return PeriodRun|null Null for EVENTUAL/unknown (no automatic period).
     */
    public function periodFor(
        string $periodicityId,
        Carbon $periodStart,
        int $generationDay = CollectionSettings::DEFAULT_GENERATION_DAY,
    ): ?PeriodRun {
        $generationDay = max(1, min(28, $generationDay));
        $start = $periodStart->copy()->startOfDay();

        [$end, $cycle] = match ($periodicityId) {
            Periodicity::MONTHLY => [$start->copy()->addMonthsNoOverflow(1), null],
            Periodicity::BIMONTHLY => [$start->copy()->addMonthsNoOverflow(2), null],
            Periodicity::YEARLY => [$start->copy()->addYearsNoOverflow(1), null],
            Periodicity::WEEKLY => [$start->copy()->addDays(7), $start->isoWeek],
            Periodicity::BIWEEKLY => [$start->copy()->addDays(15), $start->day],
            Periodicity::EVERY_TWO_DAYS => [$start->copy()->addDays(2), $start->day],
            default => [null, null],
        };

        if ($end === null) {
            return null;
        }

        $end->subDay();

        return new PeriodRun(
            year: $start->year,
            month: $start->month,
            cycle: $cycle,
            periodLabel: $this->rangeLabel($start, $end),
            issuedAt: $this->generationDate($start, $end, $generationDay),
            dueDate: $end,
            periodStart: $start,
        );
    }

    /**
     * Human-readable date range for an anchored period, e.g. "15 jul – 14 ago 2026".
     * En dash (–), not a hyphen. Uses the app locale (es) via translatedFormat.
     */
    private function rangeLabel(Carbon $start, Carbon $end): string
    {
        return $start->translatedFormat('j M').' – '.$end->translatedFormat('j M Y');
    }

    // =========================================================================
    // Period-bounds helpers (pure date math, no DB/tenant)
    // =========================================================================

    /**
     * generationDate = start + (generationDay − 1) days, clamped so it never
     * exceeds the period end. This is the NACE date the charge is generated.
     */
    private function generationDate(Carbon $start, Carbon $end, int $generationDay): Carbon
    {
        $generated = $start->copy()->addDays($generationDay - 1);

        return $generated->greaterThan($end) ? $end->copy() : $generated;
    }
}
