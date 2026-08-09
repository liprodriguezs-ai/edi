<?php

namespace Modules\Setting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Setting\Rules\SettingValueRule;

class SettingCreateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('setting', 'slug')->where('tenant_id', tenant('id'))],
            'value' => ['nullable', new SettingValueRule((string) $this->input('type'))],
            'type' => ['required', 'string', 'in:boolean,string,integer,json'],
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
