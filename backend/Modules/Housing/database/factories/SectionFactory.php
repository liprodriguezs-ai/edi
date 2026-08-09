<?php

namespace Modules\Housing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Housing\Models\Section;

class SectionFactory extends Factory
{
    protected $model = Section::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'order' => $this->faker->numberBetween(0, 10),
        ];
    }
}
