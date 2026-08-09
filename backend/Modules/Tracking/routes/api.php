<?php

use Illuminate\Support\Facades\Route;
use Modules\Tracking\Http\Controllers\TrackController;

// Public tracking beacon for the landing (Astro) and the React app. NO
// auth:sanctum and NO initializeTenancy: events fire for anonymous visitors
// and mid-onboarding users. Throttled per IP (60/min) — mirrors the public
// billing/catalog + billing/quote routes in Modules/SubscriptionPlan.
Route::middleware('throttle:60,1')->post('track', [TrackController::class, 'track']);
