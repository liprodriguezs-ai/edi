<?php

namespace Modules\Stimulation\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Stimulation\Models\MassageMovement;

/**
 * @extends Factory<MassageMovement>
 */
class MassageMovementFactory extends Factory
{
    protected $model = MassageMovement::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'is_active' => true,
        ];
    }
}
