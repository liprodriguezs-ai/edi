<?php

namespace Modules\SubscriptionPlan\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Cashier\Subscription;
use Modules\SubscriptionPlan\Exceptions\CheckoutUnavailableException;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\Tenant\Models\Tenant;
use RuntimeException;

/**
 * Builds and mutates a MULTI-ITEM Stripe subscription (one plan carrying N
 * billable modules on a single Cashier subscription). Billing v2 (S4): the price
 * of a module inside a plan is FIXED (plan_module_price), so every subscription
 * line carries quantity=1 — units NEVER multiply the price. The unit count only
 * informs which plan the organization is suggested (metadata).
 *
 * WIRED (self-serve management): buildCheckout is reached from
 * BillingCheckoutController; the post-purchase mutator (changeInterval) + the
 * proration preview (previewChange) are reached from the owner-only self-service
 * endpoints on ConfigurationController. Per-module self-serve (add/remove add-on,
 * change units) was removed in the 2026-07 collapse — there is a single sellable
 * module, so the only real mutations left are interval and plan. After a mutation
 * the Stripe customer.subscription.updated webhook reconciles tenant_entitlement
 * via StripeWebhookListener — this service NEVER writes entitlements itself.
 *
 * Pricing authority is PlanPricingService — the client is never trusted for
 * amounts. This domain service stays HTTP-agnostic; the controller maps
 * CheckoutUnavailableException to a 422.
 */
class MultiItemSubscriptionService
{
    public function __construct(
        private PlanPricingService $planPricingService,
        private EntitlementService $entitlementService,
    ) {}

    /**
     * Start a multi-item Stripe Checkout for the organization's owner. Every requested
     * billable module becomes one subscription line with quantity=1; modules with
     * no active/synced Stripe price for (plan, interval) are skipped. The base
     * module is mandatory (validated upstream + priced by the matrix).
     *
     * @param  array<int, string>  $moduleKeys  Empty → every module the plan prices.
     * @param  int|null  $units  Informational unit count → Checkout metadata (plan suggestion).
     * @param  string|null  $eventId  Purchase tracking event_id, threaded so the Stripe webhook
     *                                dispatches the SAME event_id for Meta/GA4 dedup. Generated when absent.
     * @param  string|null  $gaClientId  GA4 _ga cookie id, threaded so the webhook's GA4 MP hit stitches.
     * @param  bool  $publicOnly  Restrict the plan to is_public + is_active. TRUE by
     *                            default because the only caller is the OWNER's
     *                            self-serve checkout: a hidden/negotiated plan key
     *                            must never be self-purchasable (F-17 — guessing one
     *                            bought the same band 33% cheaper). Super-admin paths
     *                            (preferential swap, provisioning) do not come through
     *                            here and keep their access to hidden plans.
     * @return string Stripe Checkout URL
     *
     * @throws CheckoutUnavailableException When no module has a billable Stripe price.
     */
    public function buildCheckout(User $owner, Tenant $tenant, string $planKey, array $moduleKeys, string $interval, ?int $units = null, ?string $eventId = null, ?string $gaClientId = null, bool $publicOnly = true): string
    {
        // Authoritative recompute: throws when the plan/modules/interval are invalid,
        // the base module is missing, or the plan is contact-sales (422 upstream).
        // Its lines also RESOLVE the effective module set when the caller sent none.
        $quote = $this->planPricingService->quoteByPlan($planKey, $moduleKeys, $interval, $publicOnly);

        $moduleKeys = array_column($quote['lines'], 'module_key');

        $priceIds = $this->resolveStripePriceIds($planKey, $moduleKeys, $interval);

        if ($priceIds === []) {
            throw CheckoutUnavailableException::noBillablePrice('El plan seleccionado no tiene un precio facturable configurado en Stripe.');
        }

        $metadata = [
            'tenant_id' => (string) $tenant->getKey(),
            'plan_key' => $planKey,
            'interval' => $interval,
            'units' => $units !== null ? (string) $units : null,
            // Tracking dedup (Purchase): consumed by StripeWebhookListener.
            'event_id' => $eventId ?? (string) Str::uuid(),
            'ga_client_id' => $gaClientId,
        ];

        // withMetadata() lands on subscription_data.metadata (the SUBSCRIPTION), which
        // is why checkout.session.completed used to arrive with metadata: [] — see F-10.
        $builder = $owner->newSubscription('default', $priceIds)->withMetadata($metadata);

        // D1 — ONE Stripe trial per organization, ever. A tenant that never held any
        // entitlement (incl. soft-deleted / expired) gets config('billing.trial_days')
        // days on the house; a cancel-and-rebuy (or any prior grant) is charged
        // immediately. The trial call is made ONLY when trialing — never with a
        // zero-day request: the SubscriptionBuilder floor coerces that into a 2-day
        // trial (SubscriptionBuilder::checkout, ~line 388), so "no trial" means
        // omitting the call entirely.
        //
        // trialUntil(end of day), NOT trialDays(): trialDays anchors trial_end to
        // the moment the CHECKOUT SESSION is created, but the subscription is not
        // born until the buyer finishes typing their card — so the seconds they
        // spend on the form come straight off the far end. Measured on real
        // checkouts: a trialDays(30) request produced 29d 23:58:22 and 29d 23:59:04,
        // both a hair UNDER 30, and Stripe's Checkout screen floors the remainder
        // and advertises "29 days free" while our own page promises 30 — a
        // contradiction shown at the exact moment the card is entered. Anchoring to
        // the end of the final day puts the trial comfortably past 30 full days, so
        // Stripe's floor lands on 30. The entitlement follows automatically:
        // subscriptionFallbackExpiry() reads Stripe's own trial_end, so access never
        // expires before the trial does.
        if (! $this->entitlementService->tenantEverHadEntitlement($tenant)) {
            $builder->trialUntil(now()->addDays((int) config('billing.trial_days'))->endOfDay());
        }

        // Fixed price per plan×module → one unit per line (NEVER × units).
        foreach ($priceIds as $priceId) {
            $builder->quantity(1, $priceId);
        }

        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $checkout = $builder->checkout([
            'success_url' => $frontend.'/select-plan?success=true',
            'cancel_url' => $frontend.'/select-plan?canceled=true',
            // Belt AND braces (F-10): the same metadata at SESSION level, so
            // checkout.session.completed resolves the tenant without a round-trip to
            // fetch the subscription. Nulls are dropped — Stripe rejects null values.
            'metadata' => array_filter($metadata, static fn ($value): bool => $value !== null),
        ]);

        return $checkout->url;
    }

