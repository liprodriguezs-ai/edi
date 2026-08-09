<?php

namespace Modules\Package\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PackageTypeUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('package_type', 'name')
                    ->where('tenant_id', tenancy()->tenant?->id)
                    ->whereNull('deleted_at')
                    ->ignore($this->route('package_type')),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
