<?php

namespace Modules\AmenityReservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Collections\Support\MoneyLimit;

/**
 * POST amenity_reservation/{id}/surcharges (S9). Authorization (board/admin
 * only — update.amenity_reservation) is enforced by the controller before
 * this validates; the FSM gate (reservation must be CONFIRMED or COMPLETED)
 * lives in AmenitySurchargeService.
 */
class AmenityReservationSurchargeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // charge_concept is TENANT-scoped → exists must filter by tenant + active.
            'charge_concept_id' => [
                'required',
                'uuid',
                Rule::exists('charge_concept', 'id')
                    ->where('tenant_id', tenant('id'))
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'amount' => ['required', 'integer', 'min:1', 'max:'.MoneyLimit::MAX_MINOR_UNITS],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
