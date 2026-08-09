<?php

use Illuminate\Support\Facades\Route;
use Modules\Announcements\Http\Controllers\AnnouncementController;
use Modules\Announcements\Http\Controllers\AnnouncementPriorityController;
use Modules\Announcements\Http\Controllers\AnnouncementStatusController;
use Modules\Announcements\Http\Controllers\AnnouncementTypeController;
use Orion\Facades\Orion;

Route::middleware('throttle:api')->group(function (): void {
    // moduleEnabled:announcement runs AFTER initializeTenancy so the billable "Avisos"
    // module is enforced per-tenant: without the base entitlement EntitlementService
    // flips module_tenant.enabled=false and every Announcements endpoint returns 403
    // MODULE_DISABLED (super-admin/lawyer bypass).
    $tenancyMiddleware = ['auth:sanctum', 'initializeTenancy', 'moduleEnabled:announcement'];
    // Custom (non-Orion) actions also reject refresh tokens and deactivated users.
    $customMiddleware = ['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy', 'moduleEnabled:announcement'];

    // Catalog resources
    Orion::resource('announcement_type', AnnouncementTypeController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    Orion::resource('announcement_priority', AnnouncementPriorityController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    Orion::resource('announcement_status', AnnouncementStatusController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    // Literal GET routes MUST be registered BEFORE the Orion resource. Otherwise
    // the resource's show route (announcement/{id}) is matched first and parses
    // "board" as a UUID id -> 500. Any tenant member may read the published board
    // (the board itself filters by the user's audience).
    Route::middleware($customMiddleware)->group(function (): void {
        Route::get('announcement/board', [AnnouncementController::class, 'board']);
        // Audience-size preview for the compose form. Literal path registered before
        // the Orion resource so it is never parsed as an {id}; counts residents, not units.
        Route::post('announcement/preview', [AnnouncementController::class, 'preview']);
    });

    // Main entity resource
    Orion::resource('announcement', AnnouncementController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    // Custom actions
    Route::middleware($customMiddleware)->group(function (): void {
        Route::post('announcement/{id}/publish', [AnnouncementController::class, 'publish']);
        Route::post('announcement/{id}/archive', [AnnouncementController::class, 'archive']);
        Route::post('announcement/{id}/read', [AnnouncementController::class, 'read']);
        Route::get('announcement/{id}/metrics', [AnnouncementController::class, 'readMetrics']);
        Route::get('announcement/{id}/read-summary', [AnnouncementController::class, 'readSummary']);
        Route::get('announcement/{id}/readers', [AnnouncementController::class, 'readers']);
        Route::post('announcement/{id}/media', [AnnouncementController::class, 'uploadMedia']);
        Route::delete('announcement/{id}/media/{mediaId}', [AnnouncementController::class, 'deleteMedia']);
    });
});
