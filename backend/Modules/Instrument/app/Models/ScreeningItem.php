<?php

namespace Modules\Instrument\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Instrument\Database\Factories\ScreeningItemFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Reactivo (item) of a group (D4). code is verbatim and opaque (D10) --
 * never parsed to derive group/area.
 */
#[ObservedBy([GlobalObserver::class])]
class ScreeningItem extends BaseModel
{
    use HasFactory;

    protected $table = 'screening_item';

    protected $fillable = [
        'id',
        'instrument_version_id',
        'age_group_id',
        'development_area_id',
        'application_position_id',
        'code',
        'question',
        'sort_order',
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

    public function age_group(): BelongsTo
    {
        return $this->belongsTo(AgeGroup::class, 'age_group_id');
    }

    public function development_area(): BelongsTo
    {
        return $this->belongsTo(DevelopmentArea::class, 'development_area_id');
    }

    public function application_position(): BelongsTo
    {
        return $this->belongsTo(ApplicationPosition::class, 'application_position_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ScreeningItemOption::class, 'screening_item_id');
    }

    protected static function newFactory(): ScreeningItemFactory
    {
        return ScreeningItemFactory::new();
    }
}
