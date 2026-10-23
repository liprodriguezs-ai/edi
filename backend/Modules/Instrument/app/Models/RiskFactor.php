<?php

namespace Modules\Instrument\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Instrument\Database\Factories\RiskFactorFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Biological risk factor (FRB) of a version (D4). Three are auto-derivable
 * from birth_record; this change stores the metadata only (auto_derivation_key).
 */
#[ObservedBy([GlobalObserver::class])]
class RiskFactor extends BaseModel
{
    use HasFactory;

    protected $table = 'risk_factor';

    public const AUTO_DERIVATION_GESTATIONAL_AGE = 'gestational_age_lt_34_weeks';

    public const AUTO_DERIVATION_BIRTH_WEIGHT = 'birth_weight_lte_1500_g';

    public const AUTO_DERIVATION_MOTHER_AGE = 'mother_age_lt_16_years';

    protected $fillable = [
        'id',
        'instrument_version_id',
        'code',
        'question',
        'sort_order',
        'auto_derivation_key',
        'legacy_id',
    ];

    protected $hidden = [
        'legacy_id',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'legacy_id' => 'integer',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(InstrumentVersion::class, 'instrument_version_id');
    }

    protected static function newFactory(): RiskFactorFactory
    {
        return RiskFactorFactory::new();
    }
}
