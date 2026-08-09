<?php

namespace Modules\AmenityReservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST amenity_reservation/{id}/reject (S7). Authorization (board/admin
 * only) is enforced by the controller before this validates; the FSM
 * transition guard (must be PENDING_APPROVAL) lives in ReservationService.
 */
class AmenityReservationRejectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'rejected_reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
