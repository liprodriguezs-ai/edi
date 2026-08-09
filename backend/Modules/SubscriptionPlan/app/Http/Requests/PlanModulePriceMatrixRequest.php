<?php

namespace Modules\SubscriptionPlan\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk-upsert payload for the plan × module price matrix editor (v2). Only the
 * LOCAL price data is accepted — stripe_price_id / stripe_product_id are owned
 * by billing:sync-stripe and are intentionally NOT part of this contract.
 *
 * Invariant: is_free and trial_days are properties of the (plan, module) pair,
 * not of one interval — free-on-month but paid-on-year would break the public
 * quoter, and EntitlementService::startModuleTrial reads a single row per
 * module, so a per-interval trial override could never take effect anyway.
 * The v2 shape makes the mismatched-flags state STRUCTURALLY unrepresentable:
 * flags live once per module entry, cells carry amounts only, and a per-cell
 * flag field is `prohibited` so a stale v1 SPA bundle fails loud (422) instead
 * of silently dropping its flags.
 */
class PlanModulePriceMatrixRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'modules' => ['required', 'array', 'min:1'],
            'modules.*.billable_module_id' => ['required', 'uuid', 'exists:billable_module,id'],
            'modules.*.is_free' => ['sometimes', 'boolean'],
            'modules.*.trial_days' => ['nullable', 'integer', 'min:0', 'max:730'],
            'modules.*.cells' => ['required', 'array:month,year'], // unknown interval keys → 422
            'modules.*.cells.month' => ['required', 'array'],
            'modules.*.cells.year' => ['required', 'array'],
            'modules.*.cells.*.unit_amount' => ['required', 'integer', 'min:0'],
            'modules.*.cells.*.currency' => ['sometimes', 'string', 'size:3'],
            // A stale v1 SPA bundle sending per-cell flags must fail LOUD (422), not
            // have its flags silently dropped and module-level is_free default to false.
            'modules.*.cells.*.is_free' => ['prohibited'],
            'modules.*.cells.*.trial_days' => ['prohibited'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
