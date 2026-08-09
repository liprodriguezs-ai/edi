<?php

namespace Modules\Announcements\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de alta para el catálogo tenant-scoped "announcement_type".
 * Sin esto, Orion no aplicaba reglas y un body sin "name" reventaba con un
 * 500 (NOT NULL). El nombre es único por tenant (unique tenant_id+name).
 */
class AnnouncementTypeCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('announcement_type', 'name')->where('tenant_id', tenant('id'))->whereNull('deleted_at'),
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
