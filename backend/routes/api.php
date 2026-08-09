<?php

use App\Http\Controllers\AngularController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\UserSessionController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use QCH\Core\Http\Controllers\ReportController;

// GET  /onboarding                   estado (true/false)
// POST /onboarding/general           paso 1: nombre + logo
// POST /onboarding/test-database     paso 2: probar conexión DB
// POST /onboarding/database          paso 2: guardar DB + lanza migraciones
// POST /onboarding/admin             paso 3: crear usuario admin
// POST /onboarding/complete          paso 4: seeders + ONBOARDING=false
Route::get('onboarding', [OnboardingController::class, 'status']);
Route::get('app-config', [OnboardingController::class, 'appConfig']);

Route::middleware('onboardingCompleted')->group(function () {
    Route::post('onboarding/general', [OnboardingController::class, 'general']);
    Route::post('onboarding/test-database', [OnboardingController::class, 'testDatabase']);
    Route::post('onboarding/database', [OnboardingController::class, 'database']);
    Route::post('onboarding/migrate', [OnboardingController::class, 'migrate']);
    Route::post('onboarding/admin', [OnboardingController::class, 'admin']);
});
Route::post('onboarding/complete', [OnboardingController::class, 'complete']);

// Stripe webhook (Cashier). PUBLIC: Stripe calls this directly — no auth, no
// tenancy. Signature is verified by Cashier's VerifyWebhookSignature middleware
// when STRIPE_WEBHOOK_SECRET (config cashier.webhook.secret) is set. CSRF is
// already exempted for stripe/* in bootstrap/app.php. The full URL is
// /api/stripe/webhook. Tenant transitions run in App\Listeners\StripeWebhookListener.
Route::post('stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])
    ->name('cashier.webhook');

RateLimiter::for('api', function ($request) {
    return Limit::perMinute(1000)->by($request->ip())->response(function () {
        return response('Too Many Requests', 429);
    });
});

RateLimiter::for('password-reset', function ($request) {
    return Limit::perMinute(5)->by($request->ip())->response(function () {
        return response()->json(['message' => 'Demasiados intentos. Intenta de nuevo en un minuto.'], 429);
    });
});

RateLimiter::for('auth-refresh', function ($request) {
    return Limit::perMinute(10)->by($request->ip())->response(function () {
        return response()->json(['message' => 'Demasiados intentos de actualización. Intenta de nuevo en un minuto.'], 429);
    });
});

RateLimiter::for('auth-login', function ($request) {
    $email = mb_strtolower(trim((string) $request->input('email')));

    return [
        // Per account: throttles brute force against ONE email even if the IP rotates.
        Limit::perMinute(5)->by('login:'.$email.'|'.$request->ip())->response(function () {
            return response()->json(['message' => 'Demasiados intentos de inicio de sesión. Intenta de nuevo en un minuto.'], 429);
        }),
        // Per IP: throttles credential spraying across many accounts from one source.
        Limit::perMinute(20)->by('login-ip:'.$request->ip())->response(function () {
            return response()->json(['message' => 'Demasiados intentos desde esta dirección. Intenta de nuevo en un minuto.'], 429);
        }),
    ];
});

// Resident invitation creation: throttled per inviter AND per destination email,
// so a resident cannot email-bomb a single address or spray many invitations.
RateLimiter::for('invite-create', function ($request) {
    $inviterId = optional($request->user())->id ?: $request->ip();
    $email = mb_strtolower(trim((string) $request->input('email')));

    return [
        Limit::perMinute(5)->by('invite-from:'.$inviterId)->response(function () {
            return response()->json(['message' => 'Demasiadas invitaciones seguidas. Intenta de nuevo en un minuto.'], 429);
        }),
        Limit::perHour(30)->by('invite-from-hour:'.$inviterId)->response(function () {
            return response()->json(['message' => 'Alcanzaste el límite de invitaciones por hora.'], 429);
        }),
        Limit::perDay(3)->by('invite-to:'.$email)->response(function () {
            return response()->json(['message' => 'Este correo ya recibió varias invitaciones hoy. Intenta mañana.'], 429);
        }),
    ];
});

// Email pre-check: light per-inviter limit to avoid account-enumeration probing.
RateLimiter::for('invite-check', function ($request) {
    $inviterId = optional($request->user())->id ?: $request->ip();

    return Limit::perMinute(20)->by('invite-check:'.$inviterId)->response(function () {
        return response()->json(['message' => 'Demasiadas verificaciones. Intenta de nuevo en un minuto.'], 429);
    });
});

// Resend: throttled per inviter only (no destination-email body field to key on,
// so it must NOT share the invite-create per-email bucket).
RateLimiter::for('invite-resend', function ($request) {
    $inviterId = optional($request->user())->id ?: $request->ip();

    return [
        Limit::perMinute(5)->by('invite-resend:'.$inviterId)->response(function () {
            return response()->json(['message' => 'Demasiados reenvíos seguidos. Intenta de nuevo en un minuto.'], 429);
        }),
        Limit::perHour(20)->by('invite-resend-hour:'.$inviterId),
    ];
});

Route::middleware('throttle:api')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:auth-login');
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth-login');

    Route::post('auth/forgot-password', [AuthController::class, 'sendResetLink'])
        ->middleware('throttle:password-reset');
    Route::post('auth/verifyResetToken', [AuthController::class, 'verifyResetToken'])
        ->middleware('throttle:password-reset');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:password-reset');

    Route::post('auth/verify-invitation-token', [AuthController::class, 'verifyInvitationToken'])
        ->middleware('throttle:password-reset');
    Route::post('auth/accept-invitation', [AuthController::class, 'acceptInvitation'])
        ->middleware('throttle:password-reset');
    Route::post('auth/accept-invitation/confirm', [AuthController::class, 'acceptInvitationConfirm'])
        ->middleware(['auth:sanctum', 'throttle:password-reset']);

    Route::post('auth/refresh', [AuthController::class, 'refresh'])
        ->middleware('throttle:auth-refresh');

    Route::post('selects', [AngularController::class, 'selects'])->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    // Rutas para registro de clientes (públicas)
    // Route::post('client-registration', [\App\Http\Controllers\ClientRegistrationController::class, 'store']);
    /* Route::get('client-registration/{userId}/status', [\App\Http\Controllers\ClientRegistrationController::class, 'getStatus']); */

    Route::middleware(['signed'])->group(function () {
        Route::get('media/{media}/download-signed', [MediaController::class, 'downloadSigned'])
            ->name('media.download_signed');
    });

    Route::group(['middleware' => ['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']], function () {
        Route::get('media/{media}/download', [MediaController::class, 'download']);
        Route::get('media/{media}/download-url', [MediaController::class, 'downloadUrl']);

        Route::get('customReport', [ReportController::class, 'customReport']);
        Route::post('customReport', [ReportController::class, 'customReport']);
        Route::get('recoveryReport', [ReportController::class, 'recoveryReport']);
        Route::post('recoveryReport', [ReportController::class, 'recoveryReport']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/accept-terms', [AuthController::class, 'acceptTerms']);
        Route::post('auth/complete-onboarding', [AuthController::class, 'completeOnboarding']);

        // Admin remote session revocation (§5.4 r4): kills every token of the user.
        Route::post('user/{id}/revoke-sessions', [UserSessionController::class, 'revokeSessions']);

        // Notification preferences (self). Central per-user; no tenant required.
        Route::get('user/notification-preferences', [NotificationPreferenceController::class, 'show']);
        Route::put('user/notification-preferences', [NotificationPreferenceController::class, 'update']);

        // Rutas protegidas para administración de clientes
        /* Route::put('client-registration/{userId}/status', [\App\Http\Controllers\ClientRegistrationController::class, 'updateStatus']); */

        // Rutas CRUD para clientes
        /* Route::get('client-registration', [\App\Http\Controllers\ClientRegistrationController::class, 'index']);
        Route::post('client-registration/search', [\App\Http\Controllers\ClientRegistrationController::class, 'search']);
        Route::get('client-registration/{id}', [\App\Http\Controllers\ClientRegistrationController::class, 'show']);
        Route::put('client-registration/{id}', [\App\Http\Controllers\ClientRegistrationController::class, 'update']);
        Route::delete('client-registration/{id}', [\App\Http\Controllers\ClientRegistrationController::class, 'destroy']); */
    });

});
