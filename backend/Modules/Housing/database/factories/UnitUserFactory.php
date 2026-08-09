<?php

namespace Modules\Housing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Housing\Models\UnitUser;

class UnitUserFactory extends Factory
{
    protected $model = UnitUser::class;

    public function definition(): array
    {
        return [
            'unit_id' => null,
            'user_id' => null,
            'unit_relationship_id' => null,
            'is_billing_owner' => false,
            'is_legal_representative' => false,
            'start_date' => $this->faker->optional()->date(),
            'end_date' => null,
            'notes' => $this->faker->optional()->sentence(),
        ];
    }
}
