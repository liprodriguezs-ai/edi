<?php

namespace Modules\SubscriptionPlan\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BillingPackageUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Key is editable but stays a unique lowercase slug; ignore the row
            // being updated (Orion resolves {billing_package} to the raw id).
            'key' => [
                'sometimes',
                'string',
                'max:255',
                'regex:/^[a-z][a-z0-9_-]*$/',
                Rule::unique('billing_package', 'key')->ignore($this->route('billing_package')),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_public' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'suggested_unit_min' => ['nullable', 'integer', 'min:1'],
            'suggested_unit_max' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Enforce suggested_unit_min <= suggested_unit_max only when BOTH are present.
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
