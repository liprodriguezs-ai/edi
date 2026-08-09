<?php

namespace Modules\Treasury\Http\Requests;

use Illuminate\Validation\Rule;
use Orion\Http\Requests\Request;

/**
 * Strict update surface (§9.6 r1): only COSMETIC fields are editable after
 * registration. Money, account, status and period fields are immutable — to
 * change them, void the expense and register it again (void + recreate).
 * Sending any immutable field returns a clean 422 instead of silently
 * desynchronizing bank_account.current_balance.
 */
class ExpenseUpdateRequest extends Request
{
    public function rules(): array
    {
        return [
            // Cosmetic, editable fields.
            'concept' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'expense_category_id' => ['sometimes', 'required', 'uuid', Rule::exists('expense_category', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'beneficiary_type_id' => ['sometimes', 'required', 'uuid', 'exists:beneficiary_type,id'],
            'employee_id' => ['nullable', 'uuid', Rule::exists('collaborator', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'provider_id' => ['nullable', 'uuid', Rule::exists('provider', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'beneficiary_text' => ['nullable', 'string', 'max:255'],

            // Immutable after registration (§9.6 r1/r3): void + recreate instead.
            'amount' => ['prohibited'],
            'bank_account_id' => ['prohibited'],
            'expense_status_id' => ['prohibited'],
            'void_reason' => ['prohibited'],
            'paid_at' => ['prohibited'],
            'year' => ['prohibited'],
            'month' => ['prohibited'],
            'fortnight' => ['prohibited'],
            'payment_method_id' => ['prohibited'],
            'community_project_id' => ['prohibited'],
            'reference' => ['prohibited'],
            'authorized_by_user_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        $voidAndRecreate = 'Este campo no se puede editar; anula el egreso y regístralo de nuevo.';

        return [
            'amount.prohibited' => $voidAndRecreate,
            'bank_account_id.prohibited' => $voidAndRecreate,
            'expense_status_id.prohibited' => 'El estado del egreso solo cambia vía POST /expense/{id}/void.',
            'void_reason.prohibited' => 'El motivo de anulación solo se registra vía POST /expense/{id}/void.',
            'paid_at.prohibited' => $voidAndRecreate,
            'year.prohibited' => $voidAndRecreate,
            'month.prohibited' => $voidAndRecreate,
            'fortnight.prohibited' => $voidAndRecreate,
            'payment_method_id.prohibited' => $voidAndRecreate,
            'community_project_id.prohibited' => $voidAndRecreate,
            'reference.prohibited' => $voidAndRecreate,
            'authorized_by_user_id.prohibited' => $voidAndRecreate,
        ];
    }
}
