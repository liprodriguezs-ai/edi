<?php

namespace Modules\Housing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Access\Models\BlacklistEntry;
use Modules\Access\Models\BlacklistType;

/**
 * Admin/superadmin resident moderation. "Block" does two things at once:
 *  - disables the account (auth_active=false) so it cannot log into ANY cluster
 *    and revokes its active tokens
 *  - adds the person to this cluster's physical-access blacklist
 * It does NOT remove unit memberships — that is the separate "baja" action.
 */
class ResidentAdminController extends Controller
{
    public function block(Request $request, string $userId): JsonResponse
    {
        $actor = auth()->guard('sanctum')->user();
        if (! $actor || ! ($actor->tokenCan('block.resident') || $actor->tokenCan('*'))) {
            throw new AuthorizationException('Unauthorized action.');
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        // User is central: bypass the tenant scope to resolve the target.
        $target = User::withoutGlobalScope(UserTenantScope::class)->findOrFail($userId);

        // Cross-tenant guard: an admin may only block a member of THEIR cluster.
        // Without this, knowing a shared user's UUID would let cluster A disable
        // that account globally (auth_active is global) — a cross-tenant DoS.
        abort_unless(
            $target->tenants()->whereKey(tenant()->getTenantKey())->exists(),
            404
        );

        DB::transaction(function () use ($target, $data): void {
            $target->forceFill(['auth_active' => false])->save();
            $target->tokens()->delete();

            BlacklistEntry::create([
                'blacklist_type_id' => BlacklistType::VISITOR,
                'value' => $this->fullName($target),
                'reason' => $data['reason'],
                'is_active' => true,
            ]);
        });

        return response()->json([
            'message' => 'Residente bloqueado: su cuenta quedó deshabilitada y se agregó a la lista negra de accesos.',
        ]);
    }

    private function fullName(User $user): string
    {
        return trim(implode(' ', array_filter([
            $user->name,
            $user->last_name,
            $user->second_last_name,
        ])));
    }
}
