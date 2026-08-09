<?php

namespace Modules\Housing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Housing\Http\Controllers\Concerns\ResolvesActor;
use Modules\Housing\Models\Unit;
use Modules\Housing\Services\ResidentInvitationService;
use Modules\User\Models\UserInvitation;

/**
 * Resident invitation management for owners and admins. NOT an Orion resource:
 * user_invitation is a CENTRAL table with no TenantScope, so every query filters
 * by tenant('id') manually and the token/token_hash are never serialized.
 */
class ResidentInvitationController extends Controller
{
    use ResolvesActor;

    public function __construct(private readonly ResidentInvitationService $service) {}

    public function checkEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'unit_id' => ['required', 'uuid'],
        ]);

        $user = $this->actor();
        $unit = Unit::findOrFail($data['unit_id']);
        $this->authorizeUnit($user, $unit);

        return response()->json(['data' => $this->service->checkEmail($data['email'], $unit)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'unit_id' => ['required', 'uuid'],
            'unit_relationship_id' => ['required', 'uuid'],
        ]);

        $user = $this->actor();
        $unit = Unit::findOrFail($data['unit_id']);
        $this->authorizeUnit($user, $unit);

        // ValidationException (duplicate / invalid type / renter-without-owner) bubbles as 422.
        $result = $this->service->inviteToUnit($user, $data['email'], $unit, $data['unit_relationship_id']);

        return response()->json([
            'message' => 'Invitación enviada a '.$data['email'].'.',
            'data' => $this->presentInvitation($result['invitation']),
            'warnings' => $result['warnings'],
        ], 201);
    }

    public function index(string $unitId): JsonResponse
    {
        $user = $this->actor();
        $unit = Unit::findOrFail($unitId);
        $this->authorizeUnit($user, $unit);

        $invitations = UserInvitation::query()
            ->where('inviteable_type', Unit::class)
            ->where('inviteable_id', $unit->id)
            ->where('tenant_id', tenant()->getTenantKey())
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (UserInvitation $invitation): array => $this->presentInvitation($invitation))
            ->all();

        return response()->json(['data' => $invitations]);
    }

    public function resend(string $id): JsonResponse
    {
        $user = $this->actor();
        $invitation = $this->findOwnedInvitation($id, $user);

        $this->service->resend($invitation);

        return response()->json([
            'message' => 'Invitación reenviada a '.$invitation->email.'.',
            'data' => $this->presentInvitation($invitation->fresh()),
        ]);
    }

    public function revoke(string $id): JsonResponse
    {
        $user = $this->actor();
        $invitation = $this->findOwnedInvitation($id, $user);

        $this->service->revoke($invitation);

        return response()->json(['message' => 'Invitación cancelada.']);
    }

    private function authorizeUnit(User $user, Unit $unit): void
    {
        $allowed = $this->service->isActiveOwner($user, $unit)
            || $user->tokenCan('create.resident_invitation')
            || $user->tokenCan('*');

        if (! $allowed) {
            throw new AuthorizationException('Unauthorized action.');
        }
    }

    private function findOwnedInvitation(string $id, User $user): UserInvitation
    {
        $invitation = UserInvitation::query()
            ->where('id', $id)
            ->where('inviteable_type', Unit::class)
            ->where('tenant_id', tenant()->getTenantKey())
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->first();

        if ($invitation === null) {
            throw new ModelNotFoundException;
        }

        $unit = Unit::find($invitation->inviteable_id);
        if ($unit === null) {
            throw new ModelNotFoundException;
        }

        $this->authorizeUnit($user, $unit);

        return $invitation;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentInvitation(UserInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'unit_id' => $invitation->inviteable_id,
            'unit_relationship_id' => $invitation->unit_relationship_id,
            'status' => $this->status($invitation),
            'expires_at' => optional($invitation->expires_at)->toIso8601String(),
            'created_at' => optional($invitation->created_at)->toIso8601String(),
        ];
    }

    private function status(UserInvitation $invitation): string
    {
        return match (true) {
            $invitation->accepted_at !== null => 'accepted',
            $invitation->revoked_at !== null => 'revoked',
            $invitation->isExpired() => 'expired',
            default => 'pending',
        };
    }
}
