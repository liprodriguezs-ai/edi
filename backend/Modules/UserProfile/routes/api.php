<?php

use Illuminate\Support\Facades\Route;
use Modules\UserProfile\Http\Controllers\UserProfileController;
use Orion\Facades\Orion;

Route::middleware('throttle:api')->group(function () {
    Orion::resource('user_profile', UserProfileController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);
});
