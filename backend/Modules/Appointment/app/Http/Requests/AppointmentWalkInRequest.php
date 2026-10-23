<?php

namespace Modules\Appointment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppointmentWalkInRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'child_id' => ['required', 'uuid', Rule::exists('child', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'practitioner_id' => ['required', 'uuid', Rule::exists('practitioner_profile', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'health_center_id' => ['nullable', 'uuid', Rule::exists('health_center', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'appointment_type_id' => ['required', 'uuid', Rule::exists('appointment_type', 'id')->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'practitioner_id.required' => 'Selecciona al profesional que atenderá al niño.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
