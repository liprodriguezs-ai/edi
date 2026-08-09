<?php

namespace Modules\Housing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnitUserCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id'))],
            'user_id' => ['required', 'uuid', Rule::exists('user', 'id')],
            'unit_relationship_id' => ['required', 'uuid', Rule::exists('unit_relationship', 'id')],
            'is_billing_owner' => ['nullable', 'boolean'],
            'is_legal_representative' => ['nullable', 'boolean'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
