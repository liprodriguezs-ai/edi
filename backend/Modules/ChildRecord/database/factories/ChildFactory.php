<?php

namespace Modules\ChildRecord\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\Sex;
use Modules\Clinic\Database\Factories\HealthCenterFactory;
use Modules\Clinic\Models\HealthCenter;

/**
 * @extends Factory<Child>
 */
class ChildFactory extends Factory
{
    protected $model = Child::class;

    public function definition(): array
    {
        return [
            'first_name' => $this->faker->firstName(),
            'last_name_paternal' => $this->faker->lastName(),
            'last_name_maternal' => $this->faker->lastName(),
            'birth_date' => $this->faker->dateTimeBetween('-6 years', 'now')->format('Y-m-d'),
            'sex_id' => $this->faker->randomElement([Sex::MALE, Sex::FEMALE]),
            'curp' => null,
            'curp_mismatch' => false,
            'external_id' => null,
            'health_center_id' => fn () => HealthCenter::factory()->create([
                'municipality_id' => HealthCenterFactory::defaultMunicipality()->id,
            ])->id,
            'is_schooled' => false,
        ];
    }
}
