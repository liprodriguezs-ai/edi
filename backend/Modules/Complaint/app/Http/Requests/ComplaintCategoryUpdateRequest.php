<?php

namespace Modules\Complaint\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ComplaintCategoryUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('complaint_category', 'name')
                    ->where('tenant_id', tenancy()->tenant?->id)
                    ->whereNull('deleted_at')
                    ->ignore($this->route('complaint_category')),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
