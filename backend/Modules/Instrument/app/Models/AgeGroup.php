<?php

namespace Modules\Instrument\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Instrument\Database\Factories\AgeGroupFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Age group of an instrument version (D4). Official groups (G1-G15) partition
 * 30-2159 days; alternates (G1A-G14A) are reached ONLY via
 * alternate_age_group_id, never by age lookup (D8).
 */
#[ObservedBy([GlobalObserver::class])]
class AgeGroup extends BaseModel
{
    use HasFactory;

    protected $table = 'age_group';

    protected $fillable = [
        'id',
        'instrument_version_id',
        'code',
        'name',
        'ordinal',
        'is_alternate',
        'min_age_days',
        'max_age_days',
        'min_age_months',
        'max_age_months',
        'alternate_age_group_id',
        'materials_note',
        'legacy_id',
    ];

    protected $hidden = [
        'legacy_id',
    ];

    protected function casts(): array
    {
        return [
            'ordinal' => 'integer',
            'is_alternate' => 'boolean',
            'min_age_days' => 'integer',
            'max_age_days' => 'integer',
            'min_age_months' => 'integer',
            'max_age_months' => 'integer',
            'legacy_id' => 'integer',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(InstrumentVersion::class, 'instrument_version_id');
    }

    public function alternate(): BelongsTo
    {
        return $this->belongsTo(self::class, 'alternate_age_group_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ScreeningItem::class, 'age_group_id');
    }

    public function area_scoring_rules(): HasMany
    {
        return $this->hasMany(AreaScoringRule::class, 'age_group_id');
    }

    public function global_scoring_rules(): HasMany
    {
        return $this->hasMany(GlobalScoringRule::class, 'age_group_id');
    }

    protected static function newFactory(): AgeGroupFactory
    {
        return AgeGroupFactory::new();
    }
}
