<?php

namespace Modules\Screening\Services\Scoring;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Chronological and corrected age in days (design D6 / REQ-SCO-003).
 * `calendar` = exact day difference; `thirty_day_month` = legacy arithmetic
 * (a month is 30 days, a year is 360). Corrected age applies to every
 * child under 2 years born before 37 weeks, with no minimum age (Manual EDI
 * 2021 p.9 and p.12; DGIS instructivo p.3: "Prueba 1 (un mes de edad
 * -corregida-)"). The legacy system also required more than 59 chronological
 * days; no official source has that gate, so it was removed (2026-10-21).
 * A corrected age under 30 days is resolved by AgeGroupResolver as "not
 * applicable yet".
 */
final class AgeResolver
{
    public const MODE_CALENDAR = 'calendar';

    public const MODE_THIRTY_DAY_MONTH = 'thirty_day_month';

    public const PREMATURITY_GESTATIONAL_WEEKS_GATE = 37;

    private const THIRTY_DAY_MONTH_TWO_YEARS_GATE_DAYS = 720;

    /**
     * @return array{chronological_age_days: int, corrected_age_days: ?int}
     */
    public function resolveAges(
        DateTimeImmutable $birthDate,
        DateTimeImmutable $testDate,
        string $ageCalculationMode,
        ?int $gestationalAgeWeeks,
    ): array {
        $chronologicalAgeDays = match ($ageCalculationMode) {
            self::MODE_CALENDAR => $this->calendarDays($birthDate, $testDate),
            self::MODE_THIRTY_DAY_MONTH => $this->thirtyDayMonthDays($birthDate, $testDate),
            default => throw new InvalidArgumentException("Unknown age_calculation_mode '{$ageCalculationMode}'."),
        };

        $correctedAgeDays = $this->resolveCorrectedAgeDays(
            $chronologicalAgeDays,
            $gestationalAgeWeeks,
            $ageCalculationMode,
            $birthDate,
            $testDate,
        );

        return [
            'chronological_age_days' => $chronologicalAgeDays,
            'corrected_age_days' => $correctedAgeDays,
        ];
    }

    /**
     * DateInterval::$days is ALWAYS non-negative -- the direction lives in
     * ->invert. Reading it raw turned a visit dated before birth into a large
     * POSITIVE age (a five-year-old backwards reads as 2065 days), which then
     * selected the wrong age group and scored the child against the wrong item
     * bank while looking perfectly ordinary. Carry the sign so an impossible age
     * stays impossible and fails the range check downstream.
     */
    private function calendarDays(DateTimeImmutable $birthDate, DateTimeImmutable $testDate): int
    {
        $elapsed = $birthDate->diff($testDate);

        return (int) $elapsed->days * ($elapsed->invert === 1 ? -1 : 1);
    }

    /**
     * Calendar decomposition into (years, months, days) with a day-borrow
     * of 30, then `y*360 + m*30 + d` (open decision Q1).
     */
    private function thirtyDayMonthDays(DateTimeImmutable $birthDate, DateTimeImmutable $testDate): int
    {
        $years = (int) $testDate->format('Y') - (int) $birthDate->format('Y');
        $months = (int) $testDate->format('n') - (int) $birthDate->format('n');
        $days = (int) $testDate->format('j') - (int) $birthDate->format('j');

        if ($days < 0) {
            $days += 30;
            $months--;
        }

        if ($months < 0) {
            $months += 12;
            $years--;
        }

        return ($years * 360) + ($months * 30) + $days;
    }

    private function resolveCorrectedAgeDays(
        int $chronologicalAgeDays,
        ?int $gestationalAgeWeeks,
        string $ageCalculationMode,
        DateTimeImmutable $birthDate,
        DateTimeImmutable $testDate,
    ): ?int {
        if ($gestationalAgeWeeks === null) {
            return null;
        }

        if ($gestationalAgeWeeks >= self::PREMATURITY_GESTATIONAL_WEEKS_GATE) {
            return null;
        }

        if (! $this->isUnderTwoYears($chronologicalAgeDays, $ageCalculationMode, $birthDate, $testDate)) {
            return null;
        }

        return $chronologicalAgeDays - ((40 - $gestationalAgeWeeks) * 7);
    }

    private function isUnderTwoYears(
        int $chronologicalAgeDays,
        string $ageCalculationMode,
        DateTimeImmutable $birthDate,
        DateTimeImmutable $testDate,
    ): bool {
        if ($ageCalculationMode === self::MODE_THIRTY_DAY_MONTH) {
            return $chronologicalAgeDays < self::THIRTY_DAY_MONTH_TWO_YEARS_GATE_DAYS;
        }

        return $testDate < $birthDate->modify('+24 months');
    }
}