    /**
     * Re-map EVERY current line to the destination interval's price in ONE swap
     * (quantity=1 each), with proration (create_prorations default). Proration is
     * left to Stripe (computed at now); the client is NEVER trusted for a
     * proration_date on this billing mutation.
     *
     * @param  array<int, string>  $stripePriceIds  destination-interval price ids
     */
    public function changeInterval(Subscription $subscription, array $stripePriceIds): void
    {
        if ($subscription->hasIncompletePayment()) {
            throw new RuntimeException('No se puede cambiar el intervalo mientras la suscripción tiene un pago incompleto.');
        }

        if ($stripePriceIds === []) {
            throw new InvalidArgumentException('No hay precios facturables para el intervalo destino.');
        }

        $prices = [];

        foreach (array_values(array_unique($stripePriceIds)) as $priceId) {
            $prices[$priceId] = ['quantity' => 1];
        }

        $subscription->swap($prices);
    }

    /**
     * Preview the proration of a PROPOSED end state (plan + module set + interval)
     * against the owner's live subscription. proration_amount is the SIGNED sum of
     * the proration line items (negative = credit); new_recurring_total is recomputed
     * SERVER-SIDE from plan_module_price, never trusting the client.
     *
     * @param  array<int, string>  $moduleKeys  the FULL desired module set after the change
     * @return array{proration_amount: int, proration_date: int|null, new_recurring_total: int, currency: string, effective_at: string}
     *
     * @throws InvalidArgumentException When the proposed set has no billable price.
     */
    public function previewChange(Subscription $subscription, string $planKey, array $moduleKeys, string $interval): array
    {
        // Authoritative recurring total for the proposed set (minor units). Quoting
        // FIRST also resolves the effective module set when the caller sent none.
        $quote = $this->planPricingService->quoteByPlan($planKey, $moduleKeys, $interval);

        $priceIds = $this->resolveStripePriceIds($planKey, array_column($quote['lines'], 'module_key'), $interval);

        if ($priceIds === []) {
            throw new InvalidArgumentException('La configuración solicitada no tiene precios facturables.');
        }

        $prices = [];

        foreach ($priceIds as $priceId) {
            $prices[$priceId] = ['quantity' => 1];
        }

        $invoice = $subscription->previewInvoice($prices);

        $prorationAmount = 0;
        $prorationDate = null;

        if ($invoice !== null) {
            $prorationDate = $invoice->subscriptionProrationDate();

            foreach ($invoice->invoiceLineItems() as $line) {
                if ($line->isProration()) {
                    $prorationAmount += (int) ($line->asStripeInvoiceLineItem()->amount ?? 0);
                }
            }
        }

        return [
            'proration_amount' => $prorationAmount,
            'proration_date' => $prorationDate,
            'new_recurring_total' => $quote['total'],
            'currency' => $quote['currency'],
            // App timezone passed explicitly so both branches agree: Carbon 3's bare
            // createFromTimestamp() returns UTC while Carbon::now() returns app time,
            // so the two arms of this ternary were emitting ISO strings anchored to
            // different zones for the same field.
            'effective_at' => $prorationDate !== null
                ? Carbon::createFromTimestamp($prorationDate, config('app.timezone'))->toIso8601String()
                : Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Resolve the Stripe price id of each requested module for the plan/interval,
     * preserving the requested order and skipping unsynced / inactive cells.
     *
     * @param  array<int, string>  $moduleKeys
     * @return array<int, string>
     */
    public function resolveStripePriceIds(string $planKey, array $moduleKeys, string $interval): array
    {
        $moduleKeys = array_values(array_unique($moduleKeys));

        if ($moduleKeys === []) {
            return [];
        }

        $rows = PlanModulePrice::query()
            ->where('interval', $interval)
            ->where('is_active', true)
            ->whereNotNull('stripe_price_id')
            ->whereHas('plan', fn ($query) => $query->where('key', $planKey))
            ->whereHas('billable_module', fn ($query) => $query->whereIn('key', $moduleKeys))
            ->with('billable_module:id,key')
            ->get()
            ->keyBy(fn (PlanModulePrice $price): string => (string) $price->billable_module?->key);

        $priceIds = [];

        foreach ($moduleKeys as $key) {
            $row = $rows->get($key);

            if ($row === null || ! $row->stripe_price_id) {
                continue;
            }

            $priceIds[] = $row->stripe_price_id;
        }

        return array_values(array_unique($priceIds));
    }
}
