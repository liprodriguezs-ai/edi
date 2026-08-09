<?php

namespace Modules\Access\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Access\Models\Visitor;
use Modules\Access\Models\VisitorStatus;
use Modules\Access\Services\AccessService;
use Modules\Collections\Services\DelinquencyRestrictionService;
use Modules\Housing\Models\UnitUser;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;

class VisitorController extends Controller
{
    protected $model = Visitor::class;

    protected $keyForPermission = 'visitor';

    public function includes(): array
    {
        return ['unit', 'visitor_motive', 'qr_type', 'visitor_status', 'created_by_user', 'access_logs'];
    }

    public function filterableBy(): array
    {
        return ['unit_id', 'visitor_status_id', 'qr_type_id', 'created_by_user_id', 'visitor_motive_id', 'arrives_by_vehicle', 'qr_expires_at', 'created_at', 'plate'];
    }

    public function sortableBy(): array
    {
        return ['name', 'created_at', 'qr_expires_at'];
    }

    public function searchableBy(): array
    {
        // qr_token is intentionally NOT searchable: it is the entry credential and
        // is hidden from serialization (see Visitor::$hidden). Only the owner ever
        // receives their own token, via ->makeVisible('qr_token').
        return ['name', 'plate'];
    }

    /**
     * §14.2: residents/renters only ever see THEIR OWN visitors ("Ver MIS QR
     * generados") — qr_token is the entry credential, so leaking another unit's
     * pending tokens lets a resident smuggle a visit in someone else's name.
     * Staff (full-log readers: security/treasurer/president/admin) see the tenant.
     */
    protected function buildIndexFetchQuery(Request $request, array $requestedRelations): Builder
    {
        return $this->scopeToOwnVisitorsUnlessStaff(
            parent::buildIndexFetchQuery($request, $requestedRelations)
        );
    }

    protected function buildShowFetchQuery(Request $request, array $requestedRelations): Builder
    {
        return $this->scopeToOwnVisitorsUnlessStaff(
            parent::buildShowFetchQuery($request, $requestedRelations)
        );
    }

    private function scopeToOwnVisitorsUnlessStaff(Builder $query): Builder
    {
        $user = auth()->guard('sanctum')->user();

        if ($user && ($user->tokenCan('*') || $user->tokenCan('read.access_log'))) {
            return $query;
        }

        // Residents see their OWN generated visitors, PLUS pending-approval requests
        // for units they actively belong to (§14.4.5). Those rows are guard-created,
        // so without this they would be invisible to the very resident who must
        // approve/reject them. Scoped to PENDING_APPROVAL only: a walk-in awaiting a
        // decision has no shareable QR, so this does not leak another member's tokens.
        $activeUnitIds = UnitUser::query()
            ->where('user_id', $user?->id)
            ->whereNull('end_date')
            ->pluck('unit_id');

        return $query->where(function (Builder $scoped) use ($user, $activeUnitIds): void {
            $scoped->where('created_by_user_id', $user?->id)
                ->orWhere(function (Builder $pending) use ($activeUnitIds): void {
                    $pending->whereIn('unit_id', $activeUnitIds)
                        ->where('visitor_status_id', VisitorStatus::PENDING_APPROVAL);
                });
        });
    }

