<?php

namespace Modules\Nutrition\Services;

use DateTimeImmutable;
use Modules\ChildRecord\Models\Sex;
use Modules\Nutrition\Models\HeightForAgeReference;
use Modules\Nutrition\Models\WeightForAgeReference;
use Modules\Nutrition\Models\WeightForHeightReference;

/**
 * S5 -- WHO nutritional status, ALWAYS computed on the server.
 *
 * Bands follow NOM-031-SSA2-1999 (the legacy system shifted them by 1 SD):
 *   P/T, P/E: <= -3 severe | (-3,-2] moderate | (-2,-1] mild | (-1,+1) normal
 *             | [+1,+2) overweight | >= +2 obesity
 *   T/E:      <= -2 short | (-2,-1] slightly short | (-1,+1) normal
 *             | [+1,+2) slightly tall | >= +2 tall
 * Length table for 0-23 months, height table for 24-60; height rounded to
 * the nearest 0.5 cm (legacy truncated with intval, so .5 heights found no
 * row). Outside the tables or older than 60 months -> 'no_reference'; a
 * missing measure leaves the indicators that need it null.
 */
class NutritionalStatusClassifier
{
    public const NO_REFERENCE = 'no_reference';

    public const BAND_UNDER_24_MONTHS = 'under_24_months';

    public const BAND_24_TO_60_MONTHS = '24_to_60_months';

    public const MAX_AGE_MONTHS = 60;

    private const SEX_IDS = [
        'male' => Sex::MALE,
        'female' => Sex::FEMALE,
    ];

    /**
     * @return array{weight_for_height: ?string, weight_for_age: ?string, height_for_age: ?string}
     */
    public function classify(string $sexKey, int $ageMonths, ?int $weightGrams, ?int $heightMm): array
    {
        $sexId = self::SEX_IDS[$sexKey] ?? null;
        $hasReference = $sexId !== null && self::weightForHeightBand($ageMonths) !== null;

        $result = ['weight_for_height' => null, 'weight_for_age' => null, 'height_for_age' => null];

        if ($weightGrams !== null && $heightMm !== null) {
            $result['weight_for_height'] = $hasReference
                ? $this->weightForHeight($sexId, $ageMonths, $weightGrams, $heightMm)
                : self::NO_REFERENCE;
        }

        if ($weightGrams !== null) {
            $result['weight_for_age'] = $hasReference
                ? $this->byAge(WeightForAgeReference::class, $sexId, $ageMonths, fn (array $row) => self::weightBand($weightGrams, $row))
                : self::NO_REFERENCE;
        }

        if ($heightMm !== null) {
            $result['height_for_age'] = $hasReference
                ? $this->byAge(HeightForAgeReference::class, $sexId, $ageMonths, fn (array $row) => self::heightBand($heightMm, $row))
                : self::NO_REFERENCE;
        }

        return $result;
    }

    /**
     * @param  array<string, int>  $row  sd_minus3..sd_plus3
     */
    public static function weightBand(int $weight, array $row): string
    {
        return match (true) {
            $weight <= $row['sd_minus3'] => 'severe_malnutrition',
            $weight <= $row['sd_minus2'] => 'moderate_malnutrition',
            $weight <= $row['sd_minus1'] => 'mild_malnutrition',
            $weight < $row['sd_plus1'] => 'normal_weight',
            $weight < $row['sd_plus2'] => 'overweight',
            default => 'obesity',
        };
    }

    /**
     * @param  array<string, int>  $row  sd_minus3..sd_plus3
     */
    public static function heightBand(int $height, array $row): string
    {
        return match (true) {
            $height <= $row['sd_minus2'] => 'short_stature',
            $height <= $row['sd_minus1'] => 'slightly_short_stature',
            $height < $row['sd_plus1'] => 'normal_stature',
            $height < $row['sd_plus2'] => 'slightly_tall_stature',
            default => 'tall_stature',
        };
    }

    /** Rounds millimetres to the nearest 5 mm (0.5 cm) table step. */
    public static function roundToHalfCentimetre(int $heightMm): int
    {
        return (int) (round($heightMm / 5) * 5);
    }

    /** Table band for weight-for-height; null when the age has no table. */
    public static function weightForHeightBand(int $ageMonths): ?string
    {
        if ($ageMonths < 0 || $ageMonths > self::MAX_AGE_MONTHS) {
            return null;
        }

        return $ageMonths <= 23 ? self::BAND_UNDER_24_MONTHS : self::BAND_24_TO_60_MONTHS;
    }

    /** Completed months between two civil dates (Y-m-d). */
    public static function completedMonths(string $birthDate, string $measuredOn): int
    {
        $from = new DateTimeImmutable($birthDate);
        $to = new DateTimeImmutable($measuredOn);

        $months = ((int) $to->format('Y') - (int) $from->format('Y')) * 12
            + ((int) $to->format('n') - (int) $from->format('n'));

        if ((int) $to->format('j') < (int) $from->format('j')) {
            $months--;
        }

        return $months;
    }

    private function weightForHeight(string $sexId, int $ageMonths, int $weightGrams, int $heightMm): string
    {
        $row = WeightForHeightReference::query()
            ->where('sex_id', $sexId)
            ->where('band', self::weightForHeightBand($ageMonths))
            ->where('height_mm', self::roundToHalfCentimetre($heightMm))
            ->first();

        return $row === null ? self::NO_REFERENCE : self::weightBand($weightGrams, $row->only(['sd_minus3', 'sd_minus2', 'sd_minus1', 'sd_plus1', 'sd_plus2']));
    }

    /**
     * @param  class-string<WeightForAgeReference|HeightForAgeReference>  $model
     * @param  callable(array<string, int>): string  $band
     */
    private function byAge(string $model, string $sexId, int $ageMonths, callable $band): string
    {
        $row = $model::query()->where('sex_id', $sexId)->where('age_months', $ageMonths)->first();

        return $row === null ? self::NO_REFERENCE : $band($row->only(['sd_minus3', 'sd_minus2', 'sd_minus1', 'sd_plus1', 'sd_plus2']));
    }
}
