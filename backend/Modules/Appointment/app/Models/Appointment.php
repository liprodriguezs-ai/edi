<?php

namespace Modules\Appointment\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Appointment\Casts\UtcInstant;
use Modules\Appointment\Database\Factories\AppointmentFactory;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\Visit;
use Modules\Clinic\Models\HealthCenter;
use Modules\Clinic\Models\PractitionerProfile;
use Modules\FollowUp\Models\FollowUp;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Agenda booking. The clinical encounter stays in `visit`; this row only gives
 * birth to it when care starts. Lifecycle columns (status and the *_at stamps,
 * visit_id) are written exclusively by AppointmentLifecycleService, so they are
 * absent from $fillable.
 */
#[ObservedBy([GlobalObserver::class])]
class Appointment extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'appointment';

    protected $fillable = [
        'child_id',
        'health_center_id',
        'practitioner_id',
        'appointment_type_id',
        'follow_up_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => UtcInstant::class,
            'ends_at' => UtcInstant::class,
            'confirmed_at' => UtcInstant::class,
            'arrived_at' => UtcInstant::class,
            'started_at' => UtcInstant::class,
            'cancelled_at' => UtcInstant::class,
            'no_show_at' => UtcInstant::class,
            'is_walk_in' => 'boolean',
        ];
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'child_id');
    }

    public function health_center(): BelongsTo
    {
        return $this->belongsTo(HealthCenter::class, 'health_center_id');
    }

    public function practitioner(): BelongsTo
    {
        return $this->belongsTo(PractitionerProfile::class, 'practitioner_id');
    }

    public function appointment_type(): BelongsTo
    {
        return $this->belongsTo(AppointmentType::class, 'appointment_type_id');
    }

    public function appointment_status(): BelongsTo
    {
        return $this->belongsTo(AppointmentStatus::class, 'appointment_status_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class, 'visit_id');
    }

    public function follow_up(): BelongsTo
    {
        return $this->belongsTo(FollowUp::class, 'follow_up_id');
    }

    protected static function newFactory(): AppointmentFactory
    {
        return AppointmentFactory::new();
    }
}
