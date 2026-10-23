<?php

namespace Modules\Nutrition\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use QCH\Core\Support\TenantTime;

/**
 * S5 -- inputs of a measurement (also used by the classify preview). Status
 * ids and age are NEVER accepted: they are not in these rules, so they never
 * reach validated().
 */
class NutritionalAssessmentClassifyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'child_id' => [
                'required',
                'uuid',
                Rule::exists('child', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at'),
            ],
            'measured_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.TenantTime::today()],
            'weight_grams' => ['nullable', 'integer', 'between:500,40000', 'required_without:height_mm'],
            'height_mm' => ['nullable', 'integer', 'between:350,1400', 'required_without:weight_grams'],
        ];
    }

    public function messages(): array
    {
        return [
            'child_id.required' => 'El niño es requerido.',
            'child_id.exists' => 'El niño no existe.',
            'measured_on.required' => 'La fecha de medición es requerida.',
            'measured_on.date_format' => 'La fecha de medición no es válida.',
            'measured_on.before_or_equal' => 'La fecha de medición no puede ser futura.',
            'weight_grams.between' => 'El peso debe estar entre 0.5 y 40 kg.',
            'weight_grams.integer' => 'El peso debe expresarse en gramos.',
            'weight_grams.required_without' => 'Captura al menos el peso o la talla.',
            'height_mm.between' => 'La talla debe estar entre 35 y 140 cm.',
            'height_mm.integer' => 'La talla debe expresarse en milímetros.',
            'height_mm.required_without' => 'Captura al menos el peso o la talla.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
