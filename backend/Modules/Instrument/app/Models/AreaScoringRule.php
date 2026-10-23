<?php

namespace Modules\Instrument\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Instrument\Database\Factories\AreaScoringRuleFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Semaphore thresholds for one (group, area) (D4/D12). No legacy_id: this
 * table is entirely hand-derived (design §5.1), not extracted from the dump.
 */
#[ObservedBy([GlobalObserver::class])]
class AreaScoringRule extends BaseModel
{
    use HasFactory;

    protected $table = 'area_scoring_rule';

    public const SOURCE_LEGACY_JS = 'legacy_js';

    public const SOURCE_LEGACY_JS_COMMENTED = 'legacy_js_commented';

    public const SOURCE_EXTRAPOLATED = 'extrapolated';

    public const SOURCE_ORACLE_CONFIRMED = 'oracle_confirmed';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'id',
        'instrument_version_id',
        'age_group_id',
        'development_area_id',
        'item_count',
        'red_threshold',
        'green_threshold',
        'allows_yellow',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'item_count' => 'integer',
            'red_threshold' => 'integer',
            'green_threshold' => 'integer',
            'allows_yellow' => 'boolean',
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

    protected static function newFactory(): AreaScoringRuleFactory
    {
        return AreaScoringRuleFactory::new();
    }
}
