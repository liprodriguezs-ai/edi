<?php

namespace Modules\ChildRecord\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BirthRecordUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * child_id is immutable after create (1:1 identity of the record).
     */
    public function rules(): array
    {
        return [
            'gestational_age_weeks' => ['nullable', 'integer', 'between:20,44'],
            'birth_weight_grams' => ['nullable', 'integer', 'between:400,7000'],
            'birth_length_mm' => ['nullable', 'integer', 'between:200,650'],
            'prenatal_visit_count' => ['nullable', 'integer', 'between:0,40'],
            'had_pregnancy_complication' => ['nullable', 'boolean'],
            'had_delivery_complication' => ['nullable', 'boolean'],
            'nicu_days' => ['nullable', 'integer', 'between:0,365'],
            'mother_age_at_birth_years' => ['nullable', 'integer', 'between:10,60'],
            'early_breastfeeding' => ['nullable', 'boolean'],
            'breastfeeding_months' => ['nullable', 'integer', 'between:0,71'],
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
}
