<?php

use Illuminate\Support\Facades\Route;
use Modules\Collections\Http\Controllers\AmountModeController;
use Modules\Collections\Http\Controllers\ChargeBatchController;
use Modules\Collections\Http\Controllers\ChargeBatchStatusController;
use Modules\Collections\Http\Controllers\ChargeConceptCategoryController;
use Modules\Collections\Http\Controllers\ChargeConceptController;
use Modules\Collections\Http\Controllers\ChargeController;
use Modules\Collections\Http\Controllers\ChargePolicyController;
use Modules\Collections\Http\Controllers\ChargeStatusController;
use Modules\Collections\Http\Controllers\CollectionsReportController;
use Modules\Collections\Http\Controllers\CreditBalanceController;
use Modules\Collections\Http\Controllers\DelinquencyRestrictionController;
use Modules\Collections\Http\Controllers\PaymentApplicationController;
use Modules\Collections\Http\Controllers\PaymentController;
use Modules\Collections\Http\Controllers\PaymentMethodController;
use Modules\Collections\Http\Controllers\PaymentStatusController;
use Modules\Collections\Http\Controllers\PeriodicityController;
use Modules\Collections\Http\Controllers\SurchargeModeController;
use Orion\Facades\Orion;

/*
 *--------------------------------------------------------------------------
 * API Routes
 *--------------------------------------------------------------------------
 *
 * Here is where you can register API routes for your application. These
 * routes are loaded by the RouteServiceProvider within a group which
 * is assigned the "api" middleware group. Enjoy building your API!
 *
*/
// moduleEnabled:charge_batch runs AFTER initializeTenancy so the billable "Cobros"
// module (permission_entity=charge_batch) is enforced per-tenant: when the base
// package is not entitled, EntitlementService flips module_tenant.enabled=false and
// every Collections endpoint returns 403 MODULE_DISABLED (super-admin/lawyer bypass).
$tenancyMiddleware = ['auth:sanctum', 'initializeTenancy', 'moduleEnabled:charge_batch'];

// Custom (non-Orion) actions also reject refresh tokens and inactive accounts.
$customActionMiddleware = ['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy', 'moduleEnabled:charge_batch'];

Route::middleware('throttle:api')->group(function () use ($tenancyMiddleware, $customActionMiddleware) {

    // ── Catalog resources ──────────────────────────────────────────────────
    Orion::resource('charge_status', ChargeStatusController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    Orion::resource('payment_status', PaymentStatusController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    Orion::resource('charge_batch_status', ChargeBatchStatusController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    Orion::resource('charge_concept_category', ChargeConceptCategoryController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    Orion::resource('amount_mode', AmountModeController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    Orion::resource('periodicity', PeriodicityController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    Orion::resource('payment_method', PaymentMethodController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    Orion::resource('surcharge_mode', SurchargeModeController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    // ── Core business resources ────────────────────────────────────────────
    Orion::resource('charge_concept', ChargeConceptController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    Orion::resource('charge_policy', ChargePolicyController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    Orion::resource('charge_batch', ChargeBatchController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    Orion::resource('charge', ChargeController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    // LEDGER TABLES ARE READ-ONLY VIA ORION (findings collections-01 / sec-money-01 /
    // sec-input-02): payments mutate ONLY through the custom endpoints below
    // (register/submit/validate/reject/cancel) and payment_application/credit_balance
    // are written exclusively by PaymentService. No store/update/destroy/restore routes.
    Orion::resource('payment', PaymentController::class)
        ->only(['index', 'search', 'show'])
        ->middleware($tenancyMiddleware);

    Orion::resource('payment_application', PaymentApplicationController::class)
        ->only(['index', 'search', 'show'])
        ->middleware($tenancyMiddleware);

    Orion::resource('credit_balance', CreditBalanceController::class)
        ->only(['index', 'search', 'show'])
        ->middleware($tenancyMiddleware);

    // GET /my-restrictions — resident-facing delinquency state (R-DELQ per-unit
    // payload, design §6.2). Deliberately NOT gated by moduleEnabled:charge_batch
    // nor an ability: the payload only ever describes the caller's OWN units.
    // rejectRefreshToken + authActive ARE required though (tenancy-reviewer
    // WARNING — design §6.2 under-specified the chain): this route never calls
    // tokenCan(), so without rejectRefreshToken a stolen 7-day refresh cookie
    // would work as an access token here (sec-authn-04); without authActive a
    // deactivated user could still read their own restriction state and
    // overdue balance (sec-authn-10) — every sibling custom-action route in
    // this file and in Package's routes carries both.
    Route::middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'])->group(function () {
        Route::get('my-restrictions', [DelinquencyRestrictionController::class, 'myRestrictions']);
    });

    // ── Custom action endpoints ────────────────────────────────────────────
    Route::middleware($customActionMiddleware)->group(function () {
        // Activate a draft batch → generate charges.
        Route::post('charge-batch/{id}/activate', [ChargeBatchController::class, 'activate']);

        // Cancel a batch AND its unpaid charges (422 if any charge has payments).
        Route::post('charge-batch/{id}/cancel', [ChargeBatchController::class, 'cancelBatch']);

        // Preview target count for a proposed selector.
        Route::post('charge-batch/preview', [ChargeBatchController::class, 'preview']);

        // Server-side KPI aggregation over ALL non-cancelled charges in the batch.
        Route::get('charge-batch/{id}/summary', [ChargeBatchController::class, 'summary']);

        // Register a payment via PaymentService (FIFO/manual apply).
        Route::post('payment/register', [PaymentController::class, 'register']);

        // Resident self-captures a payment for their own unit (→ pending_validation).
        Route::post('payment/submit', [PaymentController::class, 'submit']);

        // Tesorero/Admin validates a pending payment (→ confirmed, FIFO applied).
        Route::post('payment/{id}/validate', [PaymentController::class, 'validatePayment']);

        // Tesorero/Admin rejects a pending payment (→ cancelled, with reason).
        Route::post('payment/{id}/reject', [PaymentController::class, 'reject']);

        // Cancel a payment and reverse all applications.
        Route::post('payment/{id}/cancel', [PaymentController::class, 'cancel']);

        // Direct counter charge: create an ad-hoc charge AND cobra it in one atomic act
        // (payment PINNED to the new charge — FIFO never diverts it to old debt).
        Route::post('charge/direct', [ChargeController::class, 'direct']);

        // Cancel a charge (requires reason, no payments applied).
        Route::post('charge/{id}/cancel', [ChargeController::class, 'cancel']);

        // Apply a discount / condonación to a charge (President/Admin only).
        Route::post('charge/{id}/discount', [ChargeController::class, 'discount']);

        // Account statement for a unit.
        Route::get('unit/{id}/statement', [PaymentController::class, 'statement']);

        // Delinquency dashboard.
        Route::get('delinquency', [PaymentController::class, 'delinquency']);

        // Delinquency xlsx export (same aggregation as GET /delinquency; report.payment).
        Route::get('delinquency/report', [PaymentController::class, 'delinquencyReport']);

        // Collections aging dashboard report (read.report_collections).
        Route::get('collections/aging', [CollectionsReportController::class, 'aging']);
    });
});
