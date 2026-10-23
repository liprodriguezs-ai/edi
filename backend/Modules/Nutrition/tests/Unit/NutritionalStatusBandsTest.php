<?php

declare(strict_types=1);

namespace Modules\Nutrition\Tests\Unit;

use Modules\Nutrition\Services\NutritionalStatusClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * S5 -- pure band rules (NOM-031-SSA2-1999). Every edge of every band is
 * pinned against a synthetic SD row so the boundaries (<= vs <) are explicit.
 */
final class NutritionalStatusBandsTest extends TestCase
{
    private const ROW = [
        'sd_minus3' => 10,
        'sd_minus2' => 20,
        'sd_minus1' => 30,
        'median' => 40,
        'sd_plus1' => 50,
        'sd_plus2' => 60,
        'sd_plus3' => 70,
    ];

    /**
     * @return array<string, array{int, string}>
     */
    public static function weightEdges(): array
    {
        return [
            'below -3 SD' => [5, 'severe_malnutrition'],
            'at -3 SD' => [10, 'severe_malnutrition'],
            'just above -3 SD' => [11, 'moderate_malnutrition'],
            'at -2 SD' => [20, 'moderate_malnutrition'],
            'just above -2 SD' => [21, 'mild_malnutrition'],
            'at -1 SD' => [30, 'mild_malnutrition'],
            'just above -1 SD' => [31, 'normal_weight'],
            'just below +1 SD' => [49, 'normal_weight'],
            'at +1 SD' => [50, 'overweight'],
            'just below +2 SD' => [59, 'overweight'],
            'at +2 SD' => [60, 'obesity'],
            'above +3 SD' => [90, 'obesity'],
        ];
    }

    #[DataProvider('weightEdges')]
    public function test_weight_bands(int $weight, string $expected): void
    {
        $this->assertSame($expected, NutritionalStatusClassifier::weightBand($weight, self::ROW));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function heightEdges(): array
    {
        return [
            'below -2 SD' => [5, 'short_stature'],
            'at -2 SD' => [20, 'short_stature'],
            'just above -2 SD' => [21, 'slightly_short_stature'],
            'at -1 SD' => [30, 'slightly_short_stature'],
            'just above -1 SD' => [31, 'normal_stature'],
            'just below +1 SD' => [49, 'normal_stature'],
            'at +1 SD' => [50, 'slightly_tall_stature'],
            'just below +2 SD' => [59, 'slightly_tall_stature'],
            'at +2 SD' => [60, 'tall_stature'],
        ];
    }

    #[DataProvider('heightEdges')]
    public function test_height_bands(int $height, string $expected): void
    {
        $this->assertSame($expected, NutritionalStatusClassifier::heightBand($height, self::ROW));
    }

    public function test_height_is_rounded_to_the_nearest_half_centimetre(): void
    {
        $this->assertSame(725, NutritionalStatusClassifier::roundToHalfCentimetre(723));
        $this->assertSame(720, NutritionalStatusClassifier::roundToHalfCentimetre(722));
        $this->assertSame(725, NutritionalStatusClassifier::roundToHalfCentimetre(727));
        $this->assertSame(730, NutritionalStatusClassifier::roundToHalfCentimetre(728));
        $this->assertSame(725, NutritionalStatusClassifier::roundToHalfCentimetre(725));
    }

    public function test_length_table_until_23_months_and_height_table_from_24_to_60(): void
    {
        $this->assertSame('under_24_months', NutritionalStatusClassifier::weightForHeightBand(0));
        $this->assertSame('under_24_months', NutritionalStatusClassifier::weightForHeightBand(23));
        $this->assertSame('24_to_60_months', NutritionalStatusClassifier::weightForHeightBand(24));
        $this->assertSame('24_to_60_months', NutritionalStatusClassifier::weightForHeightBand(60));
        $this->assertNull(NutritionalStatusClassifier::weightForHeightBand(61));
        $this->assertNull(NutritionalStatusClassifier::weightForHeightBand(-1));
    }

    public function test_age_is_completed_months_between_birth_and_measurement(): void
    {
        $this->assertSame(0, NutritionalStatusClassifier::completedMonths('2024-01-15', '2024-02-14'));
        $this->assertSame(1, NutritionalStatusClassifier::completedMonths('2024-01-15', '2024-02-15'));
        $this->assertSame(23, NutritionalStatusClassifier::completedMonths('2022-01-15', '2024-01-14'));
        $this->assertSame(24, NutritionalStatusClassifier::completedMonths('2022-01-15', '2024-01-15'));
        $this->assertSame(0, NutritionalStatusClassifier::completedMonths('2024-03-01', '2024-03-01'));
    }
}
