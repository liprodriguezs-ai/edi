<?php

namespace Modules\Collections\Services;

use Carbon\Carbon;

/**
 * Value object describing a single charge-generation run for a given periodicity.
 *
 * Returned by PeriodicityScheduleResolver::runsForToday(). The command uses these
 * to build the ChargeBatch without any date math of its own.
 *
 * @see PeriodicityScheduleResolver
 */
final readonly class PeriodRun
{
    public function __construct(
        /** Calendar year of this batch (e.g. 2026). */
        public int $year,
        /** Calendar month of this batch (1-12). Always set for all periodicity types. */
        public ?int $month,
        /**
         * Sub-period discriminator within the month:
         * - NULL for MONTHLY / BIMONTHLY / YEARLY (one run per month at most)
         * - ISO week number for WEEKLY
         * - 1 (day-15 run) or 2 (end-of-month run) for BIWEEKLY
         */
        public ?int $cycle,
        /**
         * Human-readable title fragment for the batch title.
         * E.g. "junio 2026", "semana 24 2026", "1ra quincena junio 2026".
         */
        public string $periodLabel,
        /** Date the batch is issued (today). */
        public Carbon $issuedAt,
        /** Due date for the charges in this batch. */
        public Carbon $dueDate,
        /**
         * The anchored period's start date. Written to charge_batch.period_start (the
         * idempotency key under anchored billing periods). NULL is never passed by
         * periodFor(); the legacy runsForToday()/firstPeriodFor() paths leave it unset
         * only until they are deleted (Phase B6).
         */
        public ?Carbon $periodStart = null,
    ) {}
}
