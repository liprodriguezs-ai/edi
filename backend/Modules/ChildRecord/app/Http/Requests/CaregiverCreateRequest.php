<?php

namespace Modules\ChildRecord\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use QCH\Core\Support\TenantTime;

class CaregiverCreateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name_paternal' => ['required', 'string', 'max:255'],
            'last_name_maternal' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:'.TenantTime::today()],
            'education_level_id' => [
                'required',
                'uuid',
                Rule::exists('education_level', 'id')->where('tenant_id', tenant('id')),
            ],
            'occupation_id' => [
                'required',
                'uuid',
                Rule::exists('occupation', 'id')->where('tenant_id', tenant('id')),
            ],
            'population_type_id' => ['required', 'uuid', Rule::exists('population_type', 'id')],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'has_social_program' => ['sometimes', 'boolean'],
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
