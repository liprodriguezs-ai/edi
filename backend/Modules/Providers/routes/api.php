<?php

use Illuminate\Support\Facades\Route;
use Modules\Providers\Http\Controllers\ProviderController;
use Modules\Providers\Http\Controllers\ProviderServiceTypeController;
use Modules\Providers\Http\Controllers\ProviderTypeController;
use Orion\Facades\Orion;

Route::middleware('throttle:api')->group(function () {
    Orion::resource('provider_type', ProviderTypeController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('provider_service_type', ProviderServiceTypeController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('provider', ProviderController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);
});
