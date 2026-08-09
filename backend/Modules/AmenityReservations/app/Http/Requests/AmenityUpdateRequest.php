<?php

namespace Modules\AmenityReservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AmenityReservations\Models\Amenity;
use Modules\Collections\Services\TargetResolver;
use Modules\Collections\Support\MoneyLimit;

/**
 * Amenity update: same shape as create, `sometimes`. The has_cost/requires_deposit
 * conditionals resolve the EFFECTIVE value (request input, falling back to the
 * persisted row) so a partial PATCH that doesn't touch `has_cost` does not
 * spuriously require `cost_amount`/`bank_account_id` — mirrors
 * ChargePolicyUpdateRequest's routed-model resolution.
 */
class AmenityUpdateRequest extends FormRequest
{
    private ?Amenity $resolvedAmenity = null;

    private bool $resolvedAmenityLoaded = false;

    public function rules(): array
    {
        $money = ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.MoneyLimit::MAX_MINOR_UNITS];

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('amenity', 'name')
                    ->where('tenant_id', tenant('id'))
                    ->whereNull('deleted_at')
                    ->ignore($this->route('amenity') ?? $this->route('id')),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'restrictions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'capacity' => ['sometimes', 'required', 'integer', 'min:1'],
            'available_weekdays' => ['sometimes', 'required', 'array', 'min:1', 'max:7'],
            'available_weekdays.*' => ['integer', 'between:0,6', 'distinct'],
            'rules_text' => ['sometimes', 'nullable', 'string'],
            'out_of_service' => ['sometimes', 'boolean'],
            'out_of_service_reason' => ['sometimes', 'nullable', 'string', 'max:255'],

            'has_cost' => ['sometimes', 'boolean'],
            'cost_amount' => [...$money, Rule::requiredIf(fn (): bool => $this->effectiveHasCost())],

            'requires_deposit' => ['sometimes', 'boolean'],
            'deposit_amount' => [...$money, Rule::requiredIf(fn (): bool => $this->effectiveRequiresDeposit())],
            'deposit_due_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365', Rule::requiredIf(fn (): bool => $this->effectiveRequiresDeposit())],

            'bank_account_id' => [
                'sometimes', 'nullable', 'uuid',
                Rule::exists('bank_account', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at'),
                Rule::requiredIf(fn (): bool => $this->effectiveHasCost()),
            ],

            'requires_approval' => ['sometimes', 'boolean'],

            'reservation_mode_id' => ['sometimes', 'required', 'uuid', Rule::exists('reservation_mode', 'id')],

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

    /**
     * has_cost as it will be AFTER this request is applied: the submitted
     * value if present, otherwise the amenity's current persisted value.
     */
    private function effectiveHasCost(): bool
    {
        if ($this->has('has_cost')) {
            return $this->boolean('has_cost');
        }

        return (bool) $this->amenity()?->has_cost;
    }

    private function effectiveRequiresDeposit(): bool
    {
        if ($this->has('requires_deposit')) {
            return $this->boolean('requires_deposit');
        }

        return (bool) $this->amenity()?->requires_deposit;
    }

    /**
     * Memoized route-resolved amenity, reused across the conditionals above.
     */
    private function amenity(): ?Amenity
    {
        if (! $this->resolvedAmenityLoaded) {
            $this->resolvedAmenity = Amenity::find($this->route('amenity') ?? $this->route('id'));
            $this->resolvedAmenityLoaded = true;
        }

        return $this->resolvedAmenity;
    }

    public function authorize(): bool
    {
        return true;
    }
}
