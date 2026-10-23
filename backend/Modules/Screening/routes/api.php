<?php

use Illuminate\Support\Facades\Route;
use Modules\Screening\Http\Controllers\ConfirmatoryTestController;
use Modules\Screening\Http\Controllers\ScreeningController;
use Orion\Facades\Orion;

/**
 * design §4 — Orion serves read + somatometry/notes update; every other
 * action is a custom route on the same controller, each gated by its own
 * permission ability (create/read/update/rescore/delete.screening).
 *
 * NOTE: the URL segment is snake_case (`confirmatory_test`), matching every
 * other Orion resource route in this codebase (population_type,
 * health_center, screening_item, ...) even though design.md §4 spelled it
 * with a hyphen — deliberate deviation for path-naming consistency.
 */
Route::middleware('throttle:api')->group(function () {
    // Registered BEFORE the Orion resource: `screening/{screening}` would
    // otherwise capture these paths and try to resolve the segment as an id.
    Route::get('screening/instrument-versions', [ScreeningController::class, 'instrumentVersions'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy', 'permission:rescore.screening']);

    Route::get('screening/age-groups', [ScreeningController::class, 'ageGroups'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy', 'permission:create.screening']);

    Route::get('screening/corrected-age', [ScreeningController::class, 'correctedAge'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy', 'permission:read.corrected_age']);

    Orion::resource('screening', ScreeningController::class)
        ->only(['search', 'index', 'show', 'update'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Route::middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'])->group(function () {
        Route::post('screening', [ScreeningController::class, 'createScreening'])
            ->middleware('permission:create.screening');

        Route::get('screening/{screening}/items', [ScreeningController::class, 'items'])
            ->middleware('permission:read.screening');

        // The official FUA document. Read-tier on purpose: handing the result sheet
        // to the caregiver is part of reading a screening, not of changing it.
        Route::get('screening/{screening}/document', [ScreeningController::class, 'document'])
            ->middleware('permission:read.screening');

        Route::put('screening/{screening}/answer', [ScreeningController::class, 'answer'])
            ->middleware('permission:update.screening');

        Route::post('screening/{screening}/score', [ScreeningController::class, 'score'])
            ->middleware('permission:update.screening');

        Route::post('screening/{screening}/complete', [ScreeningController::class, 'complete'])
            ->middleware('permission:update.screening');

        Route::post('screening/{screening}/rescore', [ScreeningController::class, 'rescore'])
            ->middleware('permission:rescore.screening');

        Route::post('screening/{screening}/invalidate', [ScreeningController::class, 'invalidate'])
            ->middleware('permission:delete.screening');
    });

    Orion::resource('confirmatory_test', ConfirmatoryTestController::class)
        ->only(['search', 'index', 'show', 'store', 'update'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);
});
