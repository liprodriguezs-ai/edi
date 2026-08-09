<?php

namespace Modules\Housing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Housing\Models\Vehicle;

class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'unit_id' => null,
            'plate' => strtoupper($this->faker->unique()->bothify('???-####')),
            'make' => $this->faker->optional()->randomElement(['Toyota', 'Honda', 'Ford', 'Chevrolet', 'Nissan']),
            'model' => $this->faker->optional()->word(),
            'color' => $this->faker->optional()->safeColorName(),
            'year' => $this->faker->optional()->numberBetween(2000, 2024),
        ];
    }
}
