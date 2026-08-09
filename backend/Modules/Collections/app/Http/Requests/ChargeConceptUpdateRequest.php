<?php

namespace Modules\Collections\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Charge concept update (spec §8.3.2). Identity-only fields; the tenant-scoped
 * code uniqueness ignores the concept being edited.
 */
class ChargeConceptUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => [
                'sometimes', 'required', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/',
                Rule::unique('charge_concept', 'code')
                    ->where('tenant_id', tenant('id'))
                    ->whereNull('deleted_at')
                    ->ignore($this->route('charge_concept') ?? $this->route('id')),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // charge_concept_category is TENANT-scoped → exists must be scoped per tenant
            // (Rule::exists ignores TenantScope) so a foreign cluster's category cannot be referenced.
            'charge_concept_category_id' => ['sometimes', 'required', 'uuid', Rule::exists('charge_concept_category', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
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
