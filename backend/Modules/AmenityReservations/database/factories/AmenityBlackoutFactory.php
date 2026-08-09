<?php

namespace Modules\AmenityReservations\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenityBlackout;

class AmenityBlackoutFactory extends Factory
{
    protected $model = AmenityBlackout::class;

    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('+1 days', '+30 days');

        return [
            'amenity_id' => Amenity::factory(),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $start->format('Y-m-d'),
            'reason' => $this->faker->optional()->sentence(),
        ];
    }
}
