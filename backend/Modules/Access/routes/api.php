<?php

use Illuminate\Support\Facades\Route;
use Modules\Access\Http\Controllers\AccessEntryTypeController;
use Modules\Access\Http\Controllers\AccessLogController;
use Modules\Access\Http\Controllers\AccessVerificationController;
use Modules\Access\Http\Controllers\BlacklistEntryController;
use Modules\Access\Http\Controllers\BlacklistTypeController;
use Modules\Access\Http\Controllers\QrTypeController;
use Modules\Access\Http\Controllers\VisitorController;
use Modules\Access\Http\Controllers\VisitorMotiveController;
use Modules\Access\Http\Controllers\VisitorStatusController;
use Orion\Facades\Orion;

Route::middleware('throttle:api')->group(function (): void {
    // moduleEnabled:access_log runs AFTER initializeTenancy so the billable "Control
    // de Accesos" module is enforced per-tenant: without the base entitlement
    // EntitlementService flips module_tenant.enabled=false and every Access endpoint
    // returns 403 MODULE_DISABLED (super-admin/lawyer bypass).
    $tenancyMiddleware = ['auth:sanctum', 'initializeTenancy', 'moduleEnabled:access_log'];
    // Custom (non-Orion) routes also reject refresh tokens and deactivated users.
    $customMiddleware = ['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy', 'moduleEnabled:access_log'];

    // Catalog resources
    Orion::resource('visitor_motive', VisitorMotiveController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    Orion::resource('visitor_status', VisitorStatusController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    Orion::resource('qr_type', QrTypeController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    Orion::resource('access_entry_type', AccessEntryTypeController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    Orion::resource('access_verification', AccessVerificationController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    Orion::resource('blacklist_type', BlacklistTypeController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    // Literal GET route MUST be registered BEFORE the Orion resource. Otherwise
    // the resource's show route (visitor/{id}) is matched first and parses "mine"
    // as a UUID id -> 500. mine() is self-scoped to the caller's own visitors.
    Route::middleware($customMiddleware)->group(function (): void {
        Route::get('visitor/mine', [VisitorController::class, 'mine']);
    });

    // Main resources
    Orion::resource('visitor', VisitorController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    // access_log is an IMMUTABLE audit trail (§14.5 r6): read-only via Orion.
    // Rows are born exclusively in AccessService (scan-qr / manual-entry /
    // entry-request / approve / reject) and mutate only through the dedicated
    // checkout + correction endpoints. 'search' is the list/filter endpoint the
    // bitácora UI uses (POST /access_log/search) — still read-only: NO
    // store/update/destroy/restore route.
    Orion::resource('access_log', AccessLogController::class)
        ->only(['index', 'show', 'search'])
        ->middleware($tenancyMiddleware);

    Orion::resource('blacklist_entry', BlacklistEntryController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    // Custom action routes
    Route::middleware($customMiddleware)->group(function (): void {
        Route::post('access/scan-qr', [AccessLogController::class, 'scanQr']);
        Route::post('access/manual-entry', [AccessLogController::class, 'manualEntry']);
        Route::post('access/entry-request', [AccessLogController::class, 'entryRequest']);
        Route::get('access/now', [AccessLogController::class, 'now']);
        Route::get('access/my-log', [AccessLogController::class, 'myLog']);
        Route::get('access/my-qr', [AccessLogController::class, 'myQr']);
        Route::post('access/{id}/checkout', [AccessLogController::class, 'checkout']);
        Route::post('access/{id}/correction', [AccessLogController::class, 'addCorrection']);
        Route::post('visitor/{id}/cancel', [VisitorController::class, 'cancelQr']);
        Route::post('visitor/{id}/approve', [VisitorController::class, 'approve']);
        Route::post('visitor/{id}/reject', [VisitorController::class, 'reject']);
    });
});
