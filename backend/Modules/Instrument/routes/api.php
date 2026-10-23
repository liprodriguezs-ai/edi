<?php

use Illuminate\Support\Facades\Route;
use Modules\Instrument\Http\Controllers\AgeGroupController;
use Modules\Instrument\Http\Controllers\AreaScoringRuleController;
use Modules\Instrument\Http\Controllers\GlobalResultMessageController;
use Modules\Instrument\Http\Controllers\GlobalScoringRuleController;
use Modules\Instrument\Http\Controllers\HeadCircumferenceReferenceController;
use Modules\Instrument\Http\Controllers\InstrumentVersionController;
use Modules\Instrument\Http\Controllers\RiskFactorController;
use Modules\Instrument\Http\Controllers\ScreeningItemController;
use Orion\Facades\Orion;

/**
 * Read-only API (design D23 / REQ-INS-014): every resource is registered
 * with only(['index','show']) -- content enters exclusively via
 * InstrumentSeeder, never through this API. All controllers share
 * $keyForPermission = 'instrument', a single permission family gates the
 * whole surface (precedent: BirthRecord -> 'child').
 */
Route::middleware('throttle:api')->group(function () {
    Orion::resource('instrument_version', InstrumentVersionController::class)
        ->only(['index', 'show'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('age_group', AgeGroupController::class)
        ->only(['index', 'show'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('screening_item', ScreeningItemController::class)
        ->only(['index', 'show'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('area_scoring_rule', AreaScoringRuleController::class)
        ->only(['index', 'show'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('global_scoring_rule', GlobalScoringRuleController::class)
        ->only(['index', 'show'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('risk_factor', RiskFactorController::class)
        ->only(['index', 'show'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('head_circumference_reference', HeadCircumferenceReferenceController::class)
        ->only(['index', 'show'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    Orion::resource('global_result_message', GlobalResultMessageController::class)
        ->only(['index', 'show'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);
});
