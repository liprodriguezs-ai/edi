<?php

use Illuminate\Support\Facades\Route;
use Modules\FollowUp\Http\Controllers\FollowUpController;
use Modules\FollowUp\Http\Controllers\ReferralController;
use Modules\FollowUp\Http\Controllers\ReferralSpecialtyController;
use Orion\Facades\Orion;

/**
 * design §4 — Orion serves index/show/store/update for follow_up and
 * referral (no destroy: retirement is `cancel`); transitions are explicit
 * routes under `update.follow_up`. referral_specialty is a full tenant
 * catalog CRUD resource.
 *
 * NOTE: URL segments are snake_case (`follow_up`, `referral_specialty`),
 * matching every other Orion resource route in this codebase (screening,
 * confirmatory_test, caregiver_relationship, health_center, ...) even
 * though design.md §4 spelled two of them with a hyphen — same deliberate
 * deviation for path-naming consistency already made in Screening's
 * routes/api.php.
 */
Route::middleware('throttle:api')->group(function () {
    Orion::resource('follow_up', FollowUpController::class)
        ->only(['search', 'index', 'show', 'store', 'update'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Route::middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'])->group(function () {
        Route::post('follow_up/{followUp}/start', [FollowUpController::class, 'start'])
            ->middleware('permission:update.follow_up');

        Route::post('follow_up/{followUp}/complete', [FollowUpController::class, 'complete'])
            ->middleware('permission:update.follow_up');

        Route::post('follow_up/{followUp}/cancel', [FollowUpController::class, 'cancel'])
            ->middleware('permission:update.follow_up');
    });

    Orion::resource('referral', ReferralController::class)
        ->only(['search', 'index', 'show', 'store', 'update'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('referral_specialty', ReferralSpecialtyController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);
});
