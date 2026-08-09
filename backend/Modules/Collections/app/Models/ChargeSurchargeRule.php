<?php

namespace Modules\Collections\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * ChargeSurchargeRule — a single escalation step of a charge_policy's late-fee schedule.
 *
 * A policy owns 0..N rules (1:N). The accrual engine selects the active rule with the
 * greatest from_month <= monthsOverdue, then computes:
 *   - PERCENT mode: base * surcharge_value / 10000 (basis points).
 *   - FIXED mode:   surcharge_value (minor units).
 * scaled by recurrence (one_time vs per_month_overdue) and capped by cap_amount.
 *
 * surcharge_value and cap_amount are intentionally NOT MoneyIntegerCast: surcharge_value
 * is basis points in percent mode (not minor units), and the accrual engine reads both as
 * raw integers for deterministic arithmetic.
 */
#[ObservedBy([GlobalObserver::class])]
class ChargeSurchargeRule extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    public const RECURRENCE_ONE_TIME = 'one_time';

    public const RECURRENCE_PER_MONTH_OVERDUE = 'per_month_overdue';

    /** @var array<int, string> */
    public const RECURRENCES = [
        self::RECURRENCE_ONE_TIME,
        self::RECURRENCE_PER_MONTH_OVERDUE,
    ];

    protected $table = 'charge_surcharge_rule';

    protected $fillable = [
        'charge_policy_id',
        'from_month',
        'surcharge_mode_id',
        'surcharge_value',
        'recurrence',
        'cap_amount',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'from_month' => 'integer',
            'surcharge_value' => 'integer',
            'cap_amount' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ChargePolicy::class, 'charge_policy_id');
    }

    public function surcharge_mode(): BelongsTo
    {
        return $this->belongsTo(SurchargeMode::class, 'surcharge_mode_id');
    }
}
