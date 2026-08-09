<?php

namespace QCH\Core\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\LockedHttpException;

class PasswordCheckMiddleware
{
    public function handle($request, Closure $next)
    {
        $user = auth()->user();
        $password = $request->password;
        if (! $user->password || ! Hash::check($password, $user->password)) {
            throw new LockedHttpException('Password not valid');
        }

        return $next($request);
    }
}
