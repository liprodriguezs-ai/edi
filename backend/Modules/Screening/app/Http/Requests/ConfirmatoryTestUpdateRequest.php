<?php

namespace Modules\Screening\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use QCH\Core\Support\TenantTime;

class ConfirmatoryTestUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'test_name' => ['sometimes', 'string', 'max:255'],
            'applied_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.TenantTime::today()],
            'confirmatory_test_result_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('confirmatory_test_result', 'id')],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
