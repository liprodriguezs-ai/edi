<?php

namespace Modules\FollowUp\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Concrete referral act of a specialist_referral follow-up (design §3,
 * REQ-FUP-007). N referrals per follow-up are allowed.
 */
#[ObservedBy([GlobalObserver::class])]
class Referral extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'referral';

    protected $fillable = [
        'follow_up_id',
        'referral_specialty_id',
        'referred_at',
        'institution',
        'outcome_notes',
    ];

    protected function casts(): array
    {
        return [
            'referred_at' => 'date',
        ];
    }

    public function follow_up(): BelongsTo
    {
        return $this->belongsTo(FollowUp::class, 'follow_up_id');
    }

    public function referral_specialty(): BelongsTo
    {
        return $this->belongsTo(ReferralSpecialty::class, 'referral_specialty_id');
    }
}
