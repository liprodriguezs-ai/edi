<?php

namespace Modules\Stimulation\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ChildRecord\Models\Child;
use Modules\Stimulation\Models\AttendancePaymentStatus;
use Modules\Stimulation\Models\StimulationGroup;
use Modules\Stimulation\Models\StimulationGroupMember;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Support\TenantTime;

/**
 * @extends Factory<StimulationGroupMember>
 */
class StimulationGroupMemberFactory extends Factory
{
    protected $model = StimulationGroupMember::class;

    public function definition(): array
    {
        return [
            'stimulation_group_id' => StimulationGroup::factory(),
            'child_id' => Child::factory(),
            'enrolled_at' => TenantTime::today(Tenant::DEFAULT_TIMEZONE),
            'default_attendance_payment_status_id' => AttendancePaymentStatus::UNPAID,
        ];
    }
}
