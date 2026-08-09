<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * CalendarDays — the ONE product-wide "days left" definition (trial-countdown-utility).
 *
 * "Days left" means whole CALENDAR days in the app timezone: the difference
 * between today's start-of-day and the end date's start-of-day, clamped at 0.
 * This is deliberately NOT elapsed-time rounding (e.g. rounded milliseconds) —
 * that was the frontend `daysUntil` approach this helper replaces, which could
 * disagree with the backend's own `presentTrials.days_left` for the same
 * instant.
 */
final class CalendarDays
{
    /**
     * Whole calendar days from today (start of day) until $end (start of day),
     * never negative. `max(0, ...)` is load-bearing: Carbon 3's `diffInDays` is
     * SIGNED, so a past `$end` would otherwise return a negative count (the
     * same bug class already fixed once in this codebase — see
     * CollectionsReportService's days-past-due computation).
     */
    public static function until(?CarbonInterface $end): ?int
    {
        if ($end === null) {
            return null;
        }

        return max(0, now()->startOfDay()->diffInDays($end->copy()->startOfDay()));
    }
}
