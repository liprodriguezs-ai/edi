<?php

namespace Modules\SubscriptionPlan\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\SubscriptionPayment;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\PlanPricingService;
use Modules\Tenant\Models\Tenant;

/**
 * Super-admin READ aggregate for a cluster's billing screen (billing v2, per
 * module): GET tenant/{id}/billing.
 *
 * Returns, in one payload, the cluster's payment method + status + current plan,
 * its PER-MODULE entitlements (what it is allowed to use, under which plan, and
 * why), the catalog of assignable modules + plans, and the manual-payment ledger.
 *
 * Custom (non-Orion) read controller; super-admin gate (update.tenant) mirrors
 * SubscriptionPaymentController / TenantEntitlementController.
 */
class TenantBillingController extends Controller
{
    public function __construct(
        private readonly PlanPricingService $planPricingService,
    ) {
        $this->middleware(function ($request, $next) {
            $user = auth()->guard('sanctum')->user();

            if (! $user || ! ($user->tokenCan('update.tenant') || $user->tokenCan('*'))) {
                throw new AuthorizationException('Unauthorized action.');
            }

            return $next($request);
        });
    }

    public function show(string $tenantId): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);

        $entitlements = $this->loadEntitlements($tenant);

        return response()->json([
            'data' => [
                'payment_method' => $tenant->payment_method,
                'status' => $tenant->status,
                'plan' => $this->currentPlan($tenant),
                'entitlements' => $this->entitlements($entitlements),
                'available_modules' => $this->availableModules(),
                'available_plans' => $this->planPricingService->planCatalog(includeHidden: true)['plans'],
                'manual_payment' => $this->manualPayment($entitlements),
                'ledger' => $this->ledger($tenant),
                'ledger_total' => $this->ledgerTotal($tenant),
            ],
        ]);
    }

    /**
     * The cluster's entitlements (active AND expired) with the module + plan data
     * needed to derive a per-module price.
     *
     * @return Collection<int, TenantEntitlement>
     */
    private function loadEntitlements(Tenant $tenant): Collection
    {
        return TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->with([
                'billable_module:id,key,name,is_base',
                'billing_package:id,key,name',
            ])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @return array{key: string, name: string}|null
     */
    private function currentPlan(Tenant $tenant): ?array
    {
        $tenant->loadMissing('billing_package:id,key,name');

        return $tenant->billing_package !== null
            ? ['key' => $tenant->billing_package->key, 'name' => $tenant->billing_package->name]
            : null;
    }

    /**
     * Per-module entitlements (active AND expired). Each row carries the plan it was
     * granted under and `amount`: the plan-derived price of this module at the
     * entitlement's interval. Courtesy (super_admin_free) rows are $0; an
     * unpriced/misconfigured cell is null.
     *
     * @param  Collection<int, TenantEntitlement>  $entitlements
     * @return array<int, array<string, mixed>>
     */
    private function entitlements(Collection $entitlements): array
    {
        return $entitlements
            ->map(fn (TenantEntitlement $entitlement): array => [
                'module_key' => $entitlement->billable_module?->key,
                'module_name' => $entitlement->billable_module?->name,
                'plan_key' => $entitlement->billing_package?->key,
                'plan_name' => $entitlement->billing_package?->name,
                'source' => $entitlement->source,
                'interval' => $entitlement->interval,
                'amount' => $this->priceFor($entitlement),
                'expires_at' => $entitlement->expires_at,
            ])
            ->all();
    }

    /**
     * Suggested manual payment that covers EVERY paid contracted module: one line
     * per module the cluster pays out-of-band (source NOT courtesy and NOT stripe),
     * each with its plan-derived amount, plus the grand total.
     *
     * @param  Collection<int, TenantEntitlement>  $entitlements
     * @return array{lines: array<int, array<string, mixed>>, total: int}
     */
    private function manualPayment(Collection $entitlements): array
    {
        $lines = $entitlements
            ->filter(fn (TenantEntitlement $entitlement): bool => $entitlement->billable_module !== null
                && $entitlement->billing_package !== null
                && ! in_array($entitlement->source, [
                    TenantEntitlement::SOURCE_SUPER_ADMIN_FREE,
                    TenantEntitlement::SOURCE_STRIPE,
                ], true))
            ->map(fn (TenantEntitlement $entitlement): array => [
                'module_key' => $entitlement->billable_module->key,
                'name' => $entitlement->billable_module->name,
                'plan_key' => $entitlement->billing_package->key,
                'interval' => $this->intervalFor($entitlement),
                'amount' => $this->priceFor($entitlement),
            ])
            ->values()
            ->all();

        $total = array_sum(array_map(static fn (array $line): int => (int) ($line['amount'] ?? 0), $lines));

        return [
            'lines' => $lines,
            'total' => $total,
        ];
    }

    /**
     * Plan-derived price for an entitlement's module at its interval, in minor units.
     * Courtesy (super_admin_free) is always $0; an unpriced/misconfigured cell is
     * null (surface for manual entry, never 500).
     */
    private function priceFor(TenantEntitlement $entitlement): ?int
    {
        $module = $entitlement->billable_module;
        $plan = $entitlement->billing_package;

        if ($module === null || $plan === null) {
            return null;
        }

        if ($entitlement->source === TenantEntitlement::SOURCE_SUPER_ADMIN_FREE) {
            return 0;
        }

        try {
            return $this->planPricingService->priceForModule($plan, $module, $this->intervalFor($entitlement));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Pricing interval for an entitlement: its stored interval, or monthly when
     * absent/invalid.
     */
    private function intervalFor(TenantEntitlement $entitlement): string
    {
        return in_array($entitlement->interval, PlanModulePrice::INTERVALS, true)
            ? $entitlement->interval
            : PlanModulePrice::INTERVAL_MONTH;
    }

    /**
     * Every assignable billable module.
     *
     * @return array<int, array<string, mixed>>
     */
    private function availableModules(): array
    {
        return BillableModule::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (BillableModule $module): array => [
                'key' => $module->key,
                'name' => $module->name,
                'is_base' => (bool) $module->is_base,
            ])
            ->all();
    }

    /**
     * Manual-payment ledger rows, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ledger(Tenant $tenant): array
    {
        return SubscriptionPayment::query()
            ->where('tenant_id', $tenant->id)
            ->with(['billable_module:id,key,name', 'billing_package:id,key,name'])
            ->orderByDesc('paid_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SubscriptionPayment $payment): array => [
                'module_key' => $payment->billable_module?->key,
                'plan_key' => $payment->billing_package?->key,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'method' => $payment->method,
                'paid_at' => $payment->paid_at,
                'period_end' => $payment->period_end,
                'notes' => $payment->notes,
            ])
            ->all();
    }

    /**
     * Sum of the cluster's ledger amounts (integer minor units).
     */
    private function ledgerTotal(Tenant $tenant): int
    {
        return (int) SubscriptionPayment::query()
            ->where('tenant_id', $tenant->id)
            ->sum('amount');
    }
}
