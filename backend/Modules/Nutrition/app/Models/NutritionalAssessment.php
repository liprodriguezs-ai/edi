<?php

namespace Modules\Nutrition\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ChildRecord\Models\Child;
use Modules\Screening\Models\Screening;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One somatometry measurement of a child with its WHO nutritional status
 * (S5). The statuses and age_months are NEVER client-writable: $fillable is
 * only `notes`; everything else is written via forceFill by
 * NutritionalAssessmentService.
 */
#[ObservedBy([GlobalObserver::class])]
class NutritionalAssessment extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'nutritional_assessment';

    protected $fillable = [
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'measured_on' => 'date:Y-m-d',
            'age_months' => 'integer',
            'weight_grams' => 'integer',
            'height_mm' => 'integer',
        ];
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'child_id');
    }

    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class, 'screening_id');
    }

    public function weight_for_height_status(): BelongsTo
    {
        return $this->belongsTo(NutritionalStatus::class, 'weight_for_height_status_id');
    }

    public function weight_for_age_status(): BelongsTo
    {
        return $this->belongsTo(NutritionalStatus::class, 'weight_for_age_status_id');
    }

    public function height_for_age_status(): BelongsTo
    {
        return $this->belongsTo(NutritionalStatus::class, 'height_for_age_status_id');
    }
}
