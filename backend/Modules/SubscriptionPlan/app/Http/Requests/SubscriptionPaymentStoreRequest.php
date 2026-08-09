<?php

namespace Modules\SubscriptionPlan\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\SubscriptionPayment;

/**
 * Validates a manual subscription payment recorded by a super-admin. The
 * super-admin gate lives in the controller constructor; this request only shapes
 * the body.
 *
 * ONE manual payment covers SEVERAL contracted packages, so the body is a list
 * of per-package lines — each its own amount (the default is the plan price; the
 * admin may edit it) and optional interval:
 *
 *   {
 *     "method": "transfer",
 *     "paid_at": "2026-06-30T12:00:00-06:00",   // optional, defaults to now
 *     "notes": "Transferencia ref 1001",         // optional, shared across lines
 *     "lines": [
 *       { "package_key": "base",       "amount": 95000, "interval": "month" },
 *       { "package_key": "paqueteria", "amount": 30000 }                     // interval optional
 *     ]
 *   }
 *
 * Amount is in minor units (cents). A line's interval is optional — when omitted
 * the service falls back to the existing entitlement's interval, else monthly.
 */
class SubscriptionPaymentStoreRequest extends FormRequest
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
        return [
            // Manual recordings are out-of-band only (transfer/cash); `card` is
            // Stripe's domain and is rejected here, not just in the service.
            'method' => ['required', 'string', Rule::in(SubscriptionPayment::MANUAL_METHODS)],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.module_key' => ['required', 'string', 'exists:billable_module,key'],
            'lines.*.plan_key' => ['required', 'string', 'exists:billing_package,key'],
            'lines.*.amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'lines.*.interval' => ['nullable', 'string', Rule::in(PlanModulePrice::INTERVALS)],
        ];
    }
}
