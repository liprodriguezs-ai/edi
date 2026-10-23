<?php

namespace Modules\Stimulation\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Stimulation\Models\DidacticMaterial;

/**
 * @extends Factory<DidacticMaterial>
 */
class DidacticMaterialFactory extends Factory
{
    protected $model = DidacticMaterial::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'is_active' => true,
        ];
    }
}
