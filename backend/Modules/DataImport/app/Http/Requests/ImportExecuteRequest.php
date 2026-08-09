<?php

namespace Modules\DataImport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportExecuteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Authorization is handled by the route middleware (auth:sanctum + permission).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'El archivo es obligatorio.',
            'file.file' => 'El campo debe ser un archivo.',
            'file.mimes' => 'El archivo debe ser un Excel (.xlsx).',
            'file.max' => 'El archivo no puede superar los 10 MB.',
        ];
    }
}
