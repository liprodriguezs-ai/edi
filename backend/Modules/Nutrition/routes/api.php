<?php

use Illuminate\Support\Facades\Route;
use Modules\Nutrition\Http\Controllers\NutritionalAssessmentController;
use Orion\Facades\Orion;

/**
 * S5 -- nutritional_assessment CRUD (Orion) plus a server-computed
 * classification preview. The preview route is registered BEFORE the Orion
 * resource so `classify` is never captured as the show route's {id}.
 */
Route::middleware('throttle:api')->group(function () {
    Route::get('nutritional_assessment/classify', [NutritionalAssessmentController::class, 'classify'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy', 'permission:read.nutritional_assessment']);

    Orion::resource('nutritional_assessment', NutritionalAssessmentController::class)
        ->withSoftDeletes()
        ->only(['search', 'index', 'show', 'store', 'update', 'destroy', 'restore'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);
});
