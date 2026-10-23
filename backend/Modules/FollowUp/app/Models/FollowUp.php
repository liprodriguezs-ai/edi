<?php

namespace Modules\FollowUp\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\ChildRecord\Models\Child;
use Modules\Screening\Models\Screening;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Conduct derived from a screening result (design §3). REQ-FUP-006: the
 * FSM/frozen fields are NEVER client-writable -- $fillable carries only what
 * a FormRequest can whitelist. follow_up_status_id, closed_by_screening_id,
 * and completed_at are written exclusively via forceFill from
 * FollowUpCreationService / FollowUpLifecycleService / the auto-close
 * listener step.
 */
#[ObservedBy([GlobalObserver::class])]
class FollowUp extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'follow_up';

    protected $fillable = [
        'due_date',
        'referral_specialty_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'child_id');
    }

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class, 'screening_id');
    }

    public function follow_up_type(): BelongsTo
    {
        return $this->belongsTo(FollowUpType::class, 'follow_up_type_id');
    }

    public function follow_up_status(): BelongsTo
    {
        return $this->belongsTo(FollowUpStatus::class, 'follow_up_status_id');
    }

    public function referral_specialty(): BelongsTo
    {
        return $this->belongsTo(ReferralSpecialty::class, 'referral_specialty_id');
    }

    public function closed_by_screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class, 'closed_by_screening_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'follow_up_id');
    }
}
