<?php

namespace Modules\Stimulation\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Stimulation\Models\BodyZone;

/**
 * @extends Factory<BodyZone>
 */
class BodyZoneFactory extends Factory
{
    protected $model = BodyZone::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'is_active' => true,
        ];
    }
}
