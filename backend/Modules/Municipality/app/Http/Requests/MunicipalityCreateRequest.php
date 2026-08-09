<?php

namespace Modules\Municipality\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MunicipalityCreateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255',
            'state_id' => 'required|uuid',
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
}
