<?php

use Illuminate\Support\Facades\Route;
use Modules\Projects\Http\Controllers\CommunityProjectCategoryController;
use Modules\Projects\Http\Controllers\CommunityProjectController;
use Modules\Projects\Http\Controllers\CommunityProjectStatusController;
use Modules\Projects\Http\Controllers\ParticipationTypeController;
use Modules\Projects\Http\Controllers\ProjectCommentController;
use Modules\Projects\Http\Controllers\ProjectContributionController;
use Modules\Projects\Http\Controllers\ProjectContributionStatusController;
use Modules\Projects\Http\Controllers\ProjectsReportController;
use Orion\Facades\Orion;

Route::middleware('throttle:api')->group(function () {
    // Catalog resources
    Orion::resource('community_project_status', CommunityProjectStatusController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('community_project_category', CommunityProjectCategoryController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('participation_type', ParticipationTypeController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('project_contribution_status', ProjectContributionStatusController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    // Main entity resources
    Orion::resource('community_project', CommunityProjectController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('project_contribution', ProjectContributionController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('project_comment', ProjectCommentController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    // Custom actions
    Route::middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'])->group(function () {
        Route::post('community-project/{id}/activate', [CommunityProjectController::class, 'activate']);
        Route::post('community-project/{id}/close', [CommunityProjectController::class, 'close']);
        Route::post('community-project/{id}/cancel', [CommunityProjectController::class, 'cancel']);
        Route::post('community-project/{id}/contribute', [CommunityProjectController::class, 'contribute']);
        Route::get('community-project/{id}/summary', [CommunityProjectController::class, 'summary']);
        Route::get('community-project/{id}/contribution-payments', [CommunityProjectController::class, 'contributionPayments']);
        Route::post('community-project/{id}/media', [CommunityProjectController::class, 'uploadMedia']);
        Route::delete('community-project/{id}/media/{mediaId}', [CommunityProjectController::class, 'deleteMedia']);
        Route::post('community-project/{id}/send-contribution-reminder', [CommunityProjectController::class, 'sendContributionReminder']);
        Route::post('project-contribution/{id}/decline', [ProjectContributionController::class, 'decline']);
        Route::post('project-contribution/{id}/revert-decline', [ProjectContributionController::class, 'revertDecline']);
        Route::post('project-comment/{id}/hide', [ProjectCommentController::class, 'hide']);

        // Projects funding-summary dashboard report (read.report_projects).
        Route::get('projects/funding-summary', [ProjectsReportController::class, 'fundingSummary']);
    });
});
