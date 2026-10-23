<?php

namespace Modules\Stimulation\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ChildRecord\Models\Child;
use Modules\Stimulation\Database\Factories\StimulationAttendanceFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Attendance fact of a child in a group on a session date (REQ-SA-001/002).
 * Upserted by natural key (stimulation_group_id, child_id, session_date) —
 * see the migration for the plain-unique rationale (design D8). No destroy
 * endpoint is exposed: attendance rows are facts, never deleted.
 */
#[ObservedBy([GlobalObserver::class])]
class StimulationAttendance extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'stimulation_attendance';

    protected $fillable = [
        'stimulation_group_id',
        'child_id',
        'session_date',
        'attended',
        'homework_done',
        'attendance_payment_status_id',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
            'attended' => 'boolean',
            'homework_done' => 'boolean',
        ];
    }

    public function stimulation_group(): BelongsTo
    {
        return $this->belongsTo(StimulationGroup::class, 'stimulation_group_id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'child_id');
    }

    public function attendance_payment_status(): BelongsTo
    {
        return $this->belongsTo(AttendancePaymentStatus::class, 'attendance_payment_status_id');
    }

    protected static function newFactory(): StimulationAttendanceFactory
    {
        return StimulationAttendanceFactory::new();
    }
}
