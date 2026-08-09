<?php

namespace Modules\Housing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnitFeatureUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        $unitFeatureId = $this->route('unit_feature') ?? $this->route('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('unit_feature', 'name')->where('tenant_id', tenant('id'))->ignore($unitFeatureId)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
