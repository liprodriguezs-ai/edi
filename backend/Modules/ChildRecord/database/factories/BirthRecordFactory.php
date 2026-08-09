<?php

namespace Modules\ChildRecord\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ChildRecord\Models\BirthRecord;
use Modules\ChildRecord\Models\Child;

/**
 * @extends Factory<BirthRecord>
 */
class BirthRecordFactory extends Factory
{
    protected $model = BirthRecord::class;

    public function definition(): array
    {
        return [
            'child_id' => fn () => Child::factory()->create()->id,
            'gestational_age_weeks' => $this->faker->numberBetween(20, 44),
            'birth_weight_grams' => $this->faker->numberBetween(400, 7000),
            'birth_length_mm' => $this->faker->numberBetween(200, 650),
            'prenatal_visit_count' => $this->faker->numberBetween(0, 40),
            'had_pregnancy_complication' => $this->faker->boolean(),
            'had_delivery_complication' => $this->faker->boolean(),
            'nicu_days' => $this->faker->numberBetween(0, 365),
            'mother_age_at_birth_years' => $this->faker->numberBetween(10, 60),
            'early_breastfeeding' => $this->faker->boolean(),
            'breastfeeding_months' => $this->faker->numberBetween(0, 71),
        ];
    }
}
