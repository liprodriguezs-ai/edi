<?php

namespace Modules\Treasury\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Treasury\Models\BankAccountPurpose;

class BankAccountPurposeFactory extends Factory
{
    protected $model = BankAccountPurpose::class;

    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->slug(1),
            'name' => $this->faker->unique()->word(),
            'order' => $this->faker->numberBetween(0, 10),
            'is_active' => true,
        ];
    }
}
