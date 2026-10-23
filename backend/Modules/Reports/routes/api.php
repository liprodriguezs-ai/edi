<?php

use Illuminate\Support\Facades\Route;
use Modules\Reports\Http\Controllers\ChildReportController;
use Modules\Reports\Http\Controllers\IndicatorController;
use Modules\Reports\Http\Controllers\NominalReportController;
use Modules\Reports\Http\Controllers\ScheduledReportController;
use Orion\Facades\Orion;

/**
 * design §4. Indicator endpoints are aggregate/PII-free, gated by
 * `read.screening_indicator` via the `permission` middleware (they are NOT
 * Orion resource actions). The nominal listing IS an Orion resource
 * (index/search/show only, read-only reporting surface) whose ability is
 * derived from $keyForPermission = 'nominal_report' — a DIFFERENT
 * permission from `read.screening`/`read.screening_indicator` (D7/D8).
 */
Route::middleware(['throttle:api', 'auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'])->prefix('reports')->group(function () {
    Route::middleware('permission:read.screening_indicator')->prefix('indicators')->group(function () {
        Route::get('summary', [IndicatorController::class, 'summary']);
        Route::get('monthly-series', [IndicatorController::class, 'monthlySeries']);
        Route::get('area-breakdown', [IndicatorController::class, 'areaBreakdown']);
        Route::get('concentrate', [IndicatorController::class, 'concentrate']);
    });

    // Per-child reports (legacy "Concentrado EDI" and "Reporte general").
    // Reading needs read.<entity>; the Excel file needs report.<entity>.
    Route::get('edi-concentrate', [ChildReportController::class, 'ediConcentrate'])
        ->middleware('permission:read.edi_concentrate');
    Route::get('edi-concentrate/export', [ChildReportController::class, 'ediConcentrateExport'])
        ->middleware('permission:report.edi_concentrate');
    Route::get('general', [ChildReportController::class, 'general'])
        ->middleware('permission:read.general_report');
    Route::get('general/export', [ChildReportController::class, 'generalExport'])
        ->middleware('permission:report.general_report');

    // Saved schedules the nightly runner replays. Its own permission tier, like
    // the nominal listing: scheduling an export is not the same right as reading one.
    Orion::resource('scheduled', ScheduledReportController::class)
        ->only(['index', 'search', 'show', 'store', 'update', 'destroy'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('nominal', NominalReportController::class)
        ->only(['index', 'search', 'show'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);
});
