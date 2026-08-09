<?php

namespace Modules\SubscriptionPlan\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the authenticated multi-item checkout request. Amounts are NEVER
 * accepted from the client — MultiItemSubscriptionService recomputes every line
 * from the stored prices via PricingService. The owner gate (account.owner) and
 * the already-subscribed (409) guard live in the controller, not here.
 *
 * `modules` is OPTIONAL: modules are no longer a user-facing choice, so an absent
 * array means "everything the plan prices" (which always includes the base). When
 * an explicit array IS sent — older callers — the base is still mandatory.
 */
class BillingCheckoutRequest extends FormRequest
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
            'plan_key' => ['required', 'string', 'max:50'],
            'modules' => ['sometimes', 'array', 'min:1', 'max:20', $this->mustIncludeBase()],
            'modules.*' => ['required', 'string', 'max:50'],
            'interval' => ['required', 'string', 'in:month,year'],
            // Informational unit count → Checkout metadata (plan suggestion), not price.
            'units' => ['nullable', 'integer', 'min:1', 'max:100000'],
            // Optional tracking dedup fields (Purchase event): threaded into the
            // Stripe Checkout metadata so StripeWebhookListener can dispatch the
            // SAME event_id/ga_client_id the browser used. Absent for older callers.
            'event_id' => ['nullable', 'uuid'],
            'ga_client_id' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * When an explicit module array is sent, it must carry the base module: a
     * subscription cannot exist without it. An ABSENT array is not a violation —
     * the server then defaults to the plan's full (base-inclusive) module set.
     */
    private function mustIncludeBase(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value) || ! in_array('base', $value, true)) {
                $fail('La selección enviada no corresponde al plan del condominio.');
            }
        };
    }
}
