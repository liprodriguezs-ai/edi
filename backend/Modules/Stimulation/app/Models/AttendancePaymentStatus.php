<?php

namespace Modules\Stimulation\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central catalog of attendance payment statuses (D4). Rows are seeded once
 * by CatalogSeeder::seedAttendancePaymentStatuses() and are read-only for
 * tenant roles. Defaults a group member row (default_attendance_payment_status_id)
 * and prefills each attendance entry (REQ-SA-001).
 */
#[ObservedBy([GlobalObserver::class])]
class AttendancePaymentStatus extends CentralCatalogModel
{
    protected $table = 'attendance_payment_status';

    public const SCHOLARSHIP = 'ed400003-0000-4000-8000-000000000001';

    public const PAID = 'ed400003-0000-4000-8000-000000000002';

    public const UNPAID = 'ed400003-0000-4000-8000-000000000003';

    protected $fillable = [
        'id',
        'key',
        'name',
        'description',
    ];
}
