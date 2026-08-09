<?php

namespace Modules\AmenityReservations\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\ReservationMode;

/**
 * Reservation creation (S6). Light shape/exists validation here — the
 * domain rules (availability, unit-membership authorization, rules
 * acceptance, double-booking/capacity, initial FSM status) are ALL owned by
 * ReservationService::create(), which is the authoritative source even when
 * called outside this HTTP layer. slot_id required-for-by_slot /
 * prohibited-for-by_day is cross-field (depends on the resolved amenity's
 * mode), so it lives in withValidator() — mirrors
 * Collections\ChargeBatchCreateRequest.
 */
class AmenityReservationCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // amenity is TENANT-scoped → exists must be scoped per tenant.
            'amenity_id' => ['required', 'uuid', Rule::exists('amenity', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            // unit is TENANT-scoped too — the single shared DB means the FK alone
            // does not stop a payload pointing at another cluster's unit.
            'unit_id' => ['required', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'reservation_date' => ['required', 'date_format:Y-m-d'],
            'slot_id' => ['nullable', 'uuid', Rule::exists('amenity_slot', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'rules_accepted' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'amenity_id.exists' => 'La amenidad seleccionada no existe en este condominio.',
            'unit_id.exists' => 'La unidad seleccionada no existe en este condominio.',
            'slot_id.exists' => 'El horario seleccionado no existe en este condominio.',
        ];
    }

    /**
     * slot_id required for by_slot amenities, prohibited for by_day ones.
     * Re-fetched here (not via a validated model instance) because the
     * cross-field check runs against the RAW input before the exists rule
     * above is guaranteed to have passed.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $amenityId = $this->input('amenity_id');

            if (! is_string($amenityId)) {
                return;
            }

            $amenity = Amenity::find($amenityId);

            if ($amenity === null) {
                return; // already flagged by the amenity_id.exists rule
            }

            $isBySlot = $amenity->reservation_mode_id === ReservationMode::BY_SLOT;

            if ($isBySlot && ! $this->filled('slot_id')) {
                $validator->errors()->add('slot_id', 'Debes elegir un horario para esta amenidad.');
            }

            if (! $isBySlot && $this->filled('slot_id')) {
                $validator->errors()->add('slot_id', 'Esta amenidad no opera por horarios.');
            }
        });
    }

    public function authorize(): bool
    {
        return true;
    }
}
