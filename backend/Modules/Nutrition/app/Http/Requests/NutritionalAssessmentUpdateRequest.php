<?php

namespace Modules\Nutrition\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use QCH\Core\Support\TenantTime;

/**
 * S5 -- the child of a measurement never changes; status ids and age are
 * never accepted (service-derived on every save).
 */
class NutritionalAssessmentUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'measured_on' => ['sometimes', 'required', 'date_format:Y-m-d', 'before_or_equal:'.TenantTime::today()],
            'weight_grams' => ['sometimes', 'nullable', 'integer', 'between:500,40000'],
            'height_mm' => ['sometimes', 'nullable', 'integer', 'between:350,1400'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return (new NutritionalAssessmentClassifyRequest)->messages();
    }

    public function authorize(): bool
    {
        return true;
    }
}
