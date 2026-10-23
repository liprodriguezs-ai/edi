<?php

declare(strict_types=1);

namespace Modules\Screening\Services\Legacy;

/**
 * Converts the dirty, mixed-unit legacy somatometry (varchar columns on
 * tran_edi_beneficiario) into the new app's canonical integer units. Height and
 * head circumference are centimetres in the legacy dump; weight and birth weight
 * mix kilograms and grams (README warning), so they are normalized by magnitude.
 * Implausible values are dropped to null rather than imported as garbage.
 */
final class LegacySomatometry
{
    /** At/above this the value is already grams; below it, it is kilograms. */
    private const KG_GRAMS_THRESHOLD = 200;

    private const HEIGHT_MM_MIN = 200;

    private const HEIGHT_MM_MAX = 1400;

    private const WEIGHT_G_MIN = 1000;

    private const WEIGHT_G_MAX = 40000;

    private const HEAD_MM_MIN = 250;

    private const HEAD_MM_MAX = 650;

    private const BIRTH_G_MIN = 500;

    private const BIRTH_G_MAX = 6500;

    public function heightMm(string|int|float|null $talla): ?int
    {
        $cm = $this->number($talla);

        return $cm === null ? null : $this->clamp((int) round($cm * 10), self::HEIGHT_MM_MIN, self::HEIGHT_MM_MAX);
    }

    public function headCircumferenceMm(string|int|float|null $pc): ?int
    {
        $cm = $this->number($pc);

        return $cm === null ? null : $this->clamp((int) round($cm * 10), self::HEAD_MM_MIN, self::HEAD_MM_MAX);
    }

    public function weightGrams(string|int|float|null $peso): ?int
    {
        return $this->grams($peso, self::WEIGHT_G_MIN, self::WEIGHT_G_MAX);
    }

    public function birthWeightGrams(string|int|float|null $pesoNacer): ?int
    {
        return $this->grams($pesoNacer, self::BIRTH_G_MIN, self::BIRTH_G_MAX);
    }

    private function grams(string|int|float|null $value, int $min, int $max): ?int
    {
        $n = $this->number($value);
        if ($n === null || $n <= 0) {
            return null;
        }
        $grams = (int) round($n >= self::KG_GRAMS_THRESHOLD ? $n : $n * 1000);

        return $this->clamp($grams, $min, $max);
    }

    private function number(string|int|float|null $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        if ($s === '' || ! is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }

    private function clamp(int $value, int $min, int $max): ?int
    {
        return ($value < $min || $value > $max) ? null : $value;
    }
}
