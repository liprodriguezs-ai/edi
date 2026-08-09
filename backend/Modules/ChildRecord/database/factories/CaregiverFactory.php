<?php

namespace Modules\ChildRecord\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ChildRecord\Models\Caregiver;
use Modules\ChildRecord\Models\EducationLevel;
use Modules\ChildRecord\Models\Occupation;
use Modules\ChildRecord\Models\PopulationType;

/**
 * @extends Factory<Caregiver>
 */
class CaregiverFactory extends Factory
{
    protected $model = Caregiver::class;

    public function definition(): array
    {
        return [
            'first_name' => $this->faker->firstName(),
            'last_name_paternal' => $this->faker->lastName(),
            'last_name_maternal' => $this->faker->lastName(),
            'birth_date' => $this->faker->dateTimeBetween('-60 years', '-16 years')->format('Y-m-d'),
            'education_level_id' => fn () => EducationLevel::factory()->create()->id,
            'occupation_id' => fn () => Occupation::factory()->create()->id,
            'population_type_id' => $this->faker->randomElement([
                PopulationType::URBAN, PopulationType::RURAL, PopulationType::INDIGENOUS,
            ]),
            'phone' => $this->faker->numerify('##########'),
            'email' => $this->faker->optional()->safeEmail(),
            'has_social_program' => false,
        ];
    }
}
