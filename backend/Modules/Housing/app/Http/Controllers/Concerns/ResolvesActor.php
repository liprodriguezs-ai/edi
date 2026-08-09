<?php

namespace Modules\Housing\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Resolves the authenticated Sanctum user for custom (non-Orion) controllers,
 * throwing a 403 when there is no session. Shared so the resident-invitation and
 * membership controllers don't duplicate the lookup.
 */
trait ResolvesActor
{
    protected function actor(): User
    {
        $user = auth()->guard('sanctum')->user();
        if (! $user) {
            throw new AuthorizationException('Unauthorized action.');
        }

        return $user;
    }
}
