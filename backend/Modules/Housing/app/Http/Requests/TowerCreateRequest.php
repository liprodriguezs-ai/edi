<?php

namespace Modules\Housing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TowerCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('tower', 'name')->where('tenant_id', tenant('id'))],
            'floors_count' => ['nullable', 'integer', 'min:1'],
            'section_id' => ['nullable', 'uuid', Rule::exists('section', 'id')->where('tenant_id', tenant('id'))],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
