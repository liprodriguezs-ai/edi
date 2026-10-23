<?php

namespace Modules\Appointment\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Appointment\Models\Appointment;
use Modules\Appointment\Models\AppointmentStatus;
use Modules\Appointment\Models\AppointmentType;
use Modules\ChildRecord\Models\Child;
use Modules\Clinic\Models\PractitionerProfile;

/**
 * @extends Factory<Appointment>
 *
 * Factories run unguarded, so lifecycle columns can be arranged directly; HTTP
 * tests exercise the real AppointmentLifecycleService instead.
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $start = now()->addDay()->setTime(10, 0);

        return [
            'child_id' => fn () => Child::factory()->create()->id,
            'practitioner_id' => fn () => PractitionerProfile::factory()->create()->id,
            'health_center_id' => fn (array $attributes) => PractitionerProfile::find($attributes['practitioner_id'])?->health_center_id,
            'appointment_type_id' => AppointmentType::SCREENING,
            'appointment_status_id' => AppointmentStatus::SCHEDULED,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes(30),
            'is_walk_in' => false,
        ];
    }
}
