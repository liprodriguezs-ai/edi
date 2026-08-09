<?php

namespace Modules\Collections\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Charge update (spec §8.7.2.2-3): a charge is immutable once payments are applied,
 * and even before that only schedule fields may change — amounts/status corrections
 * happen by cancelling and recreating the charge. Everything financial is PROHIBITED
 * (findings collections-01 / sec-input-01).
 */
class ChargeUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'issued_at' => ['sometimes', 'required', 'date'],
            'due_date' => ['sometimes', 'required', 'date'],
            'grace_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],

            // Ledger/FSM columns — service-owned, never client-writable.
            'charge_batch_id' => ['prohibited'],
            'charge_concept_id' => ['prohibited'],
            'unit_id' => ['prohibited'],
            'expected_amount' => ['prohibited'],
            'paid_amount' => ['prohibited'],
            'charge_status_id' => ['prohibited'],
            'is_cancelled' => ['prohibited'],
            'cancellation_reason' => ['prohibited'],
            'discount_amount' => ['prohibited'],
            'discount_reason' => ['prohibited'],
            'discounted_by_user_id' => ['prohibited'],
            'community_project_id' => ['prohibited'],
            'amenity_reservation_id' => ['prohibited'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
