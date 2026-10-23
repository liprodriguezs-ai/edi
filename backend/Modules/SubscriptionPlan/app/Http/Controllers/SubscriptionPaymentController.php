<?php

namespace Modules\SubscriptionPlan\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Laravel\Cashier\Subscription;
use Modules\SubscriptionPlan\Http\Requests\SubscriptionPaymentStoreRequest;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\SubscriptionPayment;
use Modules\SubscriptionPlan\Services\SubscriptionPaymentService;
use Modules\Tenant\Models\Tenant;

/**
 * Super-admin manual-billing endpoints for a organization:
 *   - POST   tenant/{id}/subscription-payment   record a manual (transfer/cash) payment
 *   - GET    tenant/{id}/subscription-payment   the organization's payment ledger
 *   - PATCH  tenant/{id}/payment-method         switch card↔transfer/cash
 *
 * Custom (non-Orion) controller: it defines its own constructor and does NOT
 * call parent::__construct() (no $model / CRUD machinery). The super-admin gate
 * mirrors TenantSystemController (authz-02): update.tenant is super-admin
 * exclusive in the seeded matrix, so these actions are platform-admin-only.
 */
class SubscriptionPaymentController extends Controller
{
    public function __construct(
        private readonly SubscriptionPaymentService $subscriptionPaymentService,
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
     * Ledger of a organization's subscription payments, newest first.
     */
    public function index(string $tenantId): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);

        $payments = SubscriptionPayment::query()
            ->where('tenant_id', $tenant->id)
            ->with(['billable_module:id,key,name', 'billing_package:id,key,name', 'recorded_by:id,name'])
            ->orderByDesc('paid_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $payments]);
    }

    /**
     * Record ONE manual (transfer/cash) payment that covers SEVERAL contracted
     * packages: each package gets its own ledger row and one interval of
     * entitlement extension; courtesy packages are excluded and a blocked organization
     * is reactivated. Returns every ledger row created.
     */
    public function store(SubscriptionPaymentStoreRequest $request, string $tenantId): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);
        $validated = $request->validated();
        $recordedBy = auth()->guard('sanctum')->user();

        $paidAt = isset($validated['paid_at'])
            ? Carbon::parse($validated['paid_at'])
            : now();

        // Resolve each line's module + plan up front; an unknown/inactive key is a
        // 422 (firstOrFail → ModelNotFound → 404 would be wrong for a body error, so
        // guard explicitly). The request already proved the keys exist.
        $lines = [];
        foreach ($validated['lines'] as $line) {
            $module = BillableModule::where('key', $line['module_key'])
                ->where('is_active', true)
                ->first();

            if ($module === null) {
                return response()->json([
                    'message' => "Módulo inválido o inactivo: {$line['module_key']}.",
                ], 422);
            }

            $plan = BillingPackage::where('key', $line['plan_key'])
                ->where('is_active', true)
                ->first();

            if ($plan === null) {
                return response()->json([
                    'message' => "Plan inválido o inactivo: {$line['plan_key']}.",
                ], 422);
            }

            $lines[] = [
                'module' => $module,
                'plan' => $plan,
                'amount' => (int) $line['amount'],
                'interval' => $line['interval'] ?? null,
            ];
        }

        // Domain guards in recordManual (idempotency double-submit, card/stripe
        // clobber, all-courtesy) throw InvalidArgumentException → surface as 422
        // (skill §2.10).
        try {
            $payments = $this->subscriptionPaymentService->recordManual(
                $tenant,
                $lines,
                $validated['method'],
                $paidAt,
                $validated['notes'] ?? null,
                $recordedBy,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Pago manual registrado',
            'data' => $payments
                ->map(fn (SubscriptionPayment $payment): SubscriptionPayment => $payment->load(['billable_module:id,key,name', 'billing_package:id,key,name', 'recorded_by:id,name']))
                ->values(),
        ], 201);
    }

    /**
     * Switch a organization's payment method. Moving off card cancels its active
     * Stripe subscription (at period end by default, immediately when
     * cancel_mode=now). The change is audited by the tenant model update.
     */
    public function updatePaymentMethod(Request $request, string $tenantId): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'in:'.implode(',', Tenant::PAYMENT_METHODS)],
            'cancel_mode' => ['nullable', 'string', 'in:'.implode(',', SubscriptionPaymentService::CANCEL_MODES)],
        ]);

        $tenant = Tenant::findOrFail($tenantId);

        $subscription = null;
        if ($validated['payment_method'] !== Tenant::PAYMENT_METHOD_CARD) {
            $owner = $this->accountOwnerForTenant($tenant);

            if ($owner !== null) {
                $subscription = $owner->subscription('default');
            } elseif ($this->tenantHasActiveSubscription($tenant)) {
                // FIX #5 — owner is ambiguous (0 or 2+ account owners) yet an active
                // Stripe subscription exists among the organization's users. We cannot
                // pick which owner's subscription to cancel, so completing the switch
                // would leave Stripe billing the organization after it "moved" to manual.
                // Refuse instead of silently orphaning the charge.
                Log::warning('No se pudo resolver la suscripción Stripe de la organización para cancelarla: account owner ambiguo.', [
                    'tenant_id' => $tenant->id,
                    'target_payment_method' => $validated['payment_method'],
                ]);

                return response()->json([
                    'message' => 'No se puede resolver la suscripción Stripe de la organización para cancelarla; resuelve el owner de la cuenta primero.',
                ], 422);
            }
        }

        $this->subscriptionPaymentService->changePaymentMethod(
            $tenant,
            $validated['payment_method'],
            $subscription,
            $validated['cancel_mode'] ?? SubscriptionPaymentService::CANCEL_AT_PERIOD_END,
        );

        return response()->json([
            'message' => 'Método de pago actualizado',
            'data' => ['payment_method' => $tenant->fresh()->payment_method],
        ]);
    }

    /**
     * Resolve THIS tenant's single account owner (null when missing or
     * ambiguous). Uses the uuid-based owners() relation rather than the Spatie
     * role() scope so it returns empty instead of throwing when the role is not
     * present (e.g. an unseeded environment).
     */
    private function accountOwnerForTenant(Tenant $tenant): ?User
    {
        $owners = $tenant->owners()->get();

        return $owners->count() === 1 ? $owners->first() : null;
    }

    /**
     * Whether ANY user of this tenant holds an active Stripe subscription. Used
     * only when the account owner cannot be resolved unambiguously (FIX #5): if
     * such a subscription exists we must not silently complete a switch to a
     * manual method, because there would be no resolvable owner to cancel it.
     */
    private function tenantHasActiveSubscription(Tenant $tenant): bool
    {
        $userIds = $tenant->users()->pluck('id');

        if ($userIds->isEmpty()) {
            return false;
        }

        return Subscription::query()
            ->whereIn('user_id', $userIds->all())
            ->active()
            ->exists();
    }
}
