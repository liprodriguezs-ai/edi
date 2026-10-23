<?php

namespace Modules\Screening\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use QCH\Core\Support\TenantTime;

/**
 * REQ-SCR-014 — screening_id eligibility (completed + red) is enforced by
 * ScreeningController-adjacent service logic, not here; this only validates
 * shape and tenant-scoped references.
 */
class ConfirmatoryTestCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'screening_id' => [
                'required',
                'uuid',
                Rule::exists('screening', 'id')->where('tenant_id', tenant('id')),
                Rule::unique('confirmatory_test', 'screening_id')
                    ->where('tenant_id', tenant('id'))
                    ->whereNull('deleted_at'),
            ],
            'test_name' => ['required', 'string', 'max:255'],
            'applied_at' => ['nullable', 'date', 'before_or_equal:'.TenantTime::today()],
            'confirmatory_test_result_id' => ['nullable', 'uuid', Rule::exists('confirmatory_test_result', 'id')],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
