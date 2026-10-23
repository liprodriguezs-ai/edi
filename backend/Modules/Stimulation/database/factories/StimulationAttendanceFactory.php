<?php

namespace Modules\Stimulation\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ChildRecord\Models\Child;
use Modules\Stimulation\Models\AttendancePaymentStatus;
use Modules\Stimulation\Models\StimulationAttendance;
use Modules\Stimulation\Models\StimulationGroup;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Support\TenantTime;

/**
 * @extends Factory<StimulationAttendance>
 */
class StimulationAttendanceFactory extends Factory
{
    protected $model = StimulationAttendance::class;

    public function definition(): array
    {
        return [
            'stimulation_group_id' => StimulationGroup::factory(),
            'child_id' => Child::factory(),
            'session_date' => TenantTime::today(Tenant::DEFAULT_TIMEZONE),
            'attended' => false,
            'homework_done' => false,
            'attendance_payment_status_id' => AttendancePaymentStatus::UNPAID,
        ];
    }
}
