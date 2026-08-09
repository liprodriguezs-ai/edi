<?php

namespace QCH\Core\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class VerifyPermission
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $permission)
    {
        if (! auth()->user() || ! in_array($permission, auth()->user()->getPermissionsViaRoles()->pluck('name')->toArray())) {
            throw new AccessDeniedHttpException(
                'permissions'
            );
        }

        return $next($request);
    }

    /**
     * @var Guard
     */
    protected $auth;

    /**
     * Create a new filter instance.
     */
    public function __construct(Guard $auth)
    {
        $this->auth = $auth;
    }
}
