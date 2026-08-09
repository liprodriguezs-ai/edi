<?php

namespace Modules\Housing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SectionUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        $sectionId = $this->route('section') ?? $this->route('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('section', 'name')->where('tenant_id', tenant('id'))->ignore($sectionId)],
            'order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
