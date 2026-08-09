<?php

use Illuminate\Support\Facades\Route;
use Modules\Package\Http\Controllers\PackageCarrierController;
use Modules\Package\Http\Controllers\PackageController;
use Modules\Package\Http\Controllers\PackageReportController;
use Modules\Package\Http\Controllers\PackageStatusController;
use Modules\Package\Http\Controllers\PackageTypeController;
use Orion\Facades\Orion;

Route::middleware('throttle:api')->group(function (): void {
    // moduleEnabled:package runs AFTER initializeTenancy so the billable "Paquetería"
    // add-on module is enforced per-tenant: without the add-on entitlement
    // EntitlementService flips module_tenant.enabled=false and every Package endpoint
    // returns 403 MODULE_DISABLED (super-admin/lawyer bypass).
    $middleware = ['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy', 'moduleEnabled:package'];

    Route::middleware($middleware)->group(function () use ($middleware): void {
        // CUSTOM ROUTES FIRST — literal paths must be declared before Orion::resource
        // so the `package/{id}` wildcard does not swallow them and fail UUID parsing.
        // NOTE: /intake-batch avoids collision with Orion's own POST /package/batch
        // (Orion batch-store expects `resources`; our endpoint uses `packages`).
        Route::post('package/intake-batch', [PackageController::class, 'batchRegister']);
        // BEFORE Orion::resource — otherwise `package/{id}` swallows this literal
        // path and fails UUID parsing (route-order gotcha, design §6.3).
        Route::get('package/restricted-units', [PackageController::class, 'restrictedUnits']);
        Route::post('package/verify-code', [PackageController::class, 'verifyCode']); // WU-2
        Route::post('package/handover', [PackageController::class, 'handover']); // WU-2
        Route::get('my-packages', [PackageController::class, 'myPackages']); // WU-2
        // NOTE: string {packageId} param (NOT implicit {package} binding). Implicit binding
        // is resolved by SubstituteBindings BEFORE InitializeTenancyFromUser runs, so a bound
        // model would be fetched across ALL tenants (TenantScope is inert until tenancy is
        // initialized) — a cross-tenant write hole. Resolving inside the controller (after
        // initializeTenancy) keeps the lookup tenant-scoped, matching every sibling module.
        Route::post('package/{packageId}/return', [PackageController::class, 'returnPackage']); // WU-2
        Route::post('package/{packageId}/incident', [PackageController::class, 'flagIncident']); // WU-2
        Route::post('package/{packageId}/reassign', [PackageController::class, 'reassignUnit']); // WU-2
        Route::get('package/pickup-policy', [PackageController::class, 'pickupPolicy']); // BUG-1: guards read policy without read.configuration
        Route::get('package/report/pending-count', [PackageReportController::class, 'pendingCount']); // WU-5
        Route::get('package/report/aging', [PackageReportController::class, 'aging']); // WU-5
        Route::get('package/report/aging/export', [PackageReportController::class, 'export']); // WU-5

        // ORION RESOURCES (after custom routes)
        Orion::resource('package', PackageController::class)
            ->withSoftDeletes()
            ->middleware($middleware);

        Orion::resource('package_status', PackageStatusController::class)
            ->only(['index', 'show'])
            ->middleware($middleware);

        Orion::resource('package_carrier', PackageCarrierController::class)
            ->withSoftDeletes()
            ->middleware($middleware);

        Orion::resource('package_type', PackageTypeController::class)
            ->withSoftDeletes()
            ->middleware($middleware);
    });
});