    protected function performStore(OrionRequest $request, Model $entity, array $attributes): void
    {
        $visitor = app(AccessService::class)->generateQr(array_merge(
            $attributes,
            ['created_by_user_id' => $request->user()?->id]
        ));
        $entity->setRawAttributes($visitor->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }

    /**
     * The store caller is, by construction, the QR's owner (performStore stamps
     * created_by_user_id to the authenticated user). Reveal qr_token on the
     * entity Orion actually serializes — it is hidden by default for everyone
     * else (see Visitor::$hidden). Orion re-fetches a fresh entity AFTER
     * performStore, discarding any makeVisible set there, so it MUST run here.
     */
    protected function afterStore(Request $request, Model $entity)
    {
        $entity->makeVisible('qr_token');

        return null;
    }

    /**
     * The Visitantes list + search must still let a resident SEE/cancel THEIR OWN
     * QR (its code is needed to render/share it). qr_token is hidden by default
     * (Visitor::$hidden), so reveal it only on rows the caller created — never on
     * another resident's row, nor on guard-created pending-approval rows. Covers
     * the search path (the list), the index path, and the show path.
     */
    protected function afterSearch($query, &$entities)
    {
        // NOTE: no type hint on $query — the parent (QCH\Core ...Controller) declares
        // an unqualified `Builder` that resolves to a non-existent class, so a typed
        // override fails PHP's signature-compatibility check. Widening to untyped is
        // the only compatible override.
        parent::afterSearch($query, $entities);
        $this->revealOwnQrTokens($entities);
    }

    protected function afterIndex(Request $request, $entities)
    {
        $this->revealOwnQrTokens($entities);

        return null;
    }

    protected function afterShow(Request $request, Model $entity)
    {
        $this->revealOwnQrTokens([$entity]);

        return null;
    }

    /**
     * Reveal qr_token only on rows created by the authenticated caller.
     */
    private function revealOwnQrTokens(mixed $entities): void
    {
        $userId = auth()->guard('sanctum')->id();

        if (! $userId) {
            return;
        }

        $rows = $entities instanceof Paginator ? $entities->items() : $entities;

        foreach ($rows as $visitor) {
            if ($visitor->created_by_user_id === $userId) {
                $visitor->makeVisible('qr_token');
            }
        }
    }

    /**
     * Cancel a pending QR.
     */
    public function cancelQr(Request $request, string $id): JsonResponse
    {
        $visitor = Visitor::findOrFail($id);

        // Board/admin (broad update.visitor) may cancel any QR.
        // Otherwise, only the resident who generated the QR may cancel it.
        $user = auth()->guard('sanctum')->user();
        $hasBoardAccess = $user && ($user->tokenCan('update.visitor') || $user->tokenCan('*'));

        if (! $hasBoardAccess && (! $user || $visitor->created_by_user_id !== $user->id)) {
            throw new AuthorizationException('Unauthorized action.');
        }

        $updated = app(AccessService::class)->cancelQr($visitor);

        return response()->json(['data' => $updated]);
    }

    /**
     * Residents view their own visitors, with their access logs (§14.3.3
     * "Llegó / Salió") included.
     */
    public function mine(Request $request): JsonResponse
    {
        $visitors = Visitor::where('created_by_user_id', $request->user()?->id)
            ->with(['unit', 'visitor_motive', 'visitor_status', 'access_logs'])
            ->orderByDesc('created_at')
            ->paginate(15);

        // These are the caller's OWN visitors, so expose the token they need to
        // render/share each QR (hidden by default for everyone else).
        $visitors->getCollection()->each->makeVisible('qr_token');

        return response()->json($visitors);
    }

    /**
     * §14.4.5: resident approves a pending entry request. Only an ACTIVE member
     * of the visitor's unit may decide — no broad permission applies here.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $visitor = Visitor::findOrFail($id);
        $this->authorizeUnitMembership($request, $visitor);

        // R-DELQ-4 hook 3: membership check FIRST — a non-member must keep getting
        // the plain AuthorizationException above, never a debt-shaped 403 (design §5).
        app(DelinquencyRestrictionService::class)->assertNotRestricted(
            $visitor->unit_id,
            DelinquencyRestrictionService::FEATURE_VISITOR_ACCESS,
        );

        $accessLog = app(AccessService::class)->approveEntry($visitor, $request->user());

        if ($accessLog === null) {
            return response()->json(['message' => 'La solicitud ya no está pendiente de aprobación.'], 422);
        }

        return response()->json(['data' => ['visitor' => $visitor->fresh(['visitor_status']), 'access_log' => $accessLog]]);
    }

    /**
     * §14.4.5: resident rejects a pending entry request.
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $visitor = Visitor::findOrFail($id);
        $this->authorizeUnitMembership($request, $visitor);

        // R-DELQ-4 hook 3: membership check FIRST (see approve()).
        app(DelinquencyRestrictionService::class)->assertNotRestricted(
            $visitor->unit_id,
            DelinquencyRestrictionService::FEATURE_VISITOR_ACCESS,
        );

        $deniedLog = app(AccessService::class)->rejectEntry($visitor, $request->user());

        if ($deniedLog === null) {
            return response()->json(['message' => 'La solicitud ya no está pendiente de aprobación.'], 422);
        }

        return response()->json(['data' => ['visitor' => $visitor->fresh(['visitor_status']), 'access_log' => $deniedLog]]);
    }

    /**
     * Only an active resident (unit_user without end_date) of the visitor's unit
     * may approve/reject its entry.
     */
    private function authorizeUnitMembership(Request $request, Visitor $visitor): void
    {
        $userId = $request->user()?->id;

        $isMember = $userId !== null && UnitUser::where('unit_id', $visitor->unit_id)
            ->where('user_id', $userId)
            ->whereNull('end_date')
            ->exists();

        if (! $isMember) {
            throw new AuthorizationException('Unauthorized action.');
        }
    }
}
