<?php

use Illuminate\Support\Facades\Route;
use Modules\Role\Http\Controllers\RoleController;
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
    Orion::resource('role', RoleController::class)->withSoftDeletes()->middleware('auth:sanctum');

    Route::middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive'])->group(function () {
        Route::get('role/permissions/{id}', [RoleController::class, 'getPermissions']);
        Route::post('role/permissions/{id}', [RoleController::class, 'setPermissions']);
    });
});
