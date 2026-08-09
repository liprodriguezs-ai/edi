<?php

namespace Modules\AmenityReservations\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\AmenityReservations\Models\ReservationSurcharge;

class ReservationSurchargeFactory extends Factory
{
    protected $model = ReservationSurcharge::class;

    public function definition(): array
    {
        return [
            'reservation_id' => AmenityReservation::factory(),
            // charge_id/charge_concept_id require real tenant-seeded Collections
            // rows the factory cannot guess — always override with real ids
            // (mirrors AmenityReservationFactory's unit_id note).
            'charge_id' => null,
            'charge_concept_id' => null,
            'amount' => $this->faker->numberBetween(5000, 100000),
            'description' => $this->faker->sentence(),
            'created_by' => (string) Str::uuid(),
        ];
    }
}
