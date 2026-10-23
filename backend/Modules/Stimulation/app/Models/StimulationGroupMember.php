<?php

namespace Modules\Stimulation\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ChildRecord\Models\Child;
use Modules\Stimulation\Database\Factories\StimulationGroupMemberFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Enrollment of a child in a stimulation group (REQ-SG-002).
 */
#[ObservedBy([GlobalObserver::class])]
class StimulationGroupMember extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'stimulation_group_member';

    protected $fillable = [
        'stimulation_group_id',
        'child_id',
        'enrolled_at',
        'default_attendance_payment_status_id',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'date',
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

    public function default_attendance_payment_status(): BelongsTo
    {
        return $this->belongsTo(AttendancePaymentStatus::class, 'default_attendance_payment_status_id');
    }

    protected static function newFactory(): StimulationGroupMemberFactory
    {
        return StimulationGroupMemberFactory::new();
    }
}
