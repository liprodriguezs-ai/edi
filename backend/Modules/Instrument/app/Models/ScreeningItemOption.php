<?php

namespace Modules\Instrument\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Instrument\Database\Factories\ScreeningItemOptionFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;

/**
 * One of the two answers of an item and the color it produces (D4/D11).
 * No legacy_id: the option is embedded in the item row at source, it has no
 * standalone legacy identity.
 */
#[ObservedBy([GlobalObserver::class])]
class ScreeningItemOption extends BaseModel
{
    use HasFactory;

    protected $table = 'screening_item_option';

    protected $fillable = [
        'id',
        'instrument_version_id',
        'screening_item_id',
        'label',
        'sort_order',
        'result_color_id',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(InstrumentVersion::class, 'instrument_version_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ScreeningItem::class, 'screening_item_id');
    }

    public function result_color(): BelongsTo
    {
        return $this->belongsTo(ResultColor::class, 'result_color_id');
    }

    protected static function newFactory(): ScreeningItemOptionFactory
    {
        return ScreeningItemOptionFactory::new();
    }
}
