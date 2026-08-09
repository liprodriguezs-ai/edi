<?php

namespace Modules\Providers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de actualización para el catálogo tenant-scoped "provider_service_type".
 */
class ProviderServiceTypeUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
