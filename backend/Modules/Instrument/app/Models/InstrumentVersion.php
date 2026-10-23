<?php

namespace Modules\Instrument\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Instrument\Database\Factories\InstrumentVersionFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Immutable edition of an instrument (D1/D5). published_at NULL = draft
 * (mutable, seeder-only); once set, content is frozen (InstrumentVersionObserver
 * / InstrumentContentObserver) and only is_default may still change here.
 *
 * NOT a CentralCatalogModel: unlike the support catalogs (D3), this row is
 * actively written by the seeder's draft -> publish flow, so it must never
 * risk serving a stale cached read mid-seed.
 */
#[ObservedBy([GlobalObserver::class])]
class InstrumentVersion extends BaseModel
{
    use HasFactory;

    protected $table = 'instrument_version';

    public const EDI_LEGACY_2013 = 'ed200005-0000-4000-8000-000000000001';

    public const EDI_2022_2ED = 'ed200005-0000-4000-8000-000000000002';

    public const AGE_MODE_THIRTY_DAY_MONTH = 'thirty_day_month';

    public const AGE_MODE_CALENDAR = 'calendar';

    protected $fillable = [
        'id',
        'instrument_id',
        'key',
        'name',
        'age_calculation_mode',
        'is_default',
        'published_at',
        'content_hash',
        'source_note',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class, 'instrument_id');
    }

    public function age_groups(): HasMany
    {
        return $this->hasMany(AgeGroup::class, 'instrument_version_id');
    }

    public function screening_items(): HasMany
    {
        return $this->hasMany(ScreeningItem::class, 'instrument_version_id');
    }

    public function risk_factors(): HasMany
    {
        return $this->hasMany(RiskFactor::class, 'instrument_version_id');
    }

    public function head_circumference_references(): HasMany
    {
        return $this->hasMany(HeadCircumferenceReference::class, 'instrument_version_id');
    }

    public function area_scoring_rules(): HasMany
    {
        return $this->hasMany(AreaScoringRule::class, 'instrument_version_id');
    }

    public function global_scoring_rules(): HasMany
    {
        return $this->hasMany(GlobalScoringRule::class, 'instrument_version_id');
    }

    public function global_result_messages(): HasMany
    {
        return $this->hasMany(GlobalResultMessage::class, 'instrument_version_id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    protected static function newFactory(): InstrumentVersionFactory
    {
        return InstrumentVersionFactory::new();
    }
}
