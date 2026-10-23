<?php

namespace Modules\Appointment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppointmentRescheduleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date', 'after:now'],
            'practitioner_id' => ['sometimes', 'uuid', Rule::exists('practitioner_profile', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
        ];
    }

    public function messages(): array
    {
        return [
            'starts_at.after' => 'La cita debe moverse a una fecha y hora futura.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
