<?php

namespace Modules\ChildRecord\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ChildRecord\Rules\Curp;

class ChildCreateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name_paternal' => ['required', 'string', 'max:255'],
            'last_name_maternal' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            'sex_id' => ['required', 'uuid', Rule::exists('sex', 'id')],
            'curp' => [
                'nullable',
                'string',
                'size:18',
                new Curp,
                Rule::unique('child', 'curp')
                    ->where('tenant_id', tenant('id'))
                    ->whereNull('deleted_at'),
            ],
            'external_id' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('child', 'external_id')
                    ->where('tenant_id', tenant('id'))
                    ->whereNull('deleted_at'),
            ],
            'health_center_id' => [
                'required',
                'uuid',
                Rule::exists('health_center', 'id')->where('tenant_id', tenant('id')),
            ],
            'is_schooled' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * Normalize curp to uppercase/trimmed before validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('curp') && $this->input('curp') !== null) {
            $this->merge(['curp' => strtoupper(trim((string) $this->input('curp')))]);
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
