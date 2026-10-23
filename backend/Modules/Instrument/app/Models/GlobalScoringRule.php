<?php

namespace Modules\Instrument\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Instrument\Database\Factories\GlobalScoringRuleFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Ordered global rule of an official group (D4/D16). condition is jsonb in
 * the closed grammar of design §5.4. First-match-wins by ascending sequence;
 * the last rule of every group is the mandatory catch-all {"all":[]}.
 */
#[ObservedBy([GlobalObserver::class])]
class GlobalScoringRule extends BaseModel
{
    use HasFactory;

    protected $table = 'global_scoring_rule';

    public const SOURCE_LEGACY_JS = 'legacy_js';

    public const SOURCE_ORACLE_CONFIRMED = 'oracle_confirmed';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'id',
        'instrument_version_id',
        'age_group_id',
        'sequence',
        'condition',
        'result_color_id',
        'global_result_message_id',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'condition' => 'array',
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

    public function result_color(): BelongsTo
    {
        return $this->belongsTo(ResultColor::class, 'result_color_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(GlobalResultMessage::class, 'global_result_message_id');
    }

    protected static function newFactory(): GlobalScoringRuleFactory
    {
        return GlobalScoringRuleFactory::new();
    }
}
