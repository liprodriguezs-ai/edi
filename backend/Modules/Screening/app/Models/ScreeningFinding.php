<?php

namespace Modules\Screening\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One clinical trace finding persisted verbatim from the engine (design D7,
 * REQ-SCO-009).
 */
#[ObservedBy([GlobalObserver::class])]
class ScreeningFinding extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'screening_finding';

    protected $fillable = [
        'screening_id',
        'finding_type_id',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class, 'screening_id');
    }

    public function finding_type(): BelongsTo
    {
        return $this->belongsTo(FindingType::class, 'finding_type_id');
    }
}
