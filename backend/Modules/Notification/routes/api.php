<?php

use Illuminate\Support\Facades\Route;
use Modules\Notification\Http\Controllers\NotificationController;
use Modules\Notification\Http\Controllers\NotificationUserController;
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
    // Custom center action (§17.2) — registered BEFORE the Orion resource so
    // "mark-all-read" is never captured as the {id} segment. The unread badge is
    // derived client-side from POST notification_user/search (meta.total) — there
    // is no dedicated badge-count endpoint.
    Route::post('notification_user/mark-all-read', [NotificationUserController::class, 'markAllRead'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('notification_user', NotificationUserController::class)->withSoftDeletes()->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);
    Orion::resource('notification', NotificationController::class)->withSoftDeletes()->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);
});
