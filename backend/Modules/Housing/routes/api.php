<?php

use Illuminate\Support\Facades\Route;
use Modules\Housing\Http\Controllers\ResidentAdminController;
use Modules\Housing\Http\Controllers\ResidentInvitationController;
use Modules\Housing\Http\Controllers\SectionController;
use Modules\Housing\Http\Controllers\TowerController;
use Modules\Housing\Http\Controllers\UnitController;
use Modules\Housing\Http\Controllers\UnitFeatureController;
use Modules\Housing\Http\Controllers\UnitMembershipController;
use Modules\Housing\Http\Controllers\UnitRelationshipController;
use Modules\Housing\Http\Controllers\UnitStatusController;
use Modules\Housing\Http\Controllers\UnitTypeController;
use Modules\Housing\Http\Controllers\UnitUserController;
use Modules\Housing\Http\Controllers\VehicleController;
use Orion\Facades\Orion;

Route::middleware('throttle:api')->group(function () {
    // Custom (non-Orion) unit actions. Registered BEFORE Orion::resource('unit')
    // so GET unit/my wins over the show route's {unit} parameter.
    Route::get('unit/my', [UnitController::class, 'myUnits'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Route::post('unit/{id}/change-owner', [UnitController::class, 'changeOwner'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    // Resident invitations + moderation. Custom (non-Orion) endpoints registered
    // BEFORE Orion::resource('unit') so unit/{unitId}/invitations is not captured
    // by the {unit} show route. user_invitation is central → tenant-scoped manually.
    $residentMiddleware = ['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'];

    Route::post('resident-invitation/check-email', [ResidentInvitationController::class, 'checkEmail'])
        ->middleware([...$residentMiddleware, 'throttle:invite-check']);

    Route::post('resident-invitation', [ResidentInvitationController::class, 'store'])
        ->middleware([...$residentMiddleware, 'throttle:invite-create']);

    Route::get('unit/{unitId}/invitations', [ResidentInvitationController::class, 'index'])
        ->middleware($residentMiddleware);

    Route::post('resident-invitation/{id}/resend', [ResidentInvitationController::class, 'resend'])
        ->middleware([...$residentMiddleware, 'throttle:invite-resend']);

    Route::delete('resident-invitation/{id}', [ResidentInvitationController::class, 'revoke'])
        ->middleware($residentMiddleware);

    Route::post('unit-membership/{unitUserId}/deactivate', [UnitMembershipController::class, 'deactivate'])
        ->middleware($residentMiddleware);

    Route::post('resident/{userId}/block', [ResidentAdminController::class, 'block'])
        ->middleware($residentMiddleware);

    Orion::resource('section', SectionController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('tower', TowerController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('unit_feature', UnitFeatureController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('unit_type', UnitTypeController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('unit_status', UnitStatusController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('unit_relationship', UnitRelationshipController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('unit', UnitController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('unit_user', UnitUserController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('vehicle', VehicleController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);
});
