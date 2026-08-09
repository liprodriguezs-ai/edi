<?php

use Illuminate\Support\Facades\Route;
use Modules\Complaint\Http\Controllers\ComplaintCategoryController;
use Modules\Complaint\Http\Controllers\ComplaintController;
use Modules\Complaint\Http\Controllers\ComplaintPriorityController;
use Modules\Complaint\Http\Controllers\ComplaintStatusController;
use Orion\Facades\Orion;

Route::middleware('throttle:api')->group(function (): void {
    // Complaint (Incidencias) is a CORE functional module (user decision, 2026-07-19) —
    // no moduleEnabled billing gate, unlike the billable Package/Announcements add-ons.
    $middleware = ['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'];

    Route::middleware($middleware)->group(function () use ($middleware): void {
        // CUSTOM ROUTES FIRST — literal paths must be declared before Orion::resource
        // so the `complaint/{id}` wildcard does not swallow them and fail UUID parsing.
        // NOTE: string {complaintId} param (NOT implicit {complaint} binding). Implicit
        // binding is resolved by SubstituteBindings BEFORE InitializeTenancyFromUser
        // runs, so a bound model would be fetched across ALL tenants (TenantScope is
        // inert until tenancy is initialized) — a cross-tenant write hole. Resolving
        // inside the controller (after initializeTenancy) keeps the lookup
        // tenant-scoped, matching every sibling module (Package precedent).
        Route::get('complaint/registration-eligibility', [ComplaintController::class, 'registrationEligibility']);
        Route::post('complaint/{complaintId}/transition', [ComplaintController::class, 'transition']);
        Route::post('complaint/{complaintId}/assign', [ComplaintController::class, 'assign']);
        Route::get('complaint/{complaintId}/eligible-assignees', [ComplaintController::class, 'eligibleAssignees']);
        Route::post('complaint/{complaintId}/message', [ComplaintController::class, 'message']);
        Route::get('complaint/{complaintId}/messages', [ComplaintController::class, 'messages']);
        Route::get('complaint/{complaintId}/history', [ComplaintController::class, 'history']);

        // ORION RESOURCES (after custom routes)
        Orion::resource('complaint', ComplaintController::class)
            ->withSoftDeletes()
            ->middleware($middleware);

        Orion::resource('complaint_category', ComplaintCategoryController::class)
            ->withSoftDeletes()
            ->middleware($middleware);

        Orion::resource('complaint_priority', ComplaintPriorityController::class)
            ->withSoftDeletes()
            ->middleware($middleware);

        Orion::resource('complaint_status', ComplaintStatusController::class)
            ->only(['index', 'show'])
            ->middleware($middleware);
    });
});
