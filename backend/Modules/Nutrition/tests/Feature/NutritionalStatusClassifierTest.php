<?php

declare(strict_types=1);

namespace Modules\Nutrition\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Nutrition\Database\Seeders\NutritionSeeder;
use Modules\Nutrition\Services\NutritionalStatusClassifier;
use Tests\TestCase;

/**
 * S5 -- classification against the real WHO reference rows (seeded).
 */
final class NutritionalStatusClassifierTest extends TestCase
{
    use RefreshDatabase;

    private NutritionalStatusClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        (new NutritionSeeder)->run();
        $this->classifier = app(NutritionalStatusClassifier::class);
    }

    public function test_classifies_a_24_month_old_boy_on_the_height_table(): void
    {
        // WFH male 24-60 @81.0 cm: -2SD 9200 < 9700 <= -1SD 9900 -> mild
        // WFA male 24 m: 9700 <= -2SD 9700 -> moderate (edge)
        // HFA male 24 m: 810 <= -2SD 810 -> short (edge)
        $this->assertSame([
            'weight_for_height' => 'mild_malnutrition',
            'weight_for_age' => 'moderate_malnutrition',
            'height_for_age' => 'short_stature',
        ], $this->classifier->classify('male', 24, 9700, 810));
    }

    public function test_band_switches_from_length_table_at_23_to_height_table_at_24_months(): void
    {
        // 80.0 cm, 9.0 kg: length table (-2SD 8900, -1SD 9600) -> mild;
        // height table (-2SD 9000) -> moderate.
        $this->assertSame('mild_malnutrition', $this->classifier->classify('male', 23, 9000, 800)['weight_for_height']);
        $this->assertSame('moderate_malnutrition', $this->classifier->classify('male', 24, 9000, 800)['weight_for_height']);
    }

    public function test_height_is_rounded_to_the_nearest_half_centimetre_not_truncated(): void
    {
        // 72.3 cm -> 72.5 row (+1SD 9800): 9.7 kg is normal. The legacy
        // truncation to 72.0 (+1SD 9600) would have said overweight.
        $this->assertSame('normal_weight', $this->classifier->classify('male', 12, 9700, 723)['weight_for_height']);
        $this->assertSame('overweight', $this->classifier->classify('male', 12, 9700, 722)['weight_for_height']);
    }

    public function test_heights_outside_the_table_have_no_reference(): void
    {
        $this->assertSame('no_reference', $this->classifier->classify('male', 12, 9700, 1105)['weight_for_height']);
        $this->assertSame('no_reference', $this->classifier->classify('male', 12, 9700, 440)['weight_for_height']);
    }

    public function test_children_older_than_60_months_have_no_reference(): void
    {
        $this->assertSame([
            'weight_for_height' => 'no_reference',
            'weight_for_age' => 'no_reference',
            'height_for_age' => 'no_reference',
        ], $this->classifier->classify('female', 61, 18000, 1100));
    }

    public function test_a_missing_measure_leaves_its_indicators_null(): void
    {
        $this->assertSame([
            'weight_for_height' => null,
            'weight_for_age' => null,
            'height_for_age' => 'short_stature',
        ], $this->classifier->classify('male', 24, null, 810));

        $this->assertSame([
            'weight_for_height' => null,
            'weight_for_age' => 'moderate_malnutrition',
            'height_for_age' => null,
        ], $this->classifier->classify('male', 24, 9700, null));
    }

    public function test_female_rows_are_used_for_girls(): void
    {
        // WFH female 24-60 @65.0 cm: -1SD 6600 < 7000 < +1SD 7900 -> normal
        $this->assertSame('normal_weight', $this->classifier->classify('female', 30, 7000, 650)['weight_for_height']);
    }
}
