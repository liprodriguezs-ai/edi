<?php

namespace Modules\Nutrition\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ChildRecord\Models\Sex;
use QCH\Core\Models\BaseModel;

/**
 * Central WHO weight-for-height reference row (S5), grams/millimetres. Immutable
 * reference data, loaded by NutritionSeeder.
 */
class WeightForHeightReference extends BaseModel
{
    protected $table = 'weight_for_height_reference';

    protected $fillable = [
        'sex_id',
        'band',
        'height_mm',
        'sd_minus3',
        'sd_minus2',
        'sd_minus1',
        'median',
        'sd_plus1',
        'sd_plus2',
        'sd_plus3',
    ];

    protected function casts(): array
    {
        return [
            'height_mm' => 'integer',
            'sd_minus3' => 'integer',
            'sd_minus2' => 'integer',
            'sd_minus1' => 'integer',
            'median' => 'integer',
            'sd_plus1' => 'integer',
            'sd_plus2' => 'integer',
            'sd_plus3' => 'integer',
        ];
    }

    public function sex(): BelongsTo
    {
        return $this->belongsTo(Sex::class, 'sex_id');
    }
}
