<?php

namespace Modules\Instrument\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ChildRecord\Models\Sex;
use Modules\Instrument\Models\HeadCircumferenceReference;

/**
 * @extends Factory<HeadCircumferenceReference>
 */
class HeadCircumferenceReferenceFactory extends Factory
{
    protected $model = HeadCircumferenceReference::class;

    public function definition(): array
    {
        return [
            'instrument_version_id' => fn () => InstrumentVersionFactory::new()->create()->id,
            'sex_id' => Sex::MALE,
            'age_months' => $this->faker->unique()->numberBetween(1, 59),
            'min_circumference_mm' => 350,
            'max_circumference_mm' => 400,
            'legacy_id' => $this->faker->unique()->numberBetween(1, 200),
        ];
    }
}
