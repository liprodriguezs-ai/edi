<?php

use Illuminate\Support\Facades\Route;
use Modules\Configuration\Http\Controllers\ConfigurationController;

Route::middleware('throttle:api')->group(function (): void {
    // Custom (non-Orion) route contract: reject refresh tokens used as access
    // tokens and require an auth-active user, before resolving tenancy.
    $tenancyMiddleware = ['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'];

    Route::middleware($tenancyMiddleware)->group(function (): void {
        Route::get('config/general', [ConfigurationController::class, 'getGeneral']);
        Route::put('config/general', [ConfigurationController::class, 'updateGeneral']);

        // Branding media (§16.3.2)
        Route::post('config/branding/logo', [ConfigurationController::class, 'uploadLogo']);

        Route::get('config/operational', [ConfigurationController::class, 'getOperational']);
        Route::put('config/operational', [ConfigurationController::class, 'updateOperational']);

        Route::get('config/collection-settings', [ConfigurationController::class, 'getCollectionSettings']);
        Route::put('config/collection-settings', [ConfigurationController::class, 'updateCollectionSettings']);

        // Resident-accessible: primary bank data, honoring the visibility flag (§16.3.4).
        Route::get('config/bank-data', [ConfigurationController::class, 'getResidentBankData']);

        Route::get('config/notification-templates', [ConfigurationController::class, 'getNotificationTemplates']);
        Route::put('config/notification-templates', [ConfigurationController::class, 'updateNotificationTemplates']);
        Route::post('config/notification-templates/restore', [ConfigurationController::class, 'restoreDefaultTemplates']);

        // Structure summary (§16.3.3)
        Route::get('config/structure', [ConfigurationController::class, 'getStructure']);

        // Subscription self-service (§16.2 / §16.3.7) — gated by manage.subscription.
        Route::get('config/subscription', [ConfigurationController::class, 'getSubscription']);

        // Self-service management of the live subscription: proration preview +
        // change interval. Owner-scoped, manage.subscription. The
        // api/config/subscription* prefix is allowlisted in EnsureSubscriptionActive,
        // so a blocked owner can still manage/pay.
        //
        // Per-module self-serve (add-addon / remove-addon / start-module-trial) and
        // change-units were removed in the 2026-07 collapse: there is a single
        // sellable module and its price does not depend on the unit count.
        Route::post('config/subscription/preview', [ConfigurationController::class, 'previewSubscriptionChange']);
        Route::post('config/subscription/change-interval', [ConfigurationController::class, 'changeSubscriptionInterval']);

        // Change the whole billing plan (re-maps every contracted line in one
        // prorated swap / re-points on manual).
        Route::post('config/subscription/change-billing-plan', [ConfigurationController::class, 'changeBillingPlan']);
        Route::post('config/subscription/change-billing-plan/preview', [ConfigurationController::class, 'previewBillingPlanChange']);

        // Real Stripe billing (Cashier): hosted billing portal, cancel.
        Route::get('config/subscription/portal', [ConfigurationController::class, 'subscriptionPortal']);
        Route::post('config/subscription/cancel', [ConfigurationController::class, 'cancelSubscription']);
        Route::post('config/subscription/resume', [ConfigurationController::class, 'resumeSubscription']);

        // Package settings (package-policies spec — reuses read/update.configuration)
        Route::get('config/package-settings', [ConfigurationController::class, 'getPackageSettings']);
        Route::put('config/package-settings', [ConfigurationController::class, 'updatePackageSettings']);

        // Complaint settings (complaints design §10 — reuses read/update.configuration)
        Route::get('config/complaint-settings', [ConfigurationController::class, 'getComplaintSettings']);
        Route::put('config/complaint-settings', [ConfigurationController::class, 'updateComplaintSettings']);

        // Delinquency restriction settings (reuses read/update.configuration)
        Route::get('config/delinquency-settings', [ConfigurationController::class, 'getDelinquencySettings']);
        Route::put('config/delinquency-settings', [ConfigurationController::class, 'updateDelinquencySettings']);

        // Notification config (per-event channels + board-role recipients)
        Route::get('config/notifications', [ConfigurationController::class, 'getNotificationConfig']);
        Route::put('config/notifications', [ConfigurationController::class, 'updateNotificationConfig']);

        // Legal documents (§16.3.8)
        Route::get('config/legal', [ConfigurationController::class, 'listLegalDocuments']);
        Route::post('config/legal', [ConfigurationController::class, 'uploadLegalDocument']);
        // Resident-accessible: current visible document of a type (e.g. aviso de privacidad).
        Route::get('config/legal/{type}/current', [ConfigurationController::class, 'showCurrentLegalDocument']);
    });
});
