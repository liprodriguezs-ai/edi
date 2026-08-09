<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\EntitlementService;
use Modules\SubscriptionPlan\Services\StripeSubscriptionStatuses;
use Modules\Tenant\Models\Tenant;
use Modules\Tracking\DTO\TrackingEvent;
use Modules\Tracking\Services\TrackingDispatcher;
use QCH\Core\Models\Role;
use Throwable;

/**
 * Drives Kivo7 tenant entitlement + status from Stripe webhook events.
 *
 * This listener is registered on Cashier's WebhookReceived event (fired for
 * EVERY incoming Stripe event before Cashier's own handlers run). Cashier keeps
 * the local `subscriptions` table in sync on its own; THIS listener owns the
 * cross-cutting cluster concern: flipping `tenant.status` and projecting the
 * tenant's billing entitlements (tenant_entitlement) onto its access tables.
 *
 * ENTITLEMENT MODEL: a multi-item checkout produces a Stripe subscription whose
 * items map (via plan_module_price.stripe_price_id) to billing packages. Those
 * events populate / reconcile tenant_entitlement through EntitlementService and
 * recompute the access projection. Items whose price is not in the matrix are
 * simply skipped, leaving the projection untouched.
 *
 * TENANCY CONTRACT (critical): a webhook request is UNAUTHENTICATED and arrives
 * with NO tenant scope initialized. We therefore NEVER call
 * tenancy()->initialize() blindly. Tenant is a CENTRAL model (no BelongsToTenant
 * / TenantScope), so we resolve and mutate the affected Tenant strictly BY ID
 * from the event metadata (metadata.tenant_id is ALWAYS preferred over the
 * customer fallback). EntitlementService acts on the explicit $tenant instance and
 * does not depend on the global TenantScope, so it is safe to call here. The
 * owner-resolution fallback queries the central User
 * model; UserTenantScope is inert while tenancy is uninitialized (it
 * early-returns), so the lookup is not silently filtered.
 *
 * SAFETY: a webhook handler must NEVER throw — Stripe retries on non-2xx, and a
 * thrown exception here would surface as a 500 to Stripe. Every branch is wrapped
 * so we log and no-op on any failure, always letting the controller return 200.
 */
class StripeWebhookListener
{
    public function __construct(
        private EntitlementService $entitlementService,
        private TrackingDispatcher $trackingDispatcher,
    ) {}

    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;
        $type = $payload['type'] ?? null;

        // Idempotency: Stripe delivers at-least-once and may re-order. CLAIM the
        // event id atomically (Cache::add) so two concurrent deliveries of the SAME
        // event don't both run and a replayed event is a no-op — no extra migration.
        // The TTL must outlast Stripe's retry window (it retries for up to ~3 days),
        // so we keep the marker for 4 days. Skipped only when the payload has no id.
        //
        // The claim is only KEPT if the handler succeeds (see the catch): marking
        // before running would strand a tenant whose handler flipped status to
        // ACTIVO but then failed to grant entitlements — the already-cached id would
        // make Stripe's retry a no-op, disabling the self-heal.
        $eventId = $payload['id'] ?? null;

        if ($eventId && ! Cache::add('stripe:evt:'.$eventId, true, now()->addDays(4))) {
            return; // already processed (or in-flight)
        }

