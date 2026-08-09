<?php

namespace QCH\Core\Http\Middleware;

use Closure;

class JwtMiddleware
{
    public function handle($request, Closure $next)
    {
        if ($request->is(env('API_PREFIX') === '/' ? '*' : env('API_PREFIX').'/*')) {
            if (! auth()->check()) {
                return response()->json(['error' => 'Token not valid'], 401);
            }
        }

        return $next($request);
    }
}
