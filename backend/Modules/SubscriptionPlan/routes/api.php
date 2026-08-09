<?php

use Illuminate\Support\Facades\Route;
use Modules\SubscriptionPlan\Http\Controllers\BillingCheckoutController;
use Modules\SubscriptionPlan\Http\Controllers\BillingPackageController;
use Modules\SubscriptionPlan\Http\Controllers\BillingPlanQuoteController;
use Modules\SubscriptionPlan\Http\Controllers\PlanModulePriceController;
use Modules\SubscriptionPlan\Http\Controllers\PreferentialSwapController;
use Modules\SubscriptionPlan\Http\Controllers\SubscriptionPaymentController;
use Modules\SubscriptionPlan\Http\Controllers\TenantBillingController;
use Modules\SubscriptionPlan\Http\Controllers\TenantEntitlementController;
use Orion\Facades\Orion;

// Public billing plan catalog + quoter for the landing pricing page. NO
// auth:sanctum and NO initializeTenancy: the catalog is central (identical for
// every tenant) and the quote is recomputed server-side from the plan_module_price
// matrix via PlanPricingService. Throttled per IP (60/min) to protect the
// unauthenticated surface — mirrors the public onboarding/auth route style in
// backend/routes/api.php.
Route::middleware('throttle:60,1')->group(function () {
    Route::get('billing/plan-catalog', [BillingPlanQuoteController::class, 'catalog']);
    Route::post('billing/plan-quote', [BillingPlanQuoteController::class, 'quote']);
});

Route::middleware('throttle:api')->group(function () {
    // Billing v2 plan admin (super admin, S4). CRUD of the plan catalog
    // (billing_package reused as a plan). Central — no initializeTenancy, mirroring
    // subscription_plan. The super-admin gate is automatic via
    // $keyForPermission='billing_package' (TraitController::authorize + tokenCan).
    Orion::resource('billing_package', BillingPackageController::class)->withSoftDeletes()->middleware('auth:sanctum');

    // Billing v2 price-matrix editor (super admin, S4). Custom (non-Orion) routes
    // for the plan × module price cells (plan_module_price). Central, super-admin
    // exclusive — same middleware contract as the other custom super-admin billing
    // routes below; the read/update gate lives in the controller constructor.
    Route::middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive'])->group(function () {
        Route::get('billing/plan/{billingPackage}/price-matrix', [PlanModulePriceController::class, 'show']);
        Route::put('billing/plan/{billingPackage}/price-matrix', [PlanModulePriceController::class, 'update']);
    });

    // Multi-item Stripe Checkout (Slice 2 / B1). ADDITIVE — runs in parallel to
    // the legacy single-plan checkout in Configuration. Custom (non-Orion) route
    // contract: reject refresh tokens, require an auth-active user, resolve
    // tenancy; the owner gate (account.owner) lives in the controller.
    Route::middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'])->group(function () {
        Route::post('billing/checkout', [BillingCheckoutController::class, 'checkout']);
    });

    // Manual billing for a organization (super admin): record a transfer/cash payment,
    // read the ledger, and switch the payment method. Gate (update.tenant) lives
    // in the controller constructor, mirroring TenantSystemController (authz-02).
    Route::middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive'])->group(function () {
        Route::get('tenant/{tenantId}/subscription-payment', [SubscriptionPaymentController::class, 'index']);
        Route::post('tenant/{tenantId}/subscription-payment', [SubscriptionPaymentController::class, 'store']);
        Route::patch('tenant/{tenantId}/payment-method', [SubscriptionPaymentController::class, 'updatePaymentMethod']);
    });

    // Read aggregate for the super-admin organization billing screen: payment method,
    // status, entitlements, the full assignable-package catalog (incl. hidden
    // preferential ones) and the manual-payment ledger. Gate (update.tenant)
    // lives in the controller, mirroring SubscriptionPaymentController.
    Route::middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive'])->group(function () {
        Route::get('tenant/{tenantId}/billing', [TenantBillingController::class, 'show']);
    });

    // Entitlement administration for a organization (super admin, Slice 6 / A):
    // activate a package free perpetually (#10) and revoke it. Gate
    // (update.tenant) lives in the controller, mirroring SubscriptionPaymentController.
    Route::middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive'])->group(function () {
        Route::post('tenant/{tenantId}/entitlement/grant-free', [TenantEntitlementController::class, 'grantFree']);
        Route::post('tenant/{tenantId}/entitlement/start-trial', [TenantEntitlementController::class, 'startTrial']);
        Route::post('tenant/{tenantId}/entitlement/revoke', [TenantEntitlementController::class, 'revoke']);
    });

    // Preferential (hidden, is_public=false) package swap for a organization (super
    // admin, Slice 6 / B): preview the proration (card) or next-cycle notice
    // (manual), then apply. Proration is computed server-side by Stripe at apply time.
    Route::middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive'])->group(function () {
        Route::post('tenant/{tenantId}/preferential-swap/preview', [PreferentialSwapController::class, 'preview']);
        Route::post('tenant/{tenantId}/preferential-swap', [PreferentialSwapController::class, 'swap']);
    });
});
