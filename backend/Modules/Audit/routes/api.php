<?php

use Illuminate\Support\Facades\Route;
use Modules\Audit\Http\Controllers\AuditController;
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
    // §18.2 — the audit trail is IMMUTABLE and tenant-scoped (findings global-01 /
    // reports-01 / reports-02): read-only routes; rows are written exclusively by
    // the auditing pipeline. Tenant filtering lives in AuditController.
    Orion::resource('audit', AuditController::class)
        ->only(['index', 'search', 'show'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);
});
