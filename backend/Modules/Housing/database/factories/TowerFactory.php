<?php

namespace Modules\Housing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Housing\Models\Tower;

class TowerFactory extends Factory
{
    protected $model = Tower::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'floors_count' => $this->faker->optional()->numberBetween(1, 30),
            'section_id' => null,
            'order' => $this->faker->numberBetween(0, 10),
        ];
    }
}
