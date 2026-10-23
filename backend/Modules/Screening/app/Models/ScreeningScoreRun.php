<?php

namespace Modules\Screening\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Instrument\Models\ResultColor;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Immutable history of every engine execution over a screening (design
 * D7/D8/D9). Rows are never updated or deleted; only authoritative runs
 * (is_authoritative = true) update the screening header (REQ-SCR-006/010).
 */
#[ObservedBy([GlobalObserver::class])]
class ScreeningScoreRun extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'screening_score_run';

    public const TRIGGER_INITIAL = 'initial';

    public const TRIGGER_RESCORE = 'rescore';

    public const TRIGGER_SIMULATION = 'simulation';

    protected $fillable = [
        'screening_id',
        'instrument_version_id',
        'is_authoritative',
        'trigger',
        'reason',
        'engine_version',
        'rules_fingerprint',
        'input_payload',
        'result_payload',
        'result_color_id',
    ];

    protected function casts(): array
    {
        return [
            'is_authoritative' => 'boolean',
            'input_payload' => 'array',
            'result_payload' => 'array',
        ];
    }

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class, 'screening_id');
    }

    public function instrument_version(): BelongsTo
    {
        return $this->belongsTo(InstrumentVersion::class, 'instrument_version_id');
    }

    public function result_color(): BelongsTo
    {
        return $this->belongsTo(ResultColor::class, 'result_color_id');
    }
}
