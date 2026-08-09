<?php

namespace Modules\Housing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Housing\Models\UnitType;

class UnitTypeFactory extends Factory
{
    protected $model = UnitType::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'key' => $this->faker->unique()->slug(1),
            'order' => $this->faker->numberBetween(0, 10),
            'is_active' => true,
        ];
    }
}
