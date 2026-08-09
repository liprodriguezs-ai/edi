<?php

namespace App\Audit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Contracts\UserResolver;

/**
 * Resolves the current user for audit using the central connection so no query
 * runs on the tenant connection (user table is in central). Prevents "current
 * transaction is aborted" when auditing tenant models inside a transaction.
 */
class CentralUserResolver implements UserResolver
{
    /**
     * @param  Auditable|null  $model
     */
    public static function resolve($model = null): ?Authenticatable
    {
        $central = config('database.default', 'central');
        $previous = DB::getDefaultConnection();

        if ($previous === $central) {
            return static::resolveUser();
        }

        DB::setDefaultConnection($central);

        try {
            return static::resolveUser();
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    private static function resolveUser(): ?Authenticatable
    {
        $guards = Config::get('audit.user.guards', [
            Config::get('auth.defaults.guard'),
        ]);

        foreach ($guards as $guard) {
            try {
                if (Auth::guard($guard)->check()) {
                    return Auth::guard($guard)->user();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
