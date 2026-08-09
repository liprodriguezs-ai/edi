<?php

namespace Modules\ChildRecord\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ChildRecord\Database\Factories\BirthRecordFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Perinatal record of a child (1:1). Every clinical field is nullable; NULL
 * means "se ignora" (D7/D-14) and MUST NOT be cast/defaulted to false/0.
 */
#[ObservedBy([GlobalObserver::class])]
class BirthRecord extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'birth_record';

    protected $fillable = [
        'child_id',
        'gestational_age_weeks',
        'birth_weight_grams',
        'birth_length_mm',
        'prenatal_visit_count',
        'had_pregnancy_complication',
        'had_delivery_complication',
        'nicu_days',
        'mother_age_at_birth_years',
        'early_breastfeeding',
        'breastfeeding_months',
    ];

    protected function casts(): array
    {
        return [
            'gestational_age_weeks' => 'integer',
            'birth_weight_grams' => 'integer',
            'birth_length_mm' => 'integer',
            'prenatal_visit_count' => 'integer',
            'had_pregnancy_complication' => 'boolean',
            'had_delivery_complication' => 'boolean',
            'nicu_days' => 'integer',
            'mother_age_at_birth_years' => 'integer',
            'early_breastfeeding' => 'boolean',
            'breastfeeding_months' => 'integer',
        ];
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'child_id');
    }

    protected static function newFactory(): BirthRecordFactory
    {
        return BirthRecordFactory::new();
    }
}
