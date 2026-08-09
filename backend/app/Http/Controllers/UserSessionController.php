<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin remote session management (§5.4 r4).
 *
 * Custom action (laravel-backend-architecture §2.10): authorized by token ability, the target
 * user is resolved AFTER initializeTenancy so UserTenantScope restricts the
 * lookup to the admin's own organization (super-admin scopes via X-Tenant-Id or
 * operates unscoped).
 */
class UserSessionController extends Controller
{
    /**
     * Revokes ALL sessions of a user: every access and refresh token is
     * deleted, ending the session immediately on every device.
     */
    public function revokeSessions(Request $request, string $userId): JsonResponse
    {
        $actor = $request->user();

        if (! $actor->tokenCan('update.user') && ! $actor->tokenCan('*')) {
            return response()->json(['message' => 'No tienes permisos para realizar esta acción.'], 403);
        }

        $user = User::query()->findOrFail($userId);
        $user->tokens()->delete();

        return response()->json(['message' => 'Sesiones revocadas correctamente.']);
    }
}
