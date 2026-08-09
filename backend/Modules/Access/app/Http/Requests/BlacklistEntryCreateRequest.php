<?php

namespace Modules\Access\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de alta para "blacklist_entry". Sin esto, un body incompleto
 * reventaba con un 500 (NOT NULL en blacklist_type_id/value). Columnas
 * obligatorias: blacklist_type_id y value.
 */
class BlacklistEntryCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'blacklist_type_id' => ['required', 'uuid', Rule::exists('blacklist_type', 'id')],
            'value' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'blacklist_type_id.required' => 'El tipo de lista negra es obligatorio.',
            'value.required' => 'El valor a bloquear es obligatorio.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