        try {
            match ($type) {
                'checkout.session.completed' => $this->handleCheckoutCompleted($payload),
                // customer.subscription.CREATED is the event Stripe actually emits on
                // the initial purchase (.updated is NOT). Handling it makes the
                // activation resilient to a missed / out-of-order
                // checkout.session.completed: reconcile grants the entitlements first,
                // so the status flip that follows is legitimately entitled.
                'customer.subscription.created',
                'customer.subscription.updated' => $this->handleSubscriptionUpdated($payload),
                'invoice.payment_succeeded' => $this->handleInvoicePaymentSucceeded($payload),
                'invoice.payment_failed' => $this->handleInvoicePaymentFailed($payload),
                'customer.subscription.deleted' => $this->handleSubscriptionDeleted($payload),
                default => null,
            };
        } catch (Throwable $e) {
            // Release the claim so Stripe's retry reprocesses this event instead of
            // hitting the early-return above and being dropped — the retry is the
            // self-heal we want when e.g. the status flip committed but the
            // entitlement grant then failed. Handlers write via idempotent upserts,
            // so reprocessing is safe. We still never bubble up: Stripe must receive
            // a 200 from the controller (a thrown exception would surface as a 500).
            if ($eventId) {
                Cache::forget('stripe:evt:'.$eventId);
            }

            Log::error('StripeWebhookListener failed', [
                'event_type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * AUTHORITATIVE entitlement-on-payment step. When checkout finishes, activate
     * the tenant and project access: the subscription's items map to billing
     * packages → grant a stripe entitlement per package (EntitlementService
     * recomputes the system_tenant / module_tenant / config projection). The status
     * flip runs in its own transaction; entitlement grants run after (each is itself
     * transactional).
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleCheckoutCompleted(array $payload): void
    {
        $object = $payload['data']['object'] ?? [];
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];

        // Only activate on CONFIRMED payment. In Mexico, async methods (OXXO,
        // transfer) emit checkout.session.completed with payment_status='unpaid'
        // before the money lands — activating then would grant access for free.
        // 'paid' = card/instant; 'no_payment_required' = trial. Anything else: wait.
        $paymentStatus = $object['payment_status'] ?? null;

        if (! in_array($paymentStatus, ['paid', 'no_payment_required'], true)) {
            Log::info('checkout.session.completed: payment not confirmed, skipping activation', [
                'payment_status' => $paymentStatus,
                'tenant_id' => $metadata['tenant_id'] ?? null,
            ]);

            return;
        }

        // F-10 — DEFENSIVE, LAYERED tenant resolution. Cashier writes the checkout
        // metadata through `subscription_data.metadata`, so in a REAL Stripe payment
        // the SESSION arrives with `metadata: []` and only the SUBSCRIPTION carries
        // tenant_id. Reading session metadata alone silently dropped every genuine
        // purchase (the owner paid and stayed on "Sin plan activo"). Layers:
        //   1. session metadata (belt: buildCheckout now sets it there too),
        //   2. the subscription's own metadata (expanded inline or retrieved),
        //   3. the customer → account owner → tenant fallback the other handlers
        //      already had and this one was missing.
        // The subscription is resolved ONCE and reused for both the metadata and the
        // items, so this costs at most one extra Stripe call.
        $subscription = $this->resolveCheckoutSubscription($object);

        if (! isset($metadata['tenant_id']) && is_array($subscription['metadata'] ?? null)) {
            $metadata = $subscription['metadata'] + $metadata;
        }

        $tenant = $this->resolveTenantFromMetadata($metadata)
            ?? $this->resolveTenantFromCustomer($this->customerId($object));

        if (! $tenant) {
            Log::warning('checkout.session.completed: tenant not resolvable from metadata or customer', [
                'metadata' => $metadata,
                'customer' => $this->customerId($object),
            ]);

            return;
        }

        DB::transaction(function () use ($tenant): void {
            $tenant->status = Tenant::STATUS_ACTIVO;
            $tenant->save();
        });

        // Grant entitlements from the subscription items. The stripe_price_id →
        // package map is resolved ONCE here (single whereIn) and the access
        // projection is recomputed ONCE after all grants — only when at least one
        // item mapped, so an empty recompute never wipes the current projection.
        $items = is_array($subscription['items']['data'] ?? null) ? $subscription['items']['data'] : [];

        $granted = $this->grantEntitlementsFromItems(
            $tenant,
            $items,
            $this->matrixByStripePrice($items),
            $this->checkoutSubscriptionId($object),
            $metadata['interval'] ?? null,
            $this->subscriptionFallbackExpiry($subscription),
        );

        if ($granted > 0) {
            $this->entitlementService->recompute($tenant);
        }

        // Tracking (Purchase): server-side conversion, the reliable counterpart to
        // the browser's fbq/gtag hit. Isolated in its own try/catch — a tracking
        // failure must NEVER affect entitlement activation, which already
        // committed above.
        try {
            $this->dispatchPurchaseTracking($tenant, $object, $metadata);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Dispatch a Purchase tracking event to BOTH Facebook CAPI and GA4 MP.
     * event_id/ga_client_id come from the Checkout Session metadata (threaded
     * through by BillingCheckoutController → MultiItemSubscriptionService), so
     * this server-side hit deduplicates with the browser's Purchase event.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $metadata
     */
    private function dispatchPurchaseTracking(Tenant $tenant, array $object, array $metadata): void
    {
        $amountTotal = $object['amount_total'] ?? null;
        $currency = $object['currency'] ?? null;

        $event = TrackingEvent::fromArray([
            'name' => 'Purchase',
            'event_id' => $metadata['event_id'] ?? null,
            // Facebook's action_source enum has NO "system" value — the valid
            // server-originated value is "system_generated" (enum:
            // email|website|phone_call|chat|physical_store|system_generated|other).
            'action_source' => 'system_generated',
            'params' => array_filter([
                'value' => is_numeric($amountTotal) ? round(((int) $amountTotal) / 100, 2) : null,
                'currency' => is_string($currency) ? strtoupper($currency) : null,
                'transaction_id' => (string) $tenant->getKey(),
            ], static fn ($value): bool => $value !== null),
            'user_data' => array_filter([
                'client_id' => $metadata['ga_client_id'] ?? null,
            ]),
        ]);

        $this->trackingDispatcher->dispatch($event, ['facebook', 'google_analytics']);
    }

    /**
     * Subscription status changed in Stripe (renewal, add-on add/remove, quantity
     * change, past_due, etc.). We set the subscription's metadata at checkout, so
     * the tenant resolves from there (customer fallback otherwise).
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleSubscriptionUpdated(array $payload): void
    {
        $object = $payload['data']['object'] ?? [];
        $tenant = $this->resolveTenantFromMetadata($object['metadata'] ?? []);

        if (! $tenant) {
            $tenant = $this->resolveTenantFromCustomer($this->customerId($object));
        }

        if (! $tenant) {
            return;
        }

        $status = $object['status'] ?? null;

        if (in_array($status, StripeSubscriptionStatuses::LIVE, true)) {
            // Reconcile billing entitlements from the current items (add-on
            // add/remove, quantity change, refreshed expires_at). Returns false for
            // a legacy subscription (no recognizable billing items) — in which case
            // the entitlement projection is LEFT UNTOUCHED.
            $this->reconcileSubscriptionEntitlements($tenant, $object);

            // Ordering guard: do NOT first-time activate from subscription.updated
            // unless the tenant is actually entitled. checkout.session.completed is
            // the sole first-time activator; subscription.* may arrive out of order,
            // so only (re)affirm active when active entitlements exist.
            if ($this->tenantHasActiveEntitlements($tenant)) {
                $this->setTenantStatus($tenant, Tenant::STATUS_ACTIVO);
            }
        } elseif (in_array($status, ['past_due', 'unpaid'], true)) {
            // Mark pending but keep access during the grace window: entitlements are
            // not yet expired, so recompute leaves granted modules on.
            $this->setTenantStatus($tenant, Tenant::STATUS_PENDIENTE_PAGO);

            if ($this->tenantHasActiveEntitlements($tenant)) {
                $this->entitlementService->recompute($tenant);
            }
        }
    }

    /**
     * Invoice paid (renewal, or recovery after a past_due) → push the entitlements'
     * expiry to the new period end and recompute so module access is restored, then
     * reaffirm ACTIVO. Only acts when the subscription actually carries billing
     * entitlements; legacy tenants are reactivated by customer.subscription.updated.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleInvoicePaymentSucceeded(array $payload): void
    {
        $object = $payload['data']['object'] ?? [];

        $tenant = $this->resolveTenantFromMetadata($object['subscription_details']['metadata'] ?? []);

        if (! $tenant) {
            $tenant = $this->resolveTenantFromCustomer($this->customerId($object));
        }

        if (! $tenant) {
            return;
        }

        $subscriptionId = $this->invoiceSubscriptionId($object);

        if (! $subscriptionId) {
            return;
        }

        $query = TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('source', TenantEntitlement::SOURCE_STRIPE)
            ->where('stripe_subscription_id', $subscriptionId);

        // Not a billing(entitlement) subscription → leave it to subscription.updated.
        if (! (clone $query)->exists()) {
            return;
        }

        $periodEnd = $this->invoicePeriodEnd($object);

        if ($periodEnd) {
            // M1 — paid-through date is MONOTONIC: a late / out-of-order invoice
            // carrying an OLDER period must never move expires_at backwards (that
            // would silently shorten the cycle). Only push forward, and never touch
            // a perpetual (null) expiry. Stripe delivers at-least-once and may
            // re-order, so a stale invoice can arrive after a newer one.
            //
            // D7 — a successful invoice is exactly the trial-conversion event (a
            // trialing subscription generates no invoice until the day-31
            // charge). Trial detection now derives from Cashier's stripe_status
            // (SubscriptionStateService), not from entitlement metadata, so
            // there is no marker to clear here anymore — only the monotonic
            // expiry advance.
            (clone $query)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', $periodEnd)
                ->update(['expires_at' => $periodEnd]);
        }

        // Only restore access if the subscription STILL has an active entitlement
        // after the (monotonic) update. A cancelled subscription has had its
        // entitlements expired to now() by handleSubscriptionDeleted; a late invoice
        // for that subscription must NOT resurrect the cluster.
        if (! (clone $query)->active()->exists()) {
            return;
        }

        $this->entitlementService->recompute($tenant);
        $this->setTenantStatus($tenant, Tenant::STATUS_ACTIVO);
    }

    /**
     * Invoice payment failed → the cluster has not paid. Invoice events carry no
     * subscription metadata directly, so resolve via subscription_details.metadata
     * when present, otherwise via the Stripe customer → owning user → owned tenant.
     * Entitlements are LEFT IN PLACE (not deleted) — the cluster keeps access until
     * the subscription is actually cancelled/deleted.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleInvoicePaymentFailed(array $payload): void
    {
        $object = $payload['data']['object'] ?? [];

        $tenant = $this->resolveTenantFromMetadata($object['subscription_details']['metadata'] ?? []);

        if (! $tenant) {
            $tenant = $this->resolveTenantFromCustomer($this->customerId($object));
        }

        if (! $tenant) {
            Log::warning('invoice.payment_failed: tenant not resolvable', [
                'customer' => $this->customerId($object),
            ]);

            return;
        }

        $this->setTenantStatus($tenant, Tenant::STATUS_PENDIENTE_PAGO);
    }

    /**
     * Subscription deleted in Stripe → block the cluster. Expire its billing
     * entitlements (set expires_at=now) and recompute so the projection turns the
     * granted modules off and purges their config. Legacy tenants (no entitlements
     * for this subscription) keep the previous behavior: status only, no recompute.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleSubscriptionDeleted(array $payload): void
    {
        $object = $payload['data']['object'] ?? [];
        $tenant = $this->resolveTenantFromMetadata($object['metadata'] ?? []);

        if (! $tenant) {
            $tenant = $this->resolveTenantFromCustomer($this->customerId($object));
        }

        if (! $tenant) {
            return;
        }

        $subscriptionId = $object['id'] ?? null;
        $expiredEntitlements = 0;

        if ($subscriptionId) {
            $expiredEntitlements = TenantEntitlement::query()
                ->where('tenant_id', $tenant->id)
                ->where('source', TenantEntitlement::SOURCE_STRIPE)
                ->where('stripe_subscription_id', $subscriptionId)
                ->update(['expires_at' => now()]);
        }

        // M2 — deleting ONE subscription does not necessarily strip the cluster's
        // coverage: it may still hold another active entitlement (a second stripe
        // subscription, a super_admin_free grant, a trial). Derive the status from
        // the single source of truth — does any active entitlement remain — instead
        // of blocking unconditionally.
        $this->setTenantStatus(
            $tenant,
            $this->tenantHasActiveEntitlements($tenant) ? Tenant::STATUS_ACTIVO : Tenant::STATUS_BLOQUEADO,
        );

        if ($expiredEntitlements > 0) {
            $this->entitlementService->recompute($tenant);
        }
    }

    /**
     * Grant a stripe entitlement for every subscription item whose price maps to a
     * plan_module_price cell (resolved once via $matrixByPrice, indexed by stripe
     * price id). Each cell yields the granted billable_module and the plan it was
     * priced under. Items with an unknown price (e.g. a legacy plan price) are
     * skipped, so this is a no-op for legacy checkouts.
     *
     * Each grant DEFERS its recompute (recompute: false); the caller rebuilds the
     * access projection once per event. Returns the number of grants applied so the
     * caller can skip the recompute entirely when nothing mapped.
     *
     * $fallbackExpiry (D2) covers the case where the item itself carries no
     * current_period_end — e.g. a trialing subscription's item before its first
     * invoice. Passing null here preserves today's semantics for a non-trialing
     * grant (a null expires_at is a deliberate perpetual entitlement elsewhere in
     * this class, e.g. super_admin_free).
     *
     * Trial detection is no longer stamped here: SubscriptionStateService reads
     * Cashier's synced `stripe_status` directly (via the owner's `subscriptions`
     * row), so this grant never writes a local trial marker.
     *
     * @param  array<int, mixed>  $items
     * @param  array<string, PlanModulePrice>  $matrixByPrice
     */
    private function grantEntitlementsFromItems(Tenant $tenant, array $items, array $matrixByPrice, ?string $subscriptionId, ?string $fallbackInterval, ?Carbon $fallbackExpiry = null): int
    {
        $granted = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $priceId = $item['price']['id'] ?? null;

            if (! $priceId) {
                continue;
            }

            $matrix = $matrixByPrice[$priceId] ?? null;

            if (! $matrix || ! $matrix->billable_module || ! $matrix->plan) {
                continue;
            }

            $this->entitlementService->grant($tenant, $matrix->billable_module, $matrix->plan, TenantEntitlement::SOURCE_STRIPE, [
                'interval' => $item['price']['recurring']['interval'] ?? $matrix->interval ?? $fallbackInterval,
                'quantity' => (int) ($item['quantity'] ?? 1),
                'expires_at' => $this->itemPeriodEnd($item) ?? $fallbackExpiry,
                'stripe_subscription_id' => $subscriptionId,
                'stripe_item_id' => $item['id'] ?? null,
            ], recompute: false);

            $granted++;
        }

        return $granted;
    }

    /**
     * Best-effort NON-NULL expiry for a TRIALING subscription grant, used only as
     * the fallback when the subscription item itself carried no
     * current_period_end (D2). Always null for a non-trialing subscription — that
     * preserves today's semantics, where a null expires_at can be a deliberate
     * perpetual grant flowing through the same grant() call elsewhere.
     *
     * A trial MUST NEVER end up with a null expires_at:
     * TenantEntitlement::scopeActive treats null as PERPETUAL, so a null-expiry
     * trial would silently grant free service forever.
     *
     * @param  array<string, mixed>  $subscription
     */
    private function subscriptionFallbackExpiry(array $subscription): ?Carbon
    {
        if (($subscription['status'] ?? null) !== 'trialing') {
            return null;
        }

        $trialEnd = $subscription['trial_end'] ?? null;

        // App timezone passed explicitly — see itemPeriodEnd() for why the bare
        // Carbon 3 call stores the expiry 6 hours late.
        if (is_numeric($trialEnd)) {
            return Carbon::createFromTimestamp((int) $trialEnd, config('app.timezone'));
        }

        // Both the item's current_period_end AND the subscription's trial_end are
        // absent — an anomaly Stripe should never produce for a trialing
        // subscription. Fall back to the configured trial length rather than let
        // the entitlement ship with a perpetual (null) expiry.
        Log::error('Trialing subscription carries no trial_end; falling back to config trial length', [
            'subscription_id' => $subscription['id'] ?? null,
        ]);

        return now()->addDays((int) config('billing.trial_days'));
    }

    /**
     * Reconcile the tenant's stripe entitlements against the subscription's current
     * items: grant/refresh the present billing items and delete the entitlements
     * whose item is gone (add-on removed). Returns false — leaving the projection
     * untouched — when none of the items map to a plan_module_price cell (legacy plan
     * subscription), so this never wipes a legacy tenant's systems.
     *
     * @param  array<string, mixed>  $object  the Stripe subscription object
     */
    private function reconcileSubscriptionEntitlements(Tenant $tenant, array $object): bool
    {
        $subscriptionId = $object['id'] ?? null;
        $items = $object['items']['data'] ?? [];
        $items = is_array($items) ? $items : [];

        // Resolve stripe_price_id → plan_module_price ONCE for the whole event, then
        // reuse the map for both the legacy-detection filter and the grant loop.
        $matrixByPrice = $this->matrixByStripePrice($items);

        $billingItems = array_values(array_filter(
            $items,
            fn ($item): bool => is_array($item)
                && ($priceId = $item['price']['id'] ?? null)
                && isset($matrixByPrice[$priceId]),
        ));

        if ($billingItems === []) {
            return false; // legacy subscription — do not touch the entitlement projection
        }

        $this->grantEntitlementsFromItems(
            $tenant,
            $billingItems,
            $matrixByPrice,
            $subscriptionId,
            $object['metadata']['interval'] ?? null,
            $this->subscriptionFallbackExpiry($object),
        );

        // Delete entitlements for items that are no longer on the subscription
        // (add-on removal). Compare against ALL present item ids, not only the
        // billing ones, so an unmapped present item never triggers a wrong delete.
        $presentItemIds = array_values(array_filter(
            array_map(fn ($item) => is_array($item) ? ($item['id'] ?? null) : null, $items),
        ));

        if ($subscriptionId && $presentItemIds !== []) {
            TenantEntitlement::query()
                ->where('tenant_id', $tenant->id)
                ->where('source', TenantEntitlement::SOURCE_STRIPE)
                ->where('stripe_subscription_id', $subscriptionId)
                ->whereNotIn('stripe_item_id', $presentItemIds)
                ->delete();
        }

        // Self-heal tenant.billing_package_id from the plan the subscription now
        // carries (the SAME plan_module_price.plan we granted under). If a local
        // re-point failed after Stripe already swapped — e.g. a crash in
        // PreferentialSwapService::repointEntitlements after swap() committed on
        // Stripe — the tenant would be stranded on the old plan; this converges it
        // to Stripe's truth on the next customer.subscription.updated.
        $this->reconcileTenantBillingPackage($tenant, $billingItems, $matrixByPrice);

        // Single recompute for the whole event (the grants above deferred theirs).
        $this->entitlementService->recompute($tenant);

        return true;
    }

    /**
     * Persist tenant.billing_package_id from the single plan the subscription's
     * billing items map to (via plan_module_price.plan), so a failed local re-point
     * self-heals on the next reconcile. No-op when it already matches; logs and
     * leaves it untouched when the items span multiple plans (never guesses a plan).
     *
     * @param  array<int, mixed>  $billingItems  items already known to map to the matrix
     * @param  array<string, PlanModulePrice>  $matrixByPrice
     */
    private function reconcileTenantBillingPackage(Tenant $tenant, array $billingItems, array $matrixByPrice): void
    {
        $planIds = [];

        foreach ($billingItems as $item) {
            $priceId = is_array($item) ? ($item['price']['id'] ?? null) : null;
            $plan = $priceId !== null ? ($matrixByPrice[$priceId]->plan ?? null) : null;

            if ($plan !== null) {
                $planIds[$plan->id] = true;
            }
        }

        if (count($planIds) > 1) {
            Log::warning('customer.subscription.updated: billing items span multiple plans; leaving billing_package_id untouched', [
                'tenant_id' => $tenant->id,
                'plan_ids' => array_keys($planIds),
            ]);

            return;
        }

        $planId = array_key_first($planIds);

        if ($planId !== null && $tenant->billing_package_id !== $planId) {
            $tenant->update(['billing_package_id' => $planId]);
        }
    }

    /**
     * Resolve the stripe_price_id → PlanModulePrice map for the given subscription
     * items in a SINGLE query (one whereIn over plan_module_price), indexed by
     * Stripe price id. Each cell carries its billable_module and plan. Prices with
     * no billable_module or plan are dropped.
     *
     * @param  array<int, mixed>  $items
     * @return array<string, PlanModulePrice>
     */
    private function matrixByStripePrice(array $items): array
    {
        $priceIds = array_values(array_unique(array_filter(
            array_map(fn ($item) => is_array($item) ? ($item['price']['id'] ?? null) : null, $items),
            fn ($priceId): bool => is_string($priceId) && $priceId !== '',
        )));

        if ($priceIds === []) {
            return [];
        }

        return PlanModulePrice::query()
            ->whereIn('stripe_price_id', $priceIds)
            ->with(['billable_module', 'plan'])
            ->get()
            ->filter(fn (PlanModulePrice $price): bool => $price->billable_module !== null && $price->plan !== null)
            ->keyBy('stripe_price_id')
            ->all();
    }

    /**
     * The FULL subscription behind a checkout session — inline when expanded on the
     * event, otherwise retrieved live from Stripe by id. Carries both the metadata
     * (tenant resolution) and the items (entitlement grants), so a single resolution
     * serves both. Returns [] on any failure so a network hiccup degrades gracefully
     * instead of breaking the activation flow.
     *
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function resolveCheckoutSubscription(array $object): array
    {
        $subscription = $object['subscription'] ?? null;

        if (is_array($subscription)) {
            return $subscription;
        }

        if (is_string($subscription) && $subscription !== '') {
            return $this->retrieveSubscription($subscription);
        }

        return [];
    }

    /**
     * Live-retrieve a subscription from Stripe (with the price expanded) and
     * normalize to plain arrays. Octane-safe: Cashier::stripe() builds a fresh
     * client per call. Wrapped so a network failure degrades to [].
     *
     * @return array<string, mixed>
     */
    private function retrieveSubscription(string $subscriptionId): array
    {
        try {
            $subscription = Cashier::stripe()->subscriptions->retrieve(
                $subscriptionId,
                ['expand' => ['items.data.price']],
            );

            $normalized = json_decode(json_encode($subscription), true);

            return is_array($normalized) ? $normalized : [];
        } catch (Throwable $e) {
            Log::warning('Stripe subscription retrieve failed during webhook entitlement grant', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * The Stripe customer id an event object points at (string id or expanded obj).
     *
     * @param  array<string, mixed>  $object
     */
    private function customerId(array $object): ?string
    {
        $customer = $object['customer'] ?? null;

        if (is_string($customer) && $customer !== '') {
            return $customer;
        }

        if (is_array($customer) && is_string($customer['id'] ?? null) && $customer['id'] !== '') {
            return $customer['id'];
        }

        return null;
    }

    /**
     * The subscription id a checkout session points at (string id or expanded obj).
     *
     * @param  array<string, mixed>  $object
     */
    private function checkoutSubscriptionId(array $object): ?string
    {
        $subscription = $object['subscription'] ?? null;

        if (is_string($subscription) && $subscription !== '') {
            return $subscription;
        }

        if (is_array($subscription) && isset($subscription['id']) && is_string($subscription['id'])) {
            return $subscription['id'];
        }

        return null;
    }

    /**
     * Per-item current period end (Stripe `basil` API moved current_period_end onto
     * subscription items). Unix seconds → Carbon, or null when absent.
     *
     * The app timezone is passed EXPLICITLY: Carbon 3 changed
     * createFromTimestamp() to return UTC (Carbon 2 returned the app timezone), and
     * `tenant_entitlement.expires_at` is a `timestamp without time zone` column read
     * back as local — so the bare call stored the expiry 6 hours late. Measured on a
     * real checkout: Stripe's trial_end was 2026-09-06 23:59:59 local while the
     * entitlement landed on 2026-09-07 05:59:59, granting access 6 hours past the
     * charge. Same defect class as the Cashier columns fixed by AppTimezoneCast.
     *
     * @param  array<string, mixed>  $item
     */
    private function itemPeriodEnd(array $item): ?Carbon
    {
        $end = $item['current_period_end'] ?? null;

        return is_numeric($end) ? Carbon::createFromTimestamp((int) $end, config('app.timezone')) : null;
    }

    /**
     * Resolve the subscription id an invoice belongs to across Stripe API shapes.
     *
     * @param  array<string, mixed>  $object
     */
    private function invoiceSubscriptionId(array $object): ?string
    {
        $candidates = [
            $object['subscription'] ?? null,
            $object['parent']['subscription_details']['subscription'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        foreach ($object['lines']['data'] ?? [] as $line) {
            if (! is_array($line)) {
                continue;
            }

            $subscription = $line['subscription']
                ?? ($line['parent']['subscription_item_details']['subscription'] ?? null);

            if (is_string($subscription) && $subscription !== '') {
                return $subscription;
            }
        }

        return null;
    }

    /**
     * The latest period end carried by an invoice (max of the line periods, falling
     * back to the invoice-level period end). Unix seconds → Carbon, or null.
     *
     * @param  array<string, mixed>  $object
     */
    private function invoicePeriodEnd(array $object): ?Carbon
    {
        $ends = [];

        foreach ($object['lines']['data'] ?? [] as $line) {
            $end = is_array($line) ? ($line['period']['end'] ?? null) : null;

            if (is_numeric($end)) {
                $ends[] = (int) $end;
            }
        }

        if ($ends === [] && is_numeric($object['period_end'] ?? null)) {
            $ends[] = (int) $object['period_end'];
        }

        // App timezone passed explicitly — this value becomes the entitlement's
        // paid-through date, so the bare Carbon 3 call would push it 6 hours late.
        // See itemPeriodEnd().
        return $ends === [] ? null : Carbon::createFromTimestamp(max($ends), config('app.timezone'));
    }

    /**
     * Whether the tenant has any non-expired entitlement (stripe, trial, legacy…).
     */
    private function tenantHasActiveEntitlements(Tenant $tenant): bool
    {
        return TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->active()
            ->exists();
    }

    /**
     * Resolve the affected tenant from event metadata `tenant_id` (preferred).
     *
     * @param  array<string, mixed>  $metadata
     */
    private function resolveTenantFromMetadata(array $metadata): ?Tenant
    {
        $tenantId = $metadata['tenant_id'] ?? null;

        if (! $tenantId) {
            return null;
        }

        return Tenant::find($tenantId);
    }

    /**
     * Fallback resolution for events without metadata (invoices): map the Stripe
     * customer id to the central User that owns it, then to the tenant that user
     * is the account owner of. Returns null when ambiguous or not found.
     */
    private function resolveTenantFromCustomer(?string $customerId): ?Tenant
    {
        if (! $customerId) {
            return null;
        }

        // User is central; UserTenantScope is inert while tenancy is not
        // initialized (webhook context), so this is not silently filtered.
        $user = User::query()->where('stripe_id', $customerId)->first();

        if (! $user) {
            return null;
        }

        // The Stripe customer/card belongs to the cluster's account owner. Only
        // treat this user as a billing owner when they actually hold that role.
        if (! $user->hasRole(Role::SLUG_ACCOUNT_OWNER)) {
            return null;
        }

        $ownedTenants = $user->tenants()->get();

        // Only act when the owner belongs to exactly one tenant — avoid
        // cross-tenant guesses when a user owns multiple clusters.
        if ($ownedTenants->count() !== 1) {
            return null;
        }

        return $ownedTenants->first();
    }

    private function setTenantStatus(Tenant $tenant, string $status): void
    {
        if ($tenant->status === $status) {
            return;
        }

        $tenant->status = $status;
        $tenant->save();
    }
}
