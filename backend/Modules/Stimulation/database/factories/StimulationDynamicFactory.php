<?php

namespace Modules\Stimulation\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Stimulation\Models\StimulationDynamic;

/**
 * @extends Factory<StimulationDynamic>
 */
class StimulationDynamicFactory extends Factory
{
    protected $model = StimulationDynamic::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'is_active' => true,
        ];
    }
}
