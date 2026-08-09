<?php

namespace Modules\Housing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Modules\Housing\Http\Controllers\Concerns\ResolvesActor;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitUser;
use Modules\Housing\Services\ResidentInvitationService;
use Modules\Housing\Services\UnitUserService;

/**
 * Unit membership (unit_user) lifecycle actions that are not Orion CRUD.
 */
class UnitMembershipController extends Controller
{
    use ResolvesActor;

    public function __construct(private readonly ResidentInvitationService $residentInvitations) {}

    /**
     * Soft baja: end the membership (end_date = today). The resident may remove
     * themselves; owners/admins may remove others on their unit.
     */
    public function deactivate(UnitUserService $unitUserService, string $unitUserId): JsonResponse
    {
        $user = $this->actor();
        $membership = UnitUser::findOrFail($unitUserId); // TenantScope → 404 cross-tenant
        $unit = Unit::findOrFail($membership->unit_id);

        $isSelf = $membership->user_id === $user->id;
        if (! $isSelf
            && ! $this->residentInvitations->isActiveOwner($user, $unit)
            && ! ($user->tokenCan('update.unit_user') || $user->tokenCan('*'))) {
            throw new AuthorizationException('Unauthorized action.');
        }

        if ($membership->end_date === null) {
            $unitUserService->update($membership, ['end_date' => today()]);
        }

        return response()->json(['message' => 'Residente dado de baja de la unidad.']);
    }
}
