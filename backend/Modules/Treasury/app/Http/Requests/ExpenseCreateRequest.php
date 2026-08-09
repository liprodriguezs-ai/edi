<?php

namespace Modules\Treasury\Http\Requests;

use Illuminate\Validation\Rule;
use Orion\Http\Requests\Request;

class ExpenseCreateRequest extends Request
{
    public function rules(): array
    {
        return [
            'concept' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'paid_at' => ['required', 'date'],
            'amount' => ['required', 'integer', 'min:1'],
            'expense_category_id' => ['required', 'uuid', Rule::exists('expense_category', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'beneficiary_type_id' => ['required', 'uuid', 'exists:beneficiary_type,id'],
            'employee_id' => ['nullable', 'uuid', Rule::exists('collaborator', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'provider_id' => ['nullable', 'uuid', Rule::exists('provider', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'beneficiary_text' => ['nullable', 'string', 'max:255'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'fortnight' => ['nullable', 'integer', 'min:1', 'max:2'],
            'payment_method_id' => ['required', 'uuid', 'exists:payment_method,id'],
            'bank_account_id' => ['required', 'uuid', Rule::exists('bank_account', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'reference' => ['nullable', 'string', 'max:255'],
            'community_project_id' => ['nullable', 'uuid', Rule::exists('community_project', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            // Central 'user' table (singular) — no tenant_id column, plain exists.
            'authorized_by_user_id' => ['nullable', 'uuid', Rule::exists('user', 'id')->whereNull('deleted_at')],
            'notes' => ['nullable', 'string'],
            'force' => ['nullable', 'boolean'], // Override duplicate detection
        ];
    }
}
