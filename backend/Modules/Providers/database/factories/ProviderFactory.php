<?php

namespace Modules\Providers\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Providers\Models\Provider;

class ProviderFactory extends Factory
{
    protected $model = Provider::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'rfc' => null,
            'contact_person' => $this->faker->name(),
            'phone' => $this->faker->numerify('55########'),
            'email' => $this->faker->optional()->safeEmail(),
            'address' => $this->faker->optional()->address(),
            'is_recurring' => $this->faker->boolean(60),
            'frequency' => $this->faker->randomElement(['monthly', 'bimonthly', 'eventual']),
            'estimated_amount' => $this->faker->numberBetween(50000, 500000), // 500–5000 MXN in cents
            'usual_payment_day' => $this->faker->numberBetween(1, 28),
            'clabe' => null,
            'bank' => null,
            'notes' => null,
            'is_active' => true,
        ];
    }
}
