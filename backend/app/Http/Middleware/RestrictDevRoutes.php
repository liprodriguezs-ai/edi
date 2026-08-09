<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hides the /dev/* utility routes (dev login panel) outside local/testing.
 *
 * In production those routes would otherwise expose an unthrottled password
 * oracle against the web guard (sec-authn-03). Responding 404 keeps their
 * existence invisible. Prepended to the `web` group in bootstrap/app.php so
 * it runs before session/CSRF work.
 */
class RestrictDevRoutes
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('dev', 'dev/*') && ! app()->environment(['local', 'testing'])) {
            abort(404);
        }

        return $next($request);
    }
}
