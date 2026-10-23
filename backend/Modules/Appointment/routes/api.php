<?php

use Illuminate\Support\Facades\Route;
use Modules\Appointment\Http\Controllers\AppointmentController;
use Orion\Facades\Orion;

/**
 * odd/tasks/edi-agenda.md — Orion serves search/index/show/store/update (no
 * destroy: an appointment is retired by cancel). Transitions are explicit
 * routes under update.appointment; "Iniciar atención" also creates a visit, so
 * it additionally requires create.visit. Static paths (suggestion, walk_in, mark_no_show)
 * are declared before the {appointment} ones.
 */
Route::middleware('throttle:api')->group(function () {
    Route::middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'])->group(function () {
        Route::get('appointment/suggestion', [AppointmentController::class, 'suggestion'])
            ->middleware('permission:read.appointment');

        Route::post('appointment/walk_in', [AppointmentController::class, 'walkIn'])
            ->middleware('permission:create.appointment');

        Route::post('appointment/mark_no_show', [AppointmentController::class, 'markDayNoShow'])
            ->middleware('permission:update.appointment');

        Route::post('appointment/{appointment}/confirm', [AppointmentController::class, 'confirm'])
            ->middleware('permission:update.appointment');

        Route::post('appointment/{appointment}/arrive', [AppointmentController::class, 'arrive'])
            ->middleware('permission:update.appointment');

        Route::post('appointment/{appointment}/start', [AppointmentController::class, 'start'])
            ->middleware(['permission:update.appointment', 'permission:create.visit']);

        Route::post('appointment/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])
            ->middleware('permission:update.appointment');

        Route::post('appointment/{appointment}/cancel', [AppointmentController::class, 'cancel'])
            ->middleware('permission:update.appointment');

        Route::post('appointment/{appointment}/no_show', [AppointmentController::class, 'noShow'])
            ->middleware('permission:update.appointment');
    });

    Orion::resource('appointment', AppointmentController::class)
        ->only(['search', 'index', 'show', 'store', 'update'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);
});
