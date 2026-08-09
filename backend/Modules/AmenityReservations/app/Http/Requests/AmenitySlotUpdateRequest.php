<?php

namespace Modules\AmenityReservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Slot update: same shape as create, `sometimes`. `amenity_id` is immutable
 * (the owning amenity is the slot's identity) — delete and recreate to move
 * a slot to a different amenity, mirrors ChargePolicy's charge_concept_id lock.
 */
class AmenitySlotUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amenity_id' => ['prohibited'],
            'weekday' => ['sometimes', 'required', 'integer', 'between:0,6'],
            'start_time' => ['sometimes', 'required', 'date_format:H:i:s,H:i'],
            'end_time' => ['sometimes', 'required', 'date_format:H:i:s,H:i', 'after:start_time'],
            'capacity_override' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'amenity_id.prohibited' => 'La amenidad de un horario no se puede cambiar.',
            'end_time.after' => 'La hora de fin debe ser posterior a la hora de inicio.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
