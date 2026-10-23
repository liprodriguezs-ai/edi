<?php

namespace Modules\Nutrition\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ChildRecord\Models\Sex;
use QCH\Core\Models\BaseModel;

/**
 * Central WHO height-for-age reference row (S5), grams/millimetres. Immutable
 * reference data, loaded by NutritionSeeder.
 */
class HeightForAgeReference extends BaseModel
{
    protected $table = 'height_for_age_reference';

    protected $fillable = [
        'sex_id',
        'age_months',
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
            'age_months' => 'integer',
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
