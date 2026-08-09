<?php

namespace Modules\Access\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de alta para el catálogo tenant-scoped "visitor_motive".
 * Sin esto, Orion no aplicaba reglas y un body sin "name" reventaba con un
 * 500 (NOT NULL). El nombre es único por tenant (unique tenant_id+name).
 */
class VisitorMotiveCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('visitor_motive', 'name')->where('tenant_id', tenant('id'))->whereNull('deleted_at'),
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
