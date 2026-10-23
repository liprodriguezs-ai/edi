<?php

namespace Modules\Screening\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ChildRecord\Models\Child;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Confirmatory test (e.g. Battelle) applied after a red completed result
 * (design D20, REQ-SCR-014).
 */
#[ObservedBy([GlobalObserver::class])]
class ConfirmatoryTest extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'confirmatory_test';

    protected $fillable = [
        'screening_id',
        'child_id',
        'test_name',
        'applied_at',
        'confirmatory_test_result_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'applied_at' => 'date',
        ];
    }

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class, 'screening_id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'child_id');
    }

    public function confirmatory_test_result(): BelongsTo
    {
        return $this->belongsTo(ConfirmatoryTestResult::class, 'confirmatory_test_result_id');
    }
}
