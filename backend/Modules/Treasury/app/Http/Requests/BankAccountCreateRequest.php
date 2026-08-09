<?php

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BankAccountCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('bank_account', 'name')->where('tenant_id', tenant('id'))],
            'bank_account_type_id' => ['required', 'uuid', Rule::exists('bank_account_type', 'id')],
            'bank_account_purpose_id' => ['nullable', 'uuid', Rule::exists('bank_account_purpose', 'id')],
            'bank' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:255'],
            'clabe' => ['nullable', 'string', 'max:18', Rule::unique('bank_account', 'clabe')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'card_number' => ['nullable', 'string', 'regex:/^\d{13,19}$/'],
            'currency' => ['nullable', 'string', 'size:3'],
            'initial_balance' => ['nullable', 'integer', 'min:0'],
            'is_primary' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'card_number.regex' => 'El número de tarjeta debe tener entre 13 y 19 dígitos, sin espacios ni guiones.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
