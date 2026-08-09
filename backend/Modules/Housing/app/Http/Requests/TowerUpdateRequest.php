<?php

namespace Modules\Housing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TowerUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        $towerId = $this->route('tower') ?? $this->route('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('tower', 'name')->where('tenant_id', tenant('id'))->ignore($towerId)],
            'floors_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'section_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('section', 'id')->where('tenant_id', tenant('id'))],
            'order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
