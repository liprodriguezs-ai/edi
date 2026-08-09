<?php

namespace Modules\AmenityReservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Collections\Services\TargetResolver;
use Modules\Collections\Support\MoneyLimit;

/**
 * Amenity creation. `has_cost` drives `cost_amount` + `bank_account_id`
 * (the destination account for reservation payments) being required;
 * `requires_deposit` drives `deposit_amount` + `deposit_due_days`.
 */
class AmenityCreateRequest extends FormRequest
{
    public function rules(): array
    {
        $money = ['nullable', 'integer', 'min:1', 'max:'.MoneyLimit::MAX_MINOR_UNITS];

        return [
            'name' => [
                'required', 'string', 'max:255',
                // amenity is TENANT-scoped → unique per tenant (alive rows only).
                Rule::unique('amenity', 'name')->where('tenant_id', tenant('id'))->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'restrictions' => ['nullable', 'string', 'max:2000'],
            'capacity' => ['required', 'integer', 'min:1'],
            'available_weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'available_weekdays.*' => ['integer', 'between:0,6', 'distinct'],
            'rules_text' => ['nullable', 'string'],
            'out_of_service' => ['nullable', 'boolean'],
            'out_of_service_reason' => ['nullable', 'string', 'max:255'],

            'has_cost' => ['nullable', 'boolean'],
            'cost_amount' => [...$money, Rule::requiredIf(fn (): bool => $this->boolean('has_cost'))],

            'requires_deposit' => ['nullable', 'boolean'],
            'deposit_amount' => [...$money, Rule::requiredIf(fn (): bool => $this->boolean('requires_deposit'))],
            'deposit_due_days' => ['nullable', 'integer', 'min:0', 'max:365', Rule::requiredIf(fn (): bool => $this->boolean('requires_deposit'))],

            // bank_account is TENANT-scoped → exists must be scoped per tenant
            // (Rule::exists ignores TenantScope) so a foreign cluster's account
            // cannot be referenced as the reservation-payment destination.
            'bank_account_id' => [
                'nullable', 'uuid',
                Rule::exists('bank_account', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at'),
                Rule::requiredIf(fn (): bool => $this->boolean('has_cost')),
            ],

            'requires_approval' => ['nullable', 'boolean'],

            // reservation_mode is a CENTRAL catalog (no tenant_id)
            // → no tenant filter on the exists rule.
            'reservation_mode_id' => ['required', 'uuid', Rule::exists('reservation_mode', 'id')],

            // Visibility target — who can see/reserve this amenity. Full
            // TargetResolver vocabulary (no 'board' concept for amenities,
            // unlike Chat's admin-facing target list).
            'target_type' => ['sometimes', 'nullable', 'string', Rule::in(TargetResolver::TARGET_TYPES)],
            'target_selector' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe una amenidad con ese nombre en el condominio.',
            'cost_amount.required_if' => 'El monto del costo es obligatorio cuando la amenidad tiene costo.',
            'bank_account_id.required_if' => 'La cuenta destino es obligatoria cuando la amenidad tiene costo.',
            'deposit_amount.required_if' => 'El monto del depósito es obligatorio cuando se requiere depósito.',
            'deposit_due_days.required_if' => 'Los días para liquidar el depósito son obligatorios cuando se requiere depósito.',
            'target_type.in' => 'El alcance de la amenidad no es válido.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
