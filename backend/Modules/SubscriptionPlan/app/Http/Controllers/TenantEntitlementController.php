<?php

namespace Modules\SubscriptionPlan\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\EntitlementService;
use Modules\SubscriptionPlan\Services\PlanPricingService;
use Modules\Tenant\Models\Tenant;

/**
 * Super-admin entitlement administration for a organization (Slice 6 / A):
 *   - POST tenant/{id}/entitlement/grant-free   grant a package free, perpetually
 *   - POST tenant/{id}/entitlement/revoke       revoke a package's access
 *
 * "Activate free" (#10) gives a organization a package with source=super_admin_free
 * and expires_at=null — perpetual, never billed, the projector enables the
 * package's modules. "Revoke" expires the entitlement (soft: expires_at=now) and
 * recomputes access, but REFUSES to strip the organization's last base package (a
 * organization with no base has no working platform).
 *
 * Custom (non-Orion) controller: it defines its own constructor and does NOT call
 * parent::__construct(). The super-admin gate mirrors SubscriptionPaymentController
 * (authz-02): update.tenant is super-admin exclusive in the seeded matrix.
 */
class TenantEntitlementController extends Controller
{
    public function __construct(
        private readonly EntitlementService $entitlementService,
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

    /**
     * Grant a module to the organization free of charge, perpetually
     * (source=super_admin_free, expires_at=null), under a plan. recompute() runs
     * inside grant().
     */
    public function grantFree(Request $request, string $tenantId): JsonResponse
    {
        $validated = $request->validate([
            'module_key' => ['required', 'string'],
            'plan_key' => ['nullable', 'string'],
        ]);

        $tenant = Tenant::findOrFail($tenantId);
        $module = $this->activeModuleOrFail($validated['module_key']);
        $plan = $this->resolvePlanOrFail($validated['plan_key'] ?? null, $tenant);

        $this->assertNoLiveStripeClobber($tenant, $module);

        $entitlement = $this->entitlementService->grant(
            $tenant,
            $module,
            $plan,
            TenantEntitlement::SOURCE_SUPER_ADMIN_FREE,
            ['expires_at' => null],
        );

        return response()->json([
            'message' => 'Acceso gratuito activado para el organización.',
            'data' => $entitlement->load(['billable_module:id,key,name', 'billing_package:id,key,name']),
        ], 201);
    }

    /**
     * Start an INTERNAL free trial of a module for the organization under its CURRENT plan
     * (tenant.billing_package_id), source=trial. SUPER-ADMIN ONLY: the owner
     * self-serve counterpart was removed in the 2026-07 collapse, so this is now
     * the sole entry point. Guards live in EntitlementService::startModuleTrial
     * (offered-by-plan, base-active-for-addon, one-trial-per-module,
     * not-already-active) → 422. NO Stripe.
     */
    public function startTrial(Request $request, string $tenantId): JsonResponse
    {
        $validated = $request->validate([
            'module_key' => ['required', 'string'],
        ]);

        $tenant = Tenant::findOrFail($tenantId);

        try {
            $entitlement = $this->entitlementService->startModuleTrial($tenant, $validated['module_key']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Prueba gratuita activada para el organización.',
            'data' => $entitlement->load(['billable_module:id,key,name', 'billing_package:id,key,name']),
        ], 201);
    }

    /**
     * Revoke a module: expire the entitlement (soft) and recompute module access.
     * Refuses to revoke the organization's only active base module.
     */
    public function revoke(Request $request, string $tenantId): JsonResponse
    {
        $validated = $request->validate([
            'module_key' => ['required', 'string'],
        ]);

        $tenant = Tenant::findOrFail($tenantId);
        $module = $this->activeModuleOrFail($validated['module_key']);

        $entitlement = TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_module_id', $module->id)
            ->first();

        if ($entitlement === null) {
            return response()->json([
                'message' => 'El organización no tiene una entitlement para este módulo.',
            ], 422);
        }

        $isActive = $entitlement->expires_at === null || $entitlement->expires_at->isFuture();

        // BM1 — refuse to revoke an ACTIVE stripe entitlement. Expiring the row here
        // only severs the access projection; it keeps source=stripe and the
        // stripe_subscription_id, so Stripe keeps charging the organization and the next
        // invoice.payment_succeeded re-grants it. The operator must cancel the Stripe
        // subscription first (which expires the entitlement), then access drops on
        // its own. Mirrors the recordManual / grant() clobber guard.
        if ($isActive && $entitlement->source === TenantEntitlement::SOURCE_STRIPE) {
            return response()->json([
                'message' => 'Este módulo tiene una suscripción Stripe activa. Cancela la suscripción en Stripe (o cambia el método de pago del organización) antes de revocar el acceso; de lo contrario la próxima factura lo reactivaría.',
            ], 422);
        }

        // F-03 — the old "never strip the organization's last active base" guard was
        // REMOVED. After the 2026-07 collapse there is a single billable module
        // (`base`) and a unique index on (tenant_id, billable_module_id), so an
        // entitled organization ALWAYS has exactly one active base: the guard fired on
        // every revoke and made a mistakenly granted courtesy impossible to withdraw.
        // Leaving the organization with no active entitlement (= blocked) is precisely the
        // operator's intent; recompute() below turns its modules off.

        // Expire the entitlement and recompute access in ONE transaction so there is
        // no window where the data says "revoked" but the modules are still on (or
        // vice versa). Mirrors recordManual/applyManual; recompute() opens a nested
        // savepoint, so both halves commit or roll back together.
        DB::transaction(function () use ($entitlement, $tenant): void {
            $entitlement->update(['expires_at' => now()]);
            $this->entitlementService->recompute($tenant);
        });

        return response()->json([
            'message' => 'Acceso revocado.',
            'data' => $entitlement->fresh(),
        ]);
    }

    /**
     * A2 — a super-admin courtesy grant must NEVER silently clobber a live Stripe
     * entitlement. EntitlementService::grant() already refuses the DB write (the
     * centralized clobber guard at EntitlementService.php:110-115) and returns the
     * existing stripe row unchanged — which, left unhandled here, would answer 201
     * as if the free grant had happened. Check the same condition up front so the
     * caller gets an honest 409 naming the live subscription instead.
     */
    private function assertNoLiveStripeClobber(Tenant $tenant, BillableModule $module): void
    {
        $existing = TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_module_id', $module->id)
            ->first();

        $isLiveStripe = $existing !== null
            && $existing->source === TenantEntitlement::SOURCE_STRIPE
            && ($existing->expires_at === null || $existing->expires_at->greaterThan(now()));

        if ($isLiveStripe) {
            abort(409, "Este módulo tiene una suscripción Stripe activa ({$existing->stripe_subscription_id}). Cancela la suscripción en Stripe antes de otorgar un acceso gratuito, de lo contrario la próxima factura lo reactivaría.");
        }
    }

    /**
     * Resolve an ACTIVE billable module by key or 422.
     */
    private function activeModuleOrFail(string $key): BillableModule
    {
        $module = BillableModule::query()
            ->where('key', $key)
            ->where('is_active', true)
            ->first();

        abort_if($module === null, 422, "Módulo inválido o inactivo: {$key}.");

        return $module;
    }

    /**
     * Resolve the plan for a grant: explicit plan_key, else the tenant's current
     * plan (billing_package_id), else the default (smallest) plan.
     */
    private function resolvePlanOrFail(?string $planKey, Tenant $tenant): BillingPackage
    {
        if ($planKey !== null && $planKey !== '') {
            // F-05 — a courtesy grant only REFERENCES the plan, it never charges, so
            // the price-less consultative plan (a_medida) is a legitimate target.
            // Aligned with TenantProvisioningService, which already passed this.
            $plan = $this->planPricingService->planByKey($planKey, includeContactSales: true);

            abort_if($plan === null, 422, "Plan inválido o inactivo: {$planKey}.");

            return $plan;
        }

        if ($tenant->billing_package_id !== null) {
            $plan = BillingPackage::query()->whereKey($tenant->billing_package_id)->first();

            if ($plan !== null) {
                return $plan;
            }
        }

        return $this->planPricingService->planForUnits(null);
    }
}
