<?php

namespace Modules\ChildRecord\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChildCaregiverCreateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'child_id' => [
                'required',
                'uuid',
                Rule::exists('child', 'id')->where('tenant_id', tenant('id')),
                Rule::unique('child_caregiver', 'child_id')
                    ->where('tenant_id', tenant('id'))
                    ->where('caregiver_id', $this->input('caregiver_id'))
                    ->whereNull('deleted_at'),
            ],
            'caregiver_id' => [
                'required',
                'uuid',
                Rule::exists('caregiver', 'id')->where('tenant_id', tenant('id')),
            ],
            'caregiver_relationship_id' => [
                'required',
                'uuid',
                Rule::exists('caregiver_relationship', 'id')->where('tenant_id', tenant('id')),
            ],
            'is_primary' => ['sometimes', 'boolean'],
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
