<?php

use Illuminate\Support\Facades\Route;
use Modules\ChildRecord\Http\Controllers\BirthRecordController;
use Modules\ChildRecord\Http\Controllers\CaregiverController;
use Modules\ChildRecord\Http\Controllers\CaregiverRelationshipController;
use Modules\ChildRecord\Http\Controllers\ChildCaregiverController;
use Modules\ChildRecord\Http\Controllers\ChildController;
use Modules\ChildRecord\Http\Controllers\EducationLevelController;
use Modules\ChildRecord\Http\Controllers\OccupationController;
use Modules\ChildRecord\Http\Controllers\PopulationTypeController;
use Modules\ChildRecord\Http\Controllers\SexController;
use Modules\ChildRecord\Http\Controllers\VisitController;
use Orion\Facades\Orion;

Route::middleware('throttle:api')->group(function () {
    // Catalogs (Phase 2)
    Orion::resource('sex', SexController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('population_type', PopulationTypeController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('caregiver_relationship', CaregiverRelationshipController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('education_level', EducationLevelController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('occupation', OccupationController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    // Core entities (Phase 3)
    Orion::resource('child', ChildController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('caregiver', CaregiverController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('child_caregiver', ChildCaregiverController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('birth_record', BirthRecordController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    // Visit (Phase 4)
    Orion::resource('visit', VisitController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);
});
