<?php

use Illuminate\Support\Facades\Route;
use Modules\Clinic\Http\Controllers\HealthCenterController;
use Modules\Clinic\Http\Controllers\PractitionerProfileController;
use Orion\Facades\Orion;

Route::middleware('throttle:api')->group(function () {
    Orion::resource('health_center', HealthCenterController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('practitioner_profile', PractitionerProfileController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);
});
