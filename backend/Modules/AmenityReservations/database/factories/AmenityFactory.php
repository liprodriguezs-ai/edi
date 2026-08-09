<?php

namespace Modules\AmenityReservations\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\ReservationMode;

class AmenityFactory extends Factory
{
    protected $model = Amenity::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'description' => $this->faker->optional()->sentence(),
            'restrictions' => $this->faker->optional()->sentence(),
            'capacity' => $this->faker->numberBetween(1, 50),
            'available_weekdays' => [1, 2, 3, 4, 5],
            'rules_text' => $this->faker->optional()->paragraph(),
            'out_of_service' => false,
            'out_of_service_reason' => null,
            'has_cost' => false,
            'cost_amount' => null,
            'requires_deposit' => false,
            'deposit_amount' => null,
            'deposit_due_days' => null,
            'bank_account_id' => null,
            'requires_approval' => false,
            'reservation_mode_id' => ReservationMode::BY_DAY,
        ];
    }

    public function withCost(): static
    {
        return $this->state(fn () => [
            'has_cost' => true,
            'cost_amount' => $this->faker->numberBetween(5000, 100000),
        ]);
    }

    public function withDeposit(): static
    {
        return $this->state(fn () => [
            'requires_deposit' => true,
            'deposit_amount' => $this->faker->numberBetween(10000, 200000),
            'deposit_due_days' => $this->faker->numberBetween(1, 7),
        ]);
    }

    public function bySlot(): static
    {
        return $this->state(fn () => [
            'reservation_mode_id' => ReservationMode::BY_SLOT,
        ]);
    }
}
