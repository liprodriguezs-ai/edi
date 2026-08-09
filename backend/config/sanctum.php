<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort()
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    | Set to null when using per-token expiration (access + refresh tokens).
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Access & Refresh Token TTL (per-token expiration)
    |--------------------------------------------------------------------------
    |
    | When expiration is null, tokens use expires_at. These values are used
    | when issuing access_token (short-lived) and refresh_token (long-lived).
    |
    */

    'expiration_access_minutes' => (int) env('SANCTUM_EXPIRATION_ACCESS_MINUTES', 60),

    // §5.4.3 — persistent session lasts 30 days without activity (renewed on use).
    'expiration_refresh_minutes' => (int) env('SANCTUM_EXPIRATION_REFRESH_MINUTES', 43200),

    /*
    |--------------------------------------------------------------------------
    | Refresh Token Cookie (httpOnly, secure storage)
    |--------------------------------------------------------------------------
    |
    | When using cookie-based refresh tokens, the backend sets this cookie
    | on login/refresh. The frontend never reads it (XSS-safe).
    |
    */

    'refresh_cookie' => [
        'name' => env('SANCTUM_REFRESH_COOKIE_NAME', 'refresh_token'),
        'path' => env('SANCTUM_REFRESH_COOKIE_PATH', '/api'),
        'domain' => env('SANCTUM_REFRESH_COOKIE_DOMAIN'),
        'secure' => env('SANCTUM_REFRESH_COOKIE_SECURE', false),
        'same_site' => env('SANCTUM_REFRESH_COOKIE_SAME_SITE', 'lax'),
        'http_only' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => PreventRequestForgery::class,
    ],

];
