<?php

namespace Modules\SubscriptionPlan\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BillingPackageCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Machine key: lowercase slug, globally unique (central catalog — no
            // tenant scope). The DB unique includes soft-deleted rows, so the rule
            // is NOT scoped by deleted_at to mirror the constraint.
            'key' => ['required', 'string', 'max:255', 'regex:/^[a-z][a-z0-9_-]*$/', Rule::unique('billing_package', 'key')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_public' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'suggested_unit_min' => ['nullable', 'integer', 'min:1'],
            'suggested_unit_max' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Enforce suggested_unit_min <= suggested_unit_max only when BOTH are present,
     * so a partial payload does not trip a false comparison.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $min = $this->input('suggested_unit_min');
            $max = $this->input('suggested_unit_max');

            if ($min !== null && $max !== null && (int) $min > (int) $max) {
                $validator->errors()->add('suggested_unit_max', 'El máximo de unidades sugeridas debe ser mayor o igual al mínimo.');
            }
        });
    }

    public function authorize(): bool
    {
        return true;
    }
}
