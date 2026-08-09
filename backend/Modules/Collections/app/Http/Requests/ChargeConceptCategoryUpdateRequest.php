<?php

namespace Modules\Collections\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de actualización para el catálogo tenant-scoped "charge_concept_category".
 * El nombre es único por tenant (unique tenant_id+name); sin esta regla, renombrar
 * una categoría a un nombre ya usado en el mismo tenant reventaba con un 500
 * (constraint) en vez de un 422 limpio. ->ignore() exime la propia fila editada.
 */
class ChargeConceptCategoryUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('charge_concept_category', 'name')
                    ->where('tenant_id', tenant('id'))
                    ->whereNull('deleted_at')
                    ->ignore($this->route('charge_concept_category')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'name.unique' => 'Ya existe un registro con ese nombre.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
