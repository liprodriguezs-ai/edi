<?php

use App\Http\Middleware\EnsureSubscriptionActive;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\InitializeTenancyFromUser;
use App\Http\Middleware\OnboardingCompleted;
use App\Http\Middleware\RejectRefreshToken;
use App\Http\Middleware\RestrictDevRoutes;
use App\Http\Middleware\SetLocale;
use Barryvdh\DomPDF\ServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Maatwebsite\Excel\ExcelServiceProvider;
use QCH\Core\Console\Commands\Module;
use QCH\Core\Http\Middleware\PasswordCheckMiddleware;
use QCH\Core\Http\Middleware\VerifyModuleEnabled;
use QCH\Core\Http\Middleware\VerifyPermission;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withProviders([
        'PDF' => ServiceProvider::class,
        'Excel' => ExcelServiceProvider::class,
        'QueryLogger' => Overtrue\LaravelQueryLogger\ServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo('/dev/login');
        $middleware->web(prepend: RestrictDevRoutes::class);
        $middleware->validateCsrfTokens(except: [
            'stripe/*',
        ]);
        $middleware->alias([
            'permission' => VerifyPermission::class,
            'setLocale' => SetLocale::class,
            'passwordCheck' => PasswordCheckMiddleware::class,
            'onboardingCompleted' => OnboardingCompleted::class,
            'initializeTenancy' => InitializeTenancyFromUser::class,
            'moduleEnabled' => VerifyModuleEnabled::class,
            'rejectRefreshToken' => RejectRefreshToken::class,
            'authActive' => EnsureUserIsActive::class,
            'subscriptionActive' => EnsureSubscriptionActive::class,
        ]);

        // Subscription enforcement is applied ACROSS the whole API surface (zero
        // per-route holes): a blocked cluster is denied everywhere except auth and
        // the pay-flow (see EnsureSubscriptionActive's allowlist). The middleware
        // self-resolves the sanctum user, so it is safe running in the group phase
        // before the route's auth:sanctum, and it no-ops when the enforcement
        // kill-switch (config billing.enforce_subscription) is off.
        $middleware->appendToGroup('api', EnsureSubscriptionActive::class);
        // $middleware->append(\QCH\Core\Http\Middleware\JwtMiddleware::class);
    })
    ->withCommands([
        Module::class,

    ])
    ->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);
    })->create();
