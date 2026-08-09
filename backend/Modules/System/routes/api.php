<?php

use Illuminate\Support\Facades\Route;
use Modules\System\Http\Controllers\MenuController;
use Modules\System\Http\Controllers\SystemController;
use Orion\Facades\Orion;

/*
 *--------------------------------------------------------------------------
 * API Routes — System module
 *--------------------------------------------------------------------------
 */

Route::middleware('throttle:api')->group(function () {
    Orion::resource('system', SystemController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Route::get('menu', MenuController::class)
        ->middleware(['auth:sanctum', 'initializeTenancy']);
});
