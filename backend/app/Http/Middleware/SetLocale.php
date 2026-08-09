<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        // get locale from header request
        $localeClient = $request->header('X-Locale');

        $language = $request->user()?->country?->language;
        $locale = $localeClient ?? $language?->code;
        if (isset($locale)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
