<?php

namespace Modules\SubscriptionPlan\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the billing v2 plan×module quote request. Amounts are NEVER accepted
 * from the client — PlanPricingService recomputes every line from the stored
 * plan_module_price matrix. The base module requirement is enforced in the
 * service (it depends on which billable module is flagged is_base), not here.
 *
 * `modules` is OPTIONAL: modules are no longer a user-facing choice, so the
 * default quote is "everything the plan prices". It is still accepted (and
 * validated) for backwards compatibility with older callers.
 */
class BillingPlanQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'plan_key' => ['required', 'string', 'max:50'],
            'modules' => ['sometimes', 'array', 'min:1', 'max:20'],
            'modules.*' => ['required', 'string', 'max:50'],
            'interval' => ['required', 'string', 'in:month,year'],
        ];
    }
}
