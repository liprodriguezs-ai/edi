<?php

namespace Modules\AmenityReservations\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenitySlot;

class AmenitySlotFactory extends Factory
{
    protected $model = AmenitySlot::class;

    public function definition(): array
    {
        return [
            'amenity_id' => Amenity::factory(),
            'weekday' => $this->faker->numberBetween(0, 6),
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'capacity_override' => null,
        ];
    }
}
