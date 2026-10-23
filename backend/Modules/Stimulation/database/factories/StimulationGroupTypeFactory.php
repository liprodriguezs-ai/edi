<?php

namespace Modules\Stimulation\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Stimulation\Models\StimulationGroupType;

/**
 * @extends Factory<StimulationGroupType>
 */
class StimulationGroupTypeFactory extends Factory
{
    protected $model = StimulationGroupType::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'is_active' => true,
        ];
    }
}
