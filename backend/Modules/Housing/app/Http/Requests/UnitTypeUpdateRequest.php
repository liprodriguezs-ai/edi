<?php

namespace Modules\Housing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnitTypeUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        $unitTypeId = $this->route('unit_type') ?? $this->route('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('unit_type', 'name')->ignore($unitTypeId)],
            'order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
