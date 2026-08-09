<?php

namespace Modules\Housing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Housing\Models\UnitFeature;

class UnitFeatureFactory extends Factory
{
    protected $model = UnitFeature::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'is_active' => true,
        ];
    }
}
