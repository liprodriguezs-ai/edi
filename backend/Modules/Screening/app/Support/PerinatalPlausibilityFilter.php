<?php

declare(strict_types=1);

namespace Modules\Screening\Support;

/**
 * Plausibility filter for perinatal values read from birth_record (design
 * D12). An out-of-range value is treated as NULL ("se ignora") -- it MUST
 * NOT be coerced to zero or "no" anywhere downstream (clinic D-14). Ranges
 * mirror birth_record's own FormRequest validation
 * (BirthRecordCreateRequest).
 */
final class PerinatalPlausibilityFilter
{
    private const GESTATIONAL_AGE_MIN_WEEKS = 20;

    private const GESTATIONAL_AGE_MAX_WEEKS = 44;

    private const BIRTH_WEIGHT_MIN_GRAMS = 400;

    private const BIRTH_WEIGHT_MAX_GRAMS = 7000;

    private const MOTHER_AGE_MIN_YEARS = 10;

    private const MOTHER_AGE_MAX_YEARS = 60;

    public static function gestationalAgeWeeks(?int $value): ?int
    {
        return self::inRange($value, self::GESTATIONAL_AGE_MIN_WEEKS, self::GESTATIONAL_AGE_MAX_WEEKS);
    }

    public static function birthWeightGrams(?int $value): ?int
    {
        return self::inRange($value, self::BIRTH_WEIGHT_MIN_GRAMS, self::BIRTH_WEIGHT_MAX_GRAMS);
    }

    public static function motherAgeAtBirthYears(?int $value): ?int
    {
        return self::inRange($value, self::MOTHER_AGE_MIN_YEARS, self::MOTHER_AGE_MAX_YEARS);
    }

    private static function inRange(?int $value, int $min, int $max): ?int
    {
        if ($value === null) {
            return null;
        }

        return ($value >= $min && $value <= $max) ? $value : null;
    }
}
