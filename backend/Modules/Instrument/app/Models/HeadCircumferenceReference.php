<?php

namespace Modules\Instrument\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ChildRecord\Models\Sex;
use Modules\Instrument\Database\Factories\HeadCircumferenceReferenceFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Percentile band (+-2 SD) of head circumference in integer mm, keyed by
 * (sex, age in months) (D4/D19). sex_id references ChildRecord's central
 * sex catalog app-level -- no DB FK.
 */
#[ObservedBy([GlobalObserver::class])]
class HeadCircumferenceReference extends BaseModel
{
    use HasFactory;

    protected $table = 'head_circumference_reference';

    protected $fillable = [
        'id',
        'instrument_version_id',
        'sex_id',
        'age_months',
        'min_circumference_mm',
        'max_circumference_mm',
        'legacy_id',
    ];

    protected $hidden = [
        'legacy_id',
    ];

    protected function casts(): array
    {
        return [
            'age_months' => 'integer',
            'min_circumference_mm' => 'integer',
            'max_circumference_mm' => 'integer',
            'legacy_id' => 'integer',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(InstrumentVersion::class, 'instrument_version_id');
    }

    /**
     * App-level relation to ChildRecord's central sex catalog (D19) -- no DB
     * FK; Eloquent relations do not require one. Exists so the read-only
     * inspector can `include=sex` without the frontend hardcoding the two
     * fixed sex UUIDs from a different module.
     */
    public function sex(): BelongsTo
    {
        return $this->belongsTo(Sex::class, 'sex_id');
    }

    protected static function newFactory(): HeadCircumferenceReferenceFactory
    {
        return HeadCircumferenceReferenceFactory::new();
    }
}
