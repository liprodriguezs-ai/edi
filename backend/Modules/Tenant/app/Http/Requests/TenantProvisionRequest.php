<?php

namespace Modules\Tenant\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\Tenant\Models\Tenant;
use Modules\Tenant\Services\TenantProvisioningService;

/**
 * Validates the super-admin ALTA + billing provisioning of a organization. The
 * super-admin gate (create.tenant) lives in the controller constructor; this
 * request only shapes the body.
 *
 * Mode-dependent requirements:
 *  - manual_paid → `interval` (to compute the amount) and
 *                  payment_method ∈ {transfer, cash}.
 *  - card        → payment_method must be `card`.
 *
 * `modules` is optional in every mode — when absent the service grants the plan's
 * full module set (today: just the base).
 *
 * `plan_key` (a billing_package plan: basico..premium) is optional — when absent
 * the service resolves the plan from the unit band.
 */
class TenantProvisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $mode = $this->input('provision_mode');
        $needsPricing = $mode === TenantProvisioningService::MODE_MANUAL_PAID;

        return [
            // status is NOT accepted: the service derives it from the provision mode
            // (card → pendiente_pago, everything else → activo).
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'currency' => ['nullable', 'string', 'size:3'],
            'type' => ['nullable', 'string', Rule::in(['horizontal', 'vertical', 'mixed'])],
            'uses_ownership_share' => ['sometimes', 'boolean'],
            'payment_method' => ['required', 'string', Rule::in(Tenant::PAYMENT_METHODS)],
            'provision_mode' => ['required', 'string', Rule::in(TenantProvisioningService::MODES)],
            // Optional plan (basico..premium); the service resolves it from units when absent.
            'plan_key' => ['nullable', 'string', 'exists:billing_package,key'],
            'units' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'interval' => [$needsPricing ? 'required' : 'nullable', 'string', Rule::in(PlanModulePrice::INTERVALS)],
            // Optional everywhere: modules are no longer a user-facing choice, so an
            // absent array means "the whole plan" (resolved by the service).
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', 'exists:billable_module,key'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $mode = $this->input('provision_mode');
            $method = $this->input('payment_method');

            if ($mode === TenantProvisioningService::MODE_MANUAL_PAID
                && ! in_array($method, [Tenant::PAYMENT_METHOD_TRANSFER, Tenant::PAYMENT_METHOD_CASH], true)) {
                $validator->errors()->add(
                    'payment_method',
                    'Un pago manual registrado requiere método de pago transferencia o efectivo.'
                );
            }

            if ($mode === TenantProvisioningService::MODE_CARD
                && $method !== Tenant::PAYMENT_METHOD_CARD) {
                $validator->errors()->add(
                    'payment_method',
                    'El modo "el dueño paga con tarjeta" requiere método de pago tarjeta.'
                );
            }
        });
    }
}
