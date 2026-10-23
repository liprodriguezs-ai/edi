<?php

namespace Modules\Screening\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Instrument\Models\RiskFactor;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One FRB answer (manual or auto-derived) of a screening (design D8/D9/D12).
 */
#[ObservedBy([GlobalObserver::class])]
class ScreeningRiskFactorAnswer extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'screening_risk_factor_answer';

    protected $fillable = [
        'screening_id',
        'risk_factor_id',
        'is_present',
        'is_auto_derived',
    ];

    protected function casts(): array
    {
        return [
            'is_present' => 'boolean',
            'is_auto_derived' => 'boolean',
        ];
    }

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class, 'screening_id');
    }

    public function risk_factor(): BelongsTo
    {
        return $this->belongsTo(RiskFactor::class, 'risk_factor_id');
    }
}
