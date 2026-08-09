<?php

namespace Modules\SubscriptionPlan\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Laravel\Cashier\Subscription;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\PreferentialSwapService;
use Modules\Tenant\Models\Tenant;

/**
 * Super-admin preferential-PLAN swap endpoints (billing v2):
 *   - POST tenant/{id}/preferential-swap/preview   proration preview (card) or
 *                                                   next-cycle notice (manual)
 *   - POST tenant/{id}/preferential-swap           perform the swap
 *
 * The HTTP layer resolves the target preferential plan, the organization's contracted
 * modules + interval (from its active entitlements) and the billing reality (CARD
 * via the account owner's Stripe subscription, else MANUAL), then delegates ALL
 * billing logic to PreferentialSwapService. Domain failures surface as 422.
 *
 * Custom (non-Orion) controller; super-admin gate mirrors
 * SubscriptionPaymentController (update.tenant is super-admin exclusive).
 */
class PreferentialSwapController extends Controller
{
    public function __construct(
        private readonly PreferentialSwapService $preferentialSwapService,
    ) {
        $this->middleware(function ($request, $next) {
            $user = auth()->guard('sanctum')->user();

            if (! $user || ! ($user->tokenCan('update.tenant') || $user->tokenCan('*'))) {
                throw new AuthorizationException('Unauthorized action.');
            }

            return $next($request);
        });
    }

    /**
     * Preview the swap. CARD → {proration_date, amount_due, lines}; MANUAL →
     * {manual:true, message, new_price}.
     */
    public function preview(Request $request, string $tenantId): JsonResponse
    {
        $validated = $request->validate([
            'to_plan_key' => ['required', 'string'],
        ]);

        $tenant = Tenant::findOrFail($tenantId);

        try {
            $context = $this->resolveContext($tenant, $validated['to_plan_key']);

            $payload = $context['subscription'] !== null
                ? $this->preferentialSwapService->previewCard($context['subscription'], $context['to'], $context['module_keys'], $context['interval'])
                : $this->preferentialSwapService->previewManual($context['to'], $context['module_keys'], $context['interval']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * Perform the swap. Proration is computed server-side by Stripe at apply
     * time — a client-supplied proration_date is never trusted.
     */
    public function swap(Request $request, string $tenantId): JsonResponse
    {
        $validated = $request->validate([
            'to_plan_key' => ['required', 'string'],
        ]);

        $tenant = Tenant::findOrFail($tenantId);

        try {
            $context = $this->resolveContext($tenant, $validated['to_plan_key']);

            $entitlement = $context['subscription'] !== null
                ? $this->preferentialSwapService->applyCard(
                    $tenant,
                    $context['subscription'],
                    $context['to'],
                    $context['module_keys'],
                    $context['interval'],
                )
                : $this->preferentialSwapService->applyManual($tenant, $context['to']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Plan preferencial aplicado.',
            'data' => $entitlement->load(['billable_module:id,key,name', 'billing_package:id,key,name']),
        ]);
    }

    /**
     * Resolve the swap context: the target preferential plan, the organization's
     * contracted module keys + interval (from its active entitlements), and (for a
     * CARD organization) the active Stripe subscription to swap.
     *
     * @return array{to: BillingPackage, module_keys: array<int, string>, interval: string, subscription: Subscription|null}
     *
     * @throws InvalidArgumentException
     */
    private function resolveContext(Tenant $tenant, string $toKey): array
    {
        $to = BillingPackage::query()
            ->where('key', $toKey)
            ->where('is_active', true)
            ->whereHas('plan_prices')
            ->first();

        if ($to === null || $to->is_public !== false) {
            throw new InvalidArgumentException('El plan destino debe ser una variante preferencial activa (oculta).');
        }

        $entitlements = TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->active()
            ->with('billable_module:id,key')
            ->get();

        $moduleKeys = $entitlements
            ->map(fn (TenantEntitlement $e): ?string => $e->billable_module?->key)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($moduleKeys === []) {
            throw new InvalidArgumentException('El organización no tiene entitlements activas para cambiar de plan.');
        }

        $interval = $entitlements->pluck('interval')->filter()->first() ?: PlanModulePrice::INTERVAL_MONTH;

        $subscription = null;

        if ($tenant->payment_method === Tenant::PAYMENT_METHOD_CARD) {
            $subscription = $this->activeSubscriptionForTenant($tenant);

            if ($subscription === null) {
                throw new InvalidArgumentException(
                    'El organización paga con tarjeta pero no se pudo resolver una suscripción Stripe activa (owner ausente o ambiguo).'
                );
            }
        }

        return [
            'to' => $to,
            'module_keys' => $moduleKeys,
            'interval' => $interval,
            'subscription' => $subscription,
        ];
    }

    /**
     * The active Stripe subscription of the organization's single account owner, or null.
     */
    private function activeSubscriptionForTenant(Tenant $tenant): ?Subscription
    {
        $owners = $tenant->owners()->get();

        if ($owners->count() !== 1) {
            return null;
        }

        /** @var User $owner */
        $owner = $owners->first();

        $subscription = $owner->subscription('default');

        return ($subscription !== null && $subscription->active()) ? $subscription : null;
    }
}
