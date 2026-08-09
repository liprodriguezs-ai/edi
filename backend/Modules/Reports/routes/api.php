<?php

use Illuminate\Support\Facades\Route;
use Modules\Reports\Http\Controllers\ReportController;
use Modules\Reports\Http\Controllers\ScheduledReportController;
use Orion\Facades\Orion;

Route::middleware('throttle:api')->group(function (): void {
    // ScheduledReport CRUD (Orion)
    Orion::resource('scheduled_report', ScheduledReportController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    // Read-only report aggregation endpoints (custom non-Orion routes carry the
    // full auth contract: no refresh tokens, no deactivated users).
    $customMiddleware = ['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'];

    Route::middleware($customMiddleware)->group(function (): void {
        Route::get('reports/delinquency', [ReportController::class, 'delinquency']);
        Route::get('reports/income-statement', [ReportController::class, 'incomeStatement']);
        Route::get('reports/account-balances', [ReportController::class, 'accountBalances']);
        Route::get('reports/audit-log', [ReportController::class, 'auditLog']);
        Route::get('reports/collection-progress', [ReportController::class, 'collectionProgress']);
        Route::post('reports/{key}/generate', [ReportController::class, 'generate']);

        // §15.4.2 — publish a generated report to the cluster board (announcement)
        Route::post('reports/{key}/publish-to-board', [ReportController::class, 'publishToBoard']);

        // Async generated reports
        Route::get('reports/generated', [ReportController::class, 'listGenerated']);
        Route::get('reports/generated/{id}/download', [ReportController::class, 'downloadGenerated']);
    });
});
