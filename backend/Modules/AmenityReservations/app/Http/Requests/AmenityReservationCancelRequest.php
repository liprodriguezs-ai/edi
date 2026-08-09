<?php

namespace Modules\AmenityReservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST amenity_reservation/{id}/cancel (S7). Authorization (board/admin OR
 * the owning resident of the reservation's unit) is enforced by the
 * controller before this validates; the FSM transition guard (must be an
 * ACTIVE status) lives in ReservationService.
 */
class AmenityReservationCancelRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'cancellation_reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
