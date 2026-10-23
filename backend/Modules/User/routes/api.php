<?php

use Illuminate\Support\Facades\Route;
use Modules\User\Http\Controllers\UserController;
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

    // Profile image routes
    Route::post('user/uploadProfileImage', [UserController::class, 'uploadProfileImage'])->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy', 'termsAccepted']);
    Route::post('user/deleteProfileImage', [UserController::class, 'deleteProfileImage'])->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy', 'termsAccepted']);
    Route::patch('user/setTokenSender', [
        UserController::class,
        'setTokenSender',
    ])->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy', 'termsAccepted']);
    Route::post('user/updateProspect', [
        UserController::class,
        'updateProspect',
    ])->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy', 'termsAccepted']);

    Route::get('user/network', [
        UserController::class,
        'network',
    ])->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    // Right to be forgotten (§18.3.4 — global-06). Admin-only custom action.
    Route::post('user/{id}/anonymize', [
        UserController::class,
        'anonymize',
    ])->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy', 'termsAccepted']);

    // T&C enforcement (§18.10 — global-08): `termsAccepted` skips reads, so it is
    // safe on the full resource; only writes return 426 on an outdated acceptance.
    Orion::resource('user', UserController::class)->withSoftDeletes()->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy', 'termsAccepted']);

    Route::post('uploadDocuments', [
        UserController::class,
        'uploadDocuments',
    ])->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy', 'termsAccepted']);

    Route::get('documents', [
        UserController::class,
        'documents',
    ])->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Route::post('updatePassword', [
        UserController::class,
        'updatePassword',
    ])->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy', 'termsAccepted']);

    /*
    Route::post('learningDocument', [
        \Modules\User\Http\Controllers\UserController::class,
        'learningDocument'
    ])->middleware(['auth:sanctum', 'initializeTenancy']);
    */
    /*  Route::patch('user/setTokenSender', [\Modules\User\Http\Controllers\UserController::class, 'setTokenSender'])
        ->middleware(['auth:sanctum', 'initializeTenancy']); */
});
