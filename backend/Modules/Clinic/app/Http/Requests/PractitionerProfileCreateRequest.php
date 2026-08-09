<?php

namespace Modules\Clinic\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PractitionerProfileCreateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'uuid',
                Rule::exists('user_tenant', 'user_id')->where('tenant_id', tenant('id')),
                Rule::unique('practitioner_profile', 'user_id')
                    ->where('tenant_id', tenant('id'))
                    ->whereNull('deleted_at'),
            ],
            'license_number' => ['required', 'string', 'max:20'],
            'specialty' => ['required', 'string', 'max:255'],
            'health_center_id' => [
                'required',
                'uuid',
                Rule::exists('health_center', 'id')->where('tenant_id', tenant('id')),
            ],
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
