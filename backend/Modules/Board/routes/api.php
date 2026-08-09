<?php

use Illuminate\Support\Facades\Route;
use Modules\Board\Http\Controllers\BoardMemberController;
use Modules\Board\Http\Controllers\BoardPeriodController;
use Modules\Board\Http\Controllers\BoardPeriodStatusController;
use Modules\Board\Http\Controllers\BoardRoleController;
use Orion\Facades\Orion;

Route::middleware('throttle:api')->group(function () {
    // Board catalogs
    Orion::resource('board_role', BoardRoleController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('board_period_status', BoardPeriodStatusController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    // Board period resources
    Orion::resource('board_period', BoardPeriodController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('board_member', BoardMemberController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    // Custom board period actions
    Route::middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'])->group(function () {
        Route::post('board_period/start', [BoardPeriodController::class, 'startPeriod']);
        Route::post('board_period/{id}/close', [BoardPeriodController::class, 'closePeriod']);
        Route::post('board_period/{id}/media', [BoardPeriodController::class, 'uploadActa']);
    });
});
