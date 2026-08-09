<?php

namespace Modules\AmenityReservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Blackout update: same shape as create, `sometimes`. `amenity_id` is
 * immutable — delete and recreate to move a blackout to a different amenity.
 */
class AmenityBlackoutUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amenity_id' => ['prohibited'],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'required', 'date', 'after_or_equal:start_date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'amenity_id.prohibited' => 'La amenidad de un bloqueo no se puede cambiar.',
            'end_date.after_or_equal' => 'La fecha de fin debe ser posterior o igual a la fecha de inicio.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
