<?php

namespace Modules\Screening\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Instrument\Models\DevelopmentArea;
use Modules\Instrument\Models\ResultColor;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Per-area engine result (design D7). Engine output only -- NO write
 * endpoint exists for this table (REQ-SCR-003).
 */
#[ObservedBy([GlobalObserver::class])]
class ScreeningAreaResult extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'screening_area_result';

    protected $fillable = [
        'screening_id',
        'development_area_id',
        'result_color_id',
        'red_count',
        'yellow_count',
        'green_count',
        'answered_own_count',
        'answered_alternate_count',
        'red_threshold',
        'green_threshold',
        'allows_yellow',
    ];

    protected function casts(): array
    {
        return [
            'red_count' => 'integer',
            'yellow_count' => 'integer',
            'green_count' => 'integer',
            'answered_own_count' => 'integer',
            'answered_alternate_count' => 'integer',
            'red_threshold' => 'integer',
            'green_threshold' => 'integer',
            'allows_yellow' => 'boolean',
        ];
    }

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class, 'screening_id');
    }

    public function development_area(): BelongsTo
    {
        return $this->belongsTo(DevelopmentArea::class, 'development_area_id');
    }

    public function result_color(): BelongsTo
    {
        return $this->belongsTo(ResultColor::class, 'result_color_id');
    }
}
