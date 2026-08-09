<?php

namespace Modules\SubscriptionPlan\Http\Controllers;

use App\Models\User;
use App\Services\AccountOwnerResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Laravel\Cashier\Cashier;
use Modules\SubscriptionPlan\Exceptions\CheckoutUnavailableException;
use Modules\SubscriptionPlan\Http\Requests\BillingCheckoutRequest;
use Modules\SubscriptionPlan\Services\MultiItemSubscriptionService;
use Modules\SubscriptionPlan\Services\StripeSubscriptionStatuses;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Http\Controllers\Controller;
use Throwable;

/**
 * Authenticated multi-item Stripe Checkout (Slice 2 / B1).
 *
 * POST /api/billing/checkout — wires the multi-item billing contract
 * (MultiItemSubscriptionService) to a real route. ADDITIVE: it runs in parallel
 * to the legacy single-plan checkout in ConfigurationController, mirroring its
 * owner-only authorization and already-subscribed (409) guard.
 *
 * The subscription/card ALWAYS belongs to the organization's account owner, so only
 * the owner may initiate checkout (403 otherwise). PricingService is the
 * server-side authority: client-supplied amounts are ignored, and a unit count
 * beyond the published bands (contact_sales) surfaces as a 422.
 */
class BillingCheckoutController extends Controller
{
    /**
     * Custom-action controller (single endpoint, NOT an Orion resource). It defines
     * its own constructor and deliberately does NOT call parent::__construct(): the
     * Orion base controller requires a $model and bootstraps CRUD machinery this
     * action never uses. The action is named with a domain verb (checkout) rather
     * than a CRUD verb so it never collides with an Orion lifecycle method
     * signature (store/index/...). Same pattern as Projects\ProjectsReportController.
     */
    public function __construct(
        private readonly MultiItemSubscriptionService $subscriptionService,
        private readonly AccountOwnerResolver $ownerResolver,
    ) {}

    public function checkout(BillingCheckoutRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $tenant = Tenant::findOrFail(tenant()->getTenantKey());
        $user = auth()->guard('sanctum')->user();
        $owner = $this->ownerResolver->resolve($tenant);

        if (! $owner) {
            // Owner unresolved (missing or ambiguous) → unprocessable, not forbidden.
            return response()->json([
                'message' => 'No se encontró el propietario de la cuenta para este organización.',
                'code' => 'owner_unresolved',
            ], 422);
        }

        if (! $user || $owner->id !== $user->id) {
            return response()->json([
                'message' => 'Solo el propietario de la cuenta puede iniciar el pago de la suscripción.',
                'code' => 'not_account_owner',
            ], 403);
        }

        // F-16 — the guard and the session creation must be ATOMIC per owner. Five
        // concurrent calls used to return five 200s (read-and-act with no lock), each
        // a chargeable Checkout session. The lock is short-lived and only wraps local
        // work + the two Stripe calls; a caller that cannot take it is told to retry
        // rather than being allowed to open a competing session.
        // The lock lives on the CENTRAL cache store (`cache.store`, a singleton
        // resolved from the original manager). The Cache FACADE is swapped for a
        // tenant-scoped CacheManager while tenancy is initialized, so locking through
        // it would key the lock inside the tenant's store; the owner is a central
        // user and the guard must hold across every context that can reach checkout.
        $lock = app('cache.store')->lock('billing:checkout:'.$owner->id, 30);

        if (! $lock->get()) {
            return response()->json([
                'message' => 'Ya hay un pago en proceso para este organización. Espera unos segundos e intenta de nuevo.',
                'code' => 'checkout_in_progress',
            ], 409);
        }

        try {
            if ($this->hasActiveSubscription($owner)) {
                return response()->json([
                    'message' => 'Ya tienes una suscripción activa. Usa el portal o cambia de plan.',
                    'code' => 'already_subscribed',
                ], 409);
            }

            $url = $this->subscriptionService->buildCheckout(
                $owner,
                $tenant,
                $validated['plan_key'],
                // Absent → the plan's full priced module set (modules are no longer
                // a user-facing choice).
                $validated['modules'] ?? [],
                $validated['interval'],
                isset($validated['units']) ? (int) $validated['units'] : null,
                $validated['event_id'] ?? null,
                $validated['ga_client_id'] ?? null,
            );
        } catch (CheckoutUnavailableException $e) {
            // reason() yields a machine-readable discriminator ('contact_sales' |
            // 'no_billable_price') so the front distinguishes the two 422 flavors
            // without parsing the Spanish message. 'no_billable_price' is always an
            // operational fault (a plan with rows but no synced Stripe id — D6) and
            // must be reported so it surfaces to an operator; 'contact_sales' is a
            // deliberate a_medida plan and never worth an alert.
            if ($e->reason() === CheckoutUnavailableException::REASON_NO_BILLABLE_PRICE) {
                report($e);
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->reason(),
            ], 422);
        } catch (InvalidArgumentException $e) {
            // Unknown / inactive / NON-PUBLIC plan key (F-17): a business rejection,
            // not a server error. The message never reveals whether a hidden plan
            // exists — PlanPricingService returns the same text for both.
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'invalid_plan',
            ], 422);
        } finally {
            $lock->release();
        }

        return response()->json(['url' => $url]);
    }

    /**
     * Whether the owner ALREADY has a live subscription — checked against BOTH the
     * local Cashier mirror and Stripe itself.
     *
     * F-16: the local `subscriptions` table is populated only by webhooks, so an
     * owner who just paid but whose webhook has not landed looks unsubscribed and can
     * be charged a second time (reproduced live). Stripe is the source of truth.
     *
     * A Stripe outage FAILS OPEN (logged, treated as "no subscription"): the local
     * guard still stands, and blocking every purchase because Stripe is briefly
     * unreachable would be worse than the residual double-charge window.
     */
    private function hasActiveSubscription(User $owner): bool
    {
        $local = $owner->subscription('default');

        if ($local && in_array($local->stripe_status, StripeSubscriptionStatuses::LIVE, true)) {
            return true;
        }

        if (! $owner->hasStripeId()) {
            return false;
        }

        try {
            // Stripe's default listing already excludes canceled subscriptions; the
            // status filter accepts ONE value, so we list and filter locally to cover
            // both active and trialing.
            $subscriptions = Cashier::stripe()->subscriptions->all([
                'customer' => $owner->stripe_id,
                'limit' => 20,
            ]);

            foreach ($subscriptions->data ?? [] as $subscription) {
                if (in_array($subscription->status ?? null, StripeSubscriptionStatuses::LIVE, true)) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            Log::warning('billing checkout: could not verify subscriptions against Stripe', [
                'user_id' => $owner->id,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }
}
