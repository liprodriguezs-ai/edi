<?php

namespace Modules\UserProfile\Http\Requests;

use App\Rules\MexicanRfc;
use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserProfileCreateRequest extends FormRequest
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
                Rule::unique('user_profile', 'user_id')->where('tenant_id', tenant('id')),
            ],
            'rfc' => ['nullable', 'string', 'max:13', new MexicanRfc],
            'curp' => ['nullable', 'string', 'max:18'],
            'birthdate' => ['nullable', 'date'],
            'address' => ['nullable', 'string'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30', new PhoneNumber],
            'notes' => ['nullable', 'string'],
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
