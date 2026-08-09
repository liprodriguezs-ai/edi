<?php

namespace Modules\Access\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de actualización para el catálogo tenant-scoped "visitor_motive".
 */
class VisitorMotiveUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('visitor_motive', 'name')
                    ->where('tenant_id', tenant('id'))
                    ->ignore($this->route('visitor_motive'))
                    ->whereNull('deleted_at'),
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
