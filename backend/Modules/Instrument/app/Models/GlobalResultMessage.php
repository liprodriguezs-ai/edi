<?php

namespace Modules\Instrument\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Instrument\Database\Factories\GlobalResultMessageFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Displayable outcome of a global rule (D4/D17): headline + referral
 * bullets, verbatim Spanish UI data.
 */
#[ObservedBy([GlobalObserver::class])]
class GlobalResultMessage extends BaseModel
{
    use HasFactory;

    protected $table = 'global_result_message';

    public const KEY_RED = 'red';

    public const KEY_YELLOW_DEVELOPMENT = 'yellow_development';

    public const KEY_YELLOW_SOCIAL = 'yellow_social';

    public const KEY_GREEN = 'green';

    protected $fillable = [
        'id',
        'instrument_version_id',
        'key',
        'result_color_id',
        'headline',
        'referral_items',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'referral_items' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(InstrumentVersion::class, 'instrument_version_id');
    }

    public function result_color(): BelongsTo
    {
        return $this->belongsTo(ResultColor::class, 'result_color_id');
    }

    protected static function newFactory(): GlobalResultMessageFactory
    {
        return GlobalResultMessageFactory::new();
    }
}
