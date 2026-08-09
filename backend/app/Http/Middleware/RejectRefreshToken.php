<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects long-lived refresh tokens used as Bearer on protected routes.
 *
 * `auth:sanctum` authenticates ANY valid token regardless of its abilities,
 * so a stolen refresh token (7-day TTL) would work as an access token on
 * routes that never call tokenCan() (sec-authn-04). Refresh tokens are only
 * valid on POST auth/refresh, which is not behind this middleware.
 *
 * Must run AFTER auth:sanctum in the route middleware chain.
 */
class RejectRefreshToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->name === 'refresh_token') {
            return response()->json(['message' => 'Token inválido para esta operación.'], 401);
        }

        return $next($request);
    }
}
