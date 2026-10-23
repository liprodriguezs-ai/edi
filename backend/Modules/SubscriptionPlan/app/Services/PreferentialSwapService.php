<?php

namespace Modules\SubscriptionPlan\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Cashier\Subscription;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\Tenant\Models\Tenant;

/**
 * Preferential ("variante oculta") PLAN swap for super-admins (billing v2).
 *
 * A super-admin moves a organization from its current plan to a PREFERENTIAL plan (a
 * billing_package with is_public=false: same modules, a negotiated cheaper price
 * in plan_module_price that never appears in the public catalog/quoter). Every one
 * of the organization's ACTIVE per-module entitlements is re-pointed to the preferential
 * plan (source + expiry + interval preserved), and tenant.billing_package_id is
 * updated.
 *
 * Two billing realities:
 *  - CARD (active Stripe subscription): preview() asks Stripe for the upcoming
 *    invoice of swapping the organization's module lines to the preferential plan's
 *    prices and reads back the proration_date for the estimate shown to the user;
 *    apply() swaps the lines and lets Stripe compute the proration at now — the
 *    client is NEVER trusted for the proration_date — then re-points the local
 *    entitlements.
 *  - MANUAL (transfer/cash — no Stripe subscription): nothing to prorate. preview()
 *    reports the new plan total; apply() only re-points the entitlements.
 *
 * HTTP-agnostic: throws InvalidArgumentException for domain failures (controller
 * maps to 422). Octane-safe: no per-instance Stripe/tenant state.
 *
 * DELIBERATE EXEMPTION (plan-downgrade-guard, REQ-03/REQ-08): unit-band fit is
 * NOT checked anywhere in this service. Both `ConfigurationController::
 * changeBillingPlan` (organization-admin self-serve) AND `PreferentialSwapController::
 * swap` (super-admin preferential swap) funnel into applyCard()/applyManual()
 * here — a guard placed in this service would silently block the super admin
 * too. The organization-admin surface enforces the guard itself, in
 * `ConfigurationController` (via `UnitLimitService::assertPlanFits()`), BEFORE
 * calling into this service; the super-admin preferential swap is exempt by
 * explicit user decision (the preferential swap is a deliberate commercial
 * tool for negotiated prices outside the public bands). Do NOT "helpfully"
 * move the check down here — that would remove the exemption. The exemption
 * is already bounded by PreferentialSwapController's constructor gate
 * (`tokenCan('update.tenant')` — super-admin exclusive); no redundant check
 * belongs in this service either.
 */
class PreferentialSwapService
{
    public function __construct(
        private EntitlementService $entitlementService,
        private PlanPricingService $planPricingService,
    ) {}

    /**
     * Preview a CARD swap to the preferential plan: read back the proration_date
     * Stripe chose for the estimate shown to the user (apply() lets Stripe
     * re-compute the proration at now — it does not pin this date).
     *
     * @param  array<int, string>  $moduleKeys  the organization's contracted module keys
     * @return array{manual: false, proration_date: int|null, amount_due: int, currency: string, lines: array<int, array{description: string|null, amount: int}>}
     */
    public function previewCard(Subscription $subscription, BillingPackage $toPlan, array $moduleKeys, string $interval): array
    {
        $priceIds = $this->stripePriceIds($toPlan, $moduleKeys, $interval);

        if ($priceIds === []) {
            throw new InvalidArgumentException("El plan preferencial '{$toPlan->key}' no tiene precios sincronizados a Stripe; corré billing:sync-stripe.");
        }

        $prices = [];

        foreach ($priceIds as $priceId) {
            $prices[$priceId] = ['quantity' => 1];
        }

        $invoice = $subscription->previewInvoice($prices);

        return [
            'manual' => false,
            'proration_date' => $invoice?->subscriptionProrationDate(),
            'amount_due' => $invoice?->rawAmountDue() ?? 0,
            'currency' => 'MXN',
            'lines' => $this->mapLines($invoice),
        ];
    }

    /**
     * Apply a CARD swap: swap the Stripe subscription to the preferential plan's
     * module prices, then re-point the tenant's entitlements to the preferential
     * plan and recompute.
     *
     * Proration is left to Stripe (computed at now): the client is NEVER trusted
     * for the proration_date on this billing mutation, so no date is forwarded to
     * swap(). The preview shows an estimate; the charge that lands is Stripe's own
     * current proration, which a caller cannot pin to minimize the charge.
     *
     * @param  array<int, string>  $moduleKeys
     */
    public function applyCard(
        Tenant $tenant,
        Subscription $subscription,
        BillingPackage $toPlan,
        array $moduleKeys,
        string $interval,
    ): TenantEntitlement {
        if ($subscription->hasIncompletePayment()) {
            throw new InvalidArgumentException(
                'No se puede cambiar de plan mientras la suscripción tiene un pago incompleto.'
            );
        }

        $priceIds = $this->stripePriceIds($toPlan, $moduleKeys, $interval);

        if ($priceIds === []) {
            throw new InvalidArgumentException("El plan preferencial '{$toPlan->key}' no tiene precios sincronizados a Stripe; corré billing:sync-stripe.");
        }

        $prices = [];

        foreach ($priceIds as $priceId) {
            $prices[$priceId] = ['quantity' => 1];
        }

        // Stripe network I/O FIRST, outside the transaction. The proration_date is
        // deliberately omitted so Stripe computes the proration at now.
        $subscription->swap($prices);

        return $this->repointEntitlements($tenant, $toPlan);
    }

