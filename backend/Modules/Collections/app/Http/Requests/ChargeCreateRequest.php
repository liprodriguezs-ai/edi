<?php

namespace Modules\Collections\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Collections\Support\MoneyLimit;

/**
 * Ad-hoc charge creation (spec §8.7.2.1 "manualmente"). Ledger/FSM columns are
 * PROHIBITED: paid_amount, charge_status_id, discounts and cancellation state are
 * written exclusively by the Collections services — never via mass assignment
 * (findings collections-01 / sec-input-01). Batch charges go through
 * charge-batch/{id}/activate, so charge_batch_id is prohibited here too.
 */
class ChargeCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // charge_concept is TENANT-scoped → exists must filter by tenant.
            'charge_concept_id' => ['required', 'uuid', Rule::exists('charge_concept', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'unit_id' => ['required', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'expected_amount' => ['required', 'integer', 'min:1', 'max:'.MoneyLimit::MAX_MINOR_UNITS],
            'issued_at' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issued_at'],
            'grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            // Ledger/FSM columns — service-owned, never client-writable.
            'charge_batch_id' => ['prohibited'],
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
