<?php

namespace Modules\Stimulation\Http\Controllers;

use Modules\Stimulation\Models\AttendancePaymentStatus;
use QCH\Core\Http\Controllers\Controller;

class AttendancePaymentStatusController extends Controller
{
    protected $model = AttendancePaymentStatus::class;

    protected $keyForPermission = 'attendance_payment_status';

    public function sortableBy(): array
    {
        return ['name', 'created_at'];
    }

    public function searchableBy(): array
    {
        return ['name'];
    }
}
