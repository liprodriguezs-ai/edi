<?php

namespace Modules\Clinic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HealthCenterUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'clues_code' => [
                'nullable',
                'string',
                'regex:/^[A-Za-z]{5}[0-9]{6}$/',
                Rule::unique('health_center', 'clues_code')
                    ->where('tenant_id', tenant('id'))
                    ->whereNull('deleted_at')
                    ->ignore($this->route('health_center')),
            ],
            'municipality_id' => ['sometimes', 'uuid', Rule::exists('municipality', 'id')],
            'address' => ['sometimes', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Normalize clues_code to uppercase before validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('clues_code') && $this->input('clues_code') !== null) {
            $this->merge(['clues_code' => strtoupper(trim((string) $this->input('clues_code')))]);
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
}
