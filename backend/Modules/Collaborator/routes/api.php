<?php

use Illuminate\Support\Facades\Route;
use Modules\Collaborator\Http\Controllers\CollaboratorController;
use Modules\Collaborator\Http\Controllers\EmployeePositionController;
use Modules\Collaborator\Http\Controllers\EmployeeShiftController;
use Modules\Collaborator\Http\Controllers\PayFrequencyController;
use Orion\Facades\Orion;

Route::middleware('throttle:api')->group(function () {
    // Custom collaborator routes (non-CRUD)
    Route::middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'])->prefix('collaborator')->group(function () {
        Route::get('me', [CollaboratorController::class, 'me']);
        Route::post('validate_import', [CollaboratorController::class, 'validateImport']);
        Route::post('batch_import', [CollaboratorController::class, 'batchImport']);
        Route::post('{id}/resend_invitation', [CollaboratorController::class, 'resendInvitation']);
        Route::post('{id}/terminate', [CollaboratorController::class, 'terminate']);
        Route::post('{id}/reactivate', [CollaboratorController::class, 'reactivate']);
        Route::post('{id}/media', [CollaboratorController::class, 'uploadDocument']);
        Route::get('{id}/media/{mediaId}', [CollaboratorController::class, 'downloadDocument']);
    });

    // Employee catalog resources
    Orion::resource('employee_position', EmployeePositionController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('pay_frequency', PayFrequencyController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('employee_shift', EmployeeShiftController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    // Main collaborator/employee resource
    Orion::resource('collaborator', CollaboratorController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);
});
