<?php

namespace Modules\Housing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnitUserUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'unit_id' => ['sometimes', 'required', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id'))],
            'user_id' => ['sometimes', 'required', 'uuid', Rule::exists('user', 'id')],
            'unit_relationship_id' => ['sometimes', 'required', 'uuid', Rule::exists('unit_relationship', 'id')],
            'is_billing_owner' => ['sometimes', 'boolean'],
            'is_legal_representative' => ['sometimes', 'boolean'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
