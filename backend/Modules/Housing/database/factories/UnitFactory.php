<?php

namespace Modules\Housing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Housing\Models\Unit;

class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        return [
            'unit_type_id' => null,
            'number' => $this->faker->optional()->numerify('###'),
            'tower_id' => null,
            'floor' => $this->faker->optional()->numberBetween(1, 30),
            'section_id' => null,
            'built_m2' => $this->faker->optional()->randomFloat(2, 40, 500),
            'land_m2' => $this->faker->optional()->randomFloat(2, 40, 500),
            'ownership_share' => $this->faker->optional()->randomFloat(4, 0.01, 100),
            'unit_status_id' => null,
            'notes' => $this->faker->optional()->sentence(),
        ];
    }
}
