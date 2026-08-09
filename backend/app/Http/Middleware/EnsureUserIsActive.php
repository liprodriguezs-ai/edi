<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces `auth_active` on EVERY authenticated request (sec-authn-10, §5.4 r4).
 *
 * Before this, auth_active was only checked at login/refresh, so a user
 * deactivated by an admin kept a working access token for up to 60 minutes.
 *
 * Must run AFTER auth:sanctum in the route middleware chain.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->auth_active) {
            return response()->json(['message' => 'Cuenta desactivada'], 403);
        }

        return $next($request);
    }
}
