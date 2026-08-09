<?php

use Illuminate\Support\Facades\Route;
use Modules\DataImport\Http\Controllers\ImportController;

/*
 * DataImport — payment import endpoints.
 *
 * All routes require full auth contract (no refresh tokens, no deactivated users)
 * plus tenant initialization. Authorization is enforced by checking token abilities
 * (`read.data_import`, `create.data_import`) inside the controller — matching the
 * pattern used by ReportController for custom non-Orion routes.
 */
Route::middleware('throttle:api')->group(function (): void {
    $middleware = ['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'];

    Route::middleware($middleware)->group(function (): void {
        Route::get('import/template', [ImportController::class, 'template']);
        Route::post('import/validate', [ImportController::class, 'validateImport']);
        Route::post('import/execute', [ImportController::class, 'executeImport']);
        Route::get('import/{id}/status', [ImportController::class, 'status']);
    });
});
