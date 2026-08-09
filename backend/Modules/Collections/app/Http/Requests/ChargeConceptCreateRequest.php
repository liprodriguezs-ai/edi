<?php

namespace Modules\Collections\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Charge concept creation (spec §8.3.2): the IDENTITY of a billable item.
 * Uppercase code unique per tenant + name + category. The charge BEHAVIOR
 * (amount, periodicity, grace, validity) lives in charge_policy, not here.
 */
class ChargeConceptCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/',
                // charge_concept is TENANT-scoped → unique per tenant (alive rows only).
                Rule::unique('charge_concept', 'code')->where('tenant_id', tenant('id'))->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            // charge_concept_category is TENANT-scoped → exists must be scoped per tenant
            // (Rule::exists ignores TenantScope) so a foreign cluster's category cannot be referenced.
            'charge_concept_category_id' => ['required', 'uuid', Rule::exists('charge_concept_category', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'La clave solo admite mayúsculas, números y guiones bajos (ej. MANT_MENSUAL).',
            'code.unique' => 'Ya existe un concepto con esa clave en el condominio.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
