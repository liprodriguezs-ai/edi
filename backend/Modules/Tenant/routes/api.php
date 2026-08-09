<?php

use Illuminate\Support\Facades\Route;
use Modules\Tenant\Http\Controllers\TenantController;
use Modules\Tenant\Http\Controllers\TenantModuleController;
use Modules\Tenant\Http\Controllers\TenantProvisioningController;
use Modules\Tenant\Http\Controllers\TenantSystemController;
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
Route::middleware('throttle:api')->group(function () {
    Orion::resource('tenant', TenantController::class)->withSoftDeletes()->middleware(['auth:sanctum', 'initializeTenancy']);

    Route::middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive'])->group(function () {
        // Super-admin ALTA of a cluster + its billing in one step (trial / courtesy
        // / manual paid / card). Gate (create.tenant) lives in the controller. No
        // initializeTenancy: writes target the NEW tenant explicitly.
        Route::post('tenant/provision', [TenantProvisioningController::class, 'store']);

        // Tenant module management (enable/disable modules per tenant)
        Route::get('tenant/{tenantId}/modules', [TenantModuleController::class, 'index']);
        Route::patch('tenant/{tenantId}/modules/{moduleId}', [TenantModuleController::class, 'toggle']);
        Route::post('tenant/{tenantId}/modules/sync', [TenantModuleController::class, 'sync']);

        // Tenant system management (assign systems to tenants)
        Route::get('tenant/{tenantId}/systems', [TenantSystemController::class, 'index']);
        Route::post('tenant/{tenantId}/systems/addon', [TenantSystemController::class, 'addAddon']);
        Route::delete('tenant/{tenantId}/systems/{systemId}/addon', [TenantSystemController::class, 'removeAddon']);
    });
});
