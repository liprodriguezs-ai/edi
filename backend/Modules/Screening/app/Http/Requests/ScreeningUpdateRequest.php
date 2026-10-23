<?php

namespace Modules\Screening\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-SCR-003 — update is somatometry/notes ONLY; visit_id is immutable
 * after create and every result field is service-only.
 */
class ScreeningUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'weight_grams' => ['sometimes', 'nullable', 'integer', 'between:1500,40000'],
            'height_mm' => ['sometimes', 'nullable', 'integer', 'between:350,1400'],
            'head_circumference_mm' => ['sometimes', 'nullable', 'integer', 'between:250,700'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'weight_grams.between' => 'El peso está fuera del rango plausible.',
            'height_mm.between' => 'La talla está fuera del rango plausible.',
            'head_circumference_mm.between' => 'El perímetro cefálico está fuera del rango plausible.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
