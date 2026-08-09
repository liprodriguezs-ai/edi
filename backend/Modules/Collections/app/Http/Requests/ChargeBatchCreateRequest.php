<?php

namespace Modules\Collections\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Services\TargetResolver;

/**
 * Charge batch creation (spec §8.4.2): a batch is generated from a charge_policy.
 * target_type is a closed set — an unknown value must fail validation, never silently
 * fall back to ALL units. Lifecycle columns (status, progress) are service-owned:
 * batches are born DRAFT and only transition via activate/cancel endpoints.
 */
class ChargeBatchCreateRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            // charge_policy is TENANT-scoped → exists must filter by tenant.
            'charge_policy_id' => ['required', 'uuid', Rule::exists('charge_policy', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'target_type' => ['required', 'string', Rule::in(TargetResolver::TARGET_TYPES)],
            'target_selector' => ['nullable', 'array'],
            'issued_at' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issued_at'],
            'activate' => ['nullable', 'boolean'],

            // Lifecycle columns — service-owned, never client-writable.
            'charge_batch_status_id' => ['prohibited'],
            'progress_pct' => ['prohibited'],
        ];

        // Idempotency guard mirrored from the DB partial unique index
        // (tenant_id, charge_policy_id, year, month) WHERE month IS NOT NULL:
        // surface a clean 422 instead of a raw 500 when a monthly batch already exists.
        if ($this->filled('month')) {
            $rules['month'][] = Rule::unique('charge_batch')
                ->where('tenant_id', tenant('id'))
                ->where('charge_policy_id', $this->input('charge_policy_id'))
                ->where('year', $this->input('year'))
                ->whereNull('deleted_at');
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'target_type.in' => 'El alcance del cobro no es válido.',
            'charge_batch_status_id.prohibited' => 'El estado del cobro lo administra el sistema (usa activar/cancelar).',
            'month.unique' => 'Ya existe un cobro para esta política en ese periodo (mes y año).',
        ];
    }

    /**
     * The selected policy AND its parent concept must be active to generate new charges.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $policyId = $this->input('charge_policy_id');
            if (! $policyId) {
                return;
            }

            $policy = ChargePolicy::with('concept')->find($policyId);
            if (! $policy) {
                return;
            }

            if (! $policy->is_active || ! ($policy->concept?->is_active)) {
                $validator->errors()->add(
                    'charge_policy_id',
                    'La política o su concepto están inactivos; no se pueden generar cobros.',
                );
            }
        });
    }

    public function authorize(): bool
    {
        return true;
    }
}
