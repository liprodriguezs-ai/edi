<?php

use App\Http\Controllers\DevLoginController;
use Illuminate\Support\Facades\Route;

if (app()->environment(['local', 'testing'])) {
    Route::prefix('dev')->group(function () {
        Route::get('login', fn () => view('dev.login'))->middleware('guest')->name('dev.login');
        Route::post('login', [DevLoginController::class, 'login'])->middleware('guest');
        Route::post('logout', [DevLoginController::class, 'logout'])->middleware('auth:web')->name('dev.logout');
    });
}
