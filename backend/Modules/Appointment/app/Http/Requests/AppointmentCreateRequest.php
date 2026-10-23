<?php

namespace Modules\Appointment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppointmentCreateRequest extends FormRequest
{
    /**
     * Lifecycle fields (status, *_at stamps, visit_id, is_walk_in) are absent on
     * purpose: AppointmentLifecycleService derives them.
     */
    public function rules(): array
    {
        return [
            'child_id' => ['required', 'uuid', Rule::exists('child', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'practitioner_id' => ['required', 'uuid', Rule::exists('practitioner_profile', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'health_center_id' => ['nullable', 'uuid', Rule::exists('health_center', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'appointment_type_id' => ['required', 'uuid', Rule::exists('appointment_type', 'id')->whereNull('deleted_at')],
            'starts_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'follow_up_id' => ['nullable', 'uuid', Rule::exists('follow_up', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'starts_at.after' => 'La cita debe agendarse en una fecha y hora futura.',
            'practitioner_id.required' => 'Selecciona al profesional que atenderá la cita.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
