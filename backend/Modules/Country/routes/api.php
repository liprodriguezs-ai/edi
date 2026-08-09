<?php

use Illuminate\Support\Facades\Route;
use Modules\Country\Http\Controllers\CountryController;
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
    Orion::resource('country', CountryController::class)->withSoftDeletes()->middleware(['auth:sanctum', 'initializeTenancy']);
});