    /**
     * Preview a MANUAL swap: no Stripe proration. Report the new plan total.
     *
     * @param  array<int, string>  $moduleKeys
     * @return array{manual: true, message: string, new_price: int}
     */
    public function previewManual(BillingPackage $toPlan, array $moduleKeys, string $interval): array
    {
        $quote = $this->planPricingService->quoteByPlan($toPlan->key, $moduleKeys, $interval);

        return [
            'manual' => true,
            'message' => 'El nuevo precio preferencial aplica desde el próximo ciclo de facturación.',
            'new_price' => $quote['total'],
        ];
    }

    /**
     * Apply a MANUAL swap: re-point the tenant's active entitlements to the
     * preferential plan (source/expiry/interval preserved) and recompute.
     */
    public function applyManual(Tenant $tenant, BillingPackage $toPlan): TenantEntitlement
    {
        return $this->repointEntitlements($tenant, $toPlan);
    }

    /**
     * Re-grant every ACTIVE entitlement of the tenant under $toPlan (idempotent
     * upsert by (tenant, billable_module)), update tenant.billing_package_id and
     * recompute access — all in ONE transaction.
     */
    private function repointEntitlements(Tenant $tenant, BillingPackage $toPlan): TenantEntitlement
    {
        return DB::transaction(function () use ($tenant, $toPlan): TenantEntitlement {
            // Serialize concurrent swaps for this tenant: take the row lock INSIDE
            // the transaction (the Stripe swap already ran in applyCard, so the lock
            // is NEVER held across network I/O). A second swap blocks here until the
            // first commits, then re-points on top of a consistent state.
            Tenant::query()->lockForUpdate()->find($tenant->id);

            $entitlements = TenantEntitlement::query()
                ->where('tenant_id', $tenant->id)
                ->active()
                ->with('billable_module')
                ->get();

            $last = null;

            foreach ($entitlements as $entitlement) {
                if ($entitlement->billable_module === null) {
                    continue;
                }

                $last = $this->entitlementService->grant(
                    $tenant,
                    $entitlement->billable_module,
                    $toPlan,
                    $entitlement->source,
                    [
                        'interval' => $entitlement->interval,
                        'expires_at' => $entitlement->expires_at,
                        'quantity' => $entitlement->quantity,
                        'stripe_subscription_id' => $entitlement->stripe_subscription_id,
                        'stripe_item_id' => $entitlement->stripe_item_id,
                        'metadata' => $entitlement->metadata,
                    ],
                    recompute: false,
                );
            }

            if ($last === null) {
                throw new InvalidArgumentException('La organización no tiene entitlements activas para cambiar de plan.');
            }

            $tenant->update(['billing_package_id' => $toPlan->id]);
            $this->entitlementService->recompute($tenant);

            return $last->fresh();
        });
    }

    /**
     * The synced Stripe price ids of the given modules in the plan at the interval.
     *
     * @param  array<int, string>  $moduleKeys
     * @return array<int, string>
     */
    private function stripePriceIds(BillingPackage $plan, array $moduleKeys, string $interval): array
    {
        $moduleKeys = array_values(array_unique($moduleKeys));

        if ($moduleKeys === []) {
            return [];
        }

        return PlanModulePrice::query()
            ->where('billing_package_id', $plan->id)
            ->where('interval', $interval)
            ->where('is_active', true)
            ->whereNotNull('stripe_price_id')
            ->whereHas('billable_module', fn ($query) => $query->whereIn('key', $moduleKeys))
            ->pluck('stripe_price_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Map the preview invoice line items to a compact {description, amount} shape.
     *
     * @return array<int, array{description: string|null, amount: int}>
     */
    private function mapLines(mixed $invoice): array
    {
        if ($invoice === null) {
            return [];
        }

        return collect($invoice->invoiceLineItems())
            ->map(fn ($item): array => [
                'description' => $item->description ?? null,
                'amount' => (int) ($item->amount ?? 0),
            ])
            ->values()
            ->all();
    }
}
