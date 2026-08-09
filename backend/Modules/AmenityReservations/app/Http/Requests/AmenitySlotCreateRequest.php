<?php

namespace Modules\AmenityReservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fixed reservable time slot for an amenity in by_slot mode (§S4).
 */
class AmenitySlotCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // amenity is TENANT-scoped → exists must be scoped per tenant.
            'amenity_id' => ['required', 'uuid', Rule::exists('amenity', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'weekday' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i:s,H:i'],
            'end_time' => ['required', 'date_format:H:i:s,H:i', 'after:start_time'],
            'capacity_override' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_time.after' => 'La hora de fin debe ser posterior a la hora de inicio.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
