<?php

namespace Modules\AmenityReservations\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\AmenityReservations\Models\ReservationStatus;

class AmenityReservationFactory extends Factory
{
    protected $model = AmenityReservation::class;

    public function definition(): array
    {
        return [
            'amenity_id' => Amenity::factory(),
            // Unit requires tenant-seeded catalog rows (unit_type/unit_status)
            // the factory cannot guess — always override with a real Unit id
            // (mirrors Modules\Housing\Database\Factories\VehicleFactory).
            'unit_id' => null,
            'user_id' => (string) Str::uuid(),
            'reservation_date' => now()->addDay()->toDateString(),
            'slot_id' => null,
            'start_at' => null,
            'end_at' => null,
            'reservation_status_id' => ReservationStatus::PENDING_APPROVAL,
            'holds_slot' => true,
            'amount_outstanding' => 0,
        ];
    }

    /** The reservation actively occupies its amenity/date (or amenity/slot/date). */
    public function holdingSlot(): static
    {
        return $this->state(fn (): array => ['holds_slot' => true]);
    }

    /** The reservation was cancelled/rejected — it no longer blocks re-booking. */
    public function released(): static
    {
        return $this->state(fn (): array => [
            'holds_slot' => false,
            'reservation_status_id' => ReservationStatus::CANCELLED,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => ['reservation_status_id' => ReservationStatus::CONFIRMED]);
    }
}
