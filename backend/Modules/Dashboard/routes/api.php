<?php

use Illuminate\Support\Facades\Route;
use Modules\Dashboard\Http\Controllers\DashboardController;
use Modules\Dashboard\Http\Controllers\OnboardingChecklistController;

Route::middleware(['throttle:api', 'auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'])->prefix('dashboard')->group(function () {
    Route::get('summary', [DashboardController::class, 'summary']);
    Route::get('onboarding-checklist', [OnboardingChecklistController::class, 'index']);
});
