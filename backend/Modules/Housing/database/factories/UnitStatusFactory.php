<?php

namespace Modules\Housing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Housing\Models\UnitStatus;

class UnitStatusFactory extends Factory
{
    protected $model = UnitStatus::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'key' => $this->faker->unique()->slug(1),
            'color' => $this->faker->hexColor(),
            'order' => $this->faker->numberBetween(0, 10),
            'is_active' => true,
        ];
    }
}
