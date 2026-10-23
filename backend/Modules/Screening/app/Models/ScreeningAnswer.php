<?php

namespace Modules\Screening\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Instrument\Models\ResultColor;
use Modules\Instrument\Models\ScreeningItem;
use Modules\Instrument\Models\ScreeningItemOption;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One item answer of a screening; result_color_id is a server-resolved
 * snapshot from the selected option (design D4) -- it has NO write endpoint
 * (REQ-SCR-003).
 */
#[ObservedBy([GlobalObserver::class])]
class ScreeningAnswer extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'screening_answer';

    protected $fillable = [
        'screening_id',
        'screening_item_id',
        'screening_item_option_id',
        'result_color_id',
        'is_auto_derived',
    ];

    protected function casts(): array
    {
        return [
            'is_auto_derived' => 'boolean',
        ];
    }

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class, 'screening_id');
    }

    public function screening_item(): BelongsTo
    {
        return $this->belongsTo(ScreeningItem::class, 'screening_item_id');
    }

    public function screening_item_option(): BelongsTo
    {
        return $this->belongsTo(ScreeningItemOption::class, 'screening_item_option_id');
    }

    public function result_color(): BelongsTo
    {
        return $this->belongsTo(ResultColor::class, 'result_color_id');
    }
}
