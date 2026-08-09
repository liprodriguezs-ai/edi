<?php

namespace Modules\ChildRecord\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ChildRecord\Models\Occupation;

/**
 * @extends Factory<Occupation>
 */
class OccupationFactory extends Factory
{
    protected $model = Occupation::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'is_active' => true,
        ];
    }
}
