<?php

use Illuminate\Support\Facades\Route;
use Modules\Stimulation\Http\Controllers\AttendancePaymentStatusController;
use Modules\Stimulation\Http\Controllers\BodyZoneController;
use Modules\Stimulation\Http\Controllers\DidacticMaterialController;
use Modules\Stimulation\Http\Controllers\MassageMovementController;
use Modules\Stimulation\Http\Controllers\SessionSectionController;
use Modules\Stimulation\Http\Controllers\SongController;
use Modules\Stimulation\Http\Controllers\StimulationAreaController;
use Modules\Stimulation\Http\Controllers\StimulationAttendanceController;
use Modules\Stimulation\Http\Controllers\StimulationDynamicController;
use Modules\Stimulation\Http\Controllers\StimulationGroupController;
use Modules\Stimulation\Http\Controllers\StimulationGroupMemberController;
use Modules\Stimulation\Http\Controllers\StimulationGroupTypeController;
use Modules\Stimulation\Http\Controllers\StimulationSessionController;
use Orion\Facades\Orion;

/**
 * NOTE: every URL segment is snake_case, matching every other Orion resource
 * route in this codebase (health_center, child_caregiver, screening_item...)
 * even though design.md §4/§6 spelled some of them with a hyphen — same
 * deliberate deviation already made in Modules/Screening/routes/api.php for
 * path-naming consistency.
 */
Route::middleware('throttle:api')->group(function () {
    // Catalogs (Phase 1) — 3 central + 6 tenant-scoped.
    Orion::resource('session_section', SessionSectionController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('stimulation_area', StimulationAreaController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('attendance_payment_status', AttendancePaymentStatusController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('stimulation_dynamic', StimulationDynamicController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('body_zone', BodyZoneController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('massage_movement', MassageMovementController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('didactic_material', DidacticMaterialController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('song', SongController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('stimulation_group_type', StimulationGroupTypeController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    // Sessions (Phase 2).
    Orion::resource('stimulation_session', StimulationSessionController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    // Groups + members (Phase 3).
    Orion::resource('stimulation_group', StimulationGroupController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('stimulation_group_member', StimulationGroupMemberController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    // Attendance (Phase 4): bulk idempotent upsert (custom, own permission
    // ability) + read-only Orion resource (D10 — same permission entity as
    // the group; no destroy route, attendance rows are facts).
    Route::middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'])->group(function () {
        Route::post('stimulation_group/{stimulation_group}/attendance', [StimulationAttendanceController::class, 'upsert'])
            ->middleware('permission:update.stimulation_group');
    });

    Orion::resource('stimulation_attendance', StimulationAttendanceController::class)
        ->only(['index', 'search', 'show'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);
});
