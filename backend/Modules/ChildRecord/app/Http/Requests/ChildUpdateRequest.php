<?php

namespace Modules\ChildRecord\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ChildRecord\Rules\Curp;
use QCH\Core\Support\TenantTime;

class ChildUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name_paternal' => ['sometimes', 'string', 'max:255'],
            'last_name_maternal' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['sometimes', 'date', 'before_or_equal:'.TenantTime::today()],
            'sex_id' => ['sometimes', 'uuid', Rule::exists('sex', 'id')],
            'curp' => [
                'nullable',
                'string',
                'size:18',
                new Curp,
                Rule::unique('child', 'curp')
                    ->where('tenant_id', tenant('id'))
                    ->whereNull('deleted_at')
                    ->ignore($this->route('child')),
            ],
            'external_id' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('child', 'external_id')
                    ->where('tenant_id', tenant('id'))
                    ->whereNull('deleted_at')
                    ->ignore($this->route('child')),
            ],
            'health_center_id' => [
                'sometimes',
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
