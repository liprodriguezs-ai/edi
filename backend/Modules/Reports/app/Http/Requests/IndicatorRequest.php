<?php

namespace Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared query-string validation for the 4 indicator dashboard endpoints
 * (design §4). Every field is optional here -- IndicatorController fills in
 * the per-action defaults (current period, 12-month window, …).
 */
class IndicatorRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'period' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m'],
            'months' => ['nullable', 'integer', 'min:1', 'max:36'],
            'health_center_id' => [
                'nullable',
                'uuid',
                Rule::exists('health_center', 'id')->where('tenant_id', tenant('id')),
            ],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
