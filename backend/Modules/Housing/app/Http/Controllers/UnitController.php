<?php

namespace Modules\Housing\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Validation\Rule;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\Payment;
use Modules\Housing\Http\Controllers\Concerns\GuardsUnitIdentity;
use Modules\Housing\Http\Controllers\Concerns\ScopesToResidentUnits;
use Modules\Housing\Models\Unit;
use Modules\Housing\Services\UnitUserService;
use Modules\SubscriptionPlan\Services\UnitLimitService;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class UnitController extends Controller
{
    use GuardsUnitIdentity;
    use ScopesToResidentUnits;

    protected $model = Unit::class;

    protected $keyForPermission = 'unit';

    public function includes(): array
    {
        return ['unitType', 'unitStatus', 'tower', 'section', 'features', 'vehicles'];
    }

    public function searchableBy(): array
    {
        return ['number', 'street', 'interior_number', 'floor', 'notes', 'unitType.name', 'tower.name', 'section.name'];
    }

    public function sortableBy(): array
    {
        return ['number', 'floor', 'created_at', 'ownership_share'];
    }

    public function filterableBy(): array
    {
        return ['id', 'unit_type_id', 'unit_status_id', 'tower_id', 'section_id', 'floor'];
    }

    /**
     * The resident scope on unit filters by the unit's own id.
     */
    protected function residentUnitScopeColumn(): string
    {
        return 'id';
    }

    /**
     * §6.3.3 — duplicate identity check runs AFTER field validation (which the
     * core DelegatingModuleRequest wiring already triggers) and BEFORE the insert,
     * so the API answers 422 with the spec message instead of a 500 from the
     * unit_identity_unique index.
     */
    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $this->assertUnitIdentityIsUnique([
            'unit_type_id' => $attributes['unit_type_id'] ?? null,
            'tower_id' => $attributes['tower_id'] ?? null,
            'floor' => $attributes['floor'] ?? null,
            'number' => $attributes['number'] ?? null,
        ]);

        parent::performStore($request, $entity, $attributes);
    }

    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        /** @var Unit $entity */
        $this->assertUnitIdentityIsUnique($this->effectiveUnitIdentity($entity, $attributes), $entity->id);

        parent::performUpdate($request, $entity, $attributes);
    }

    /**
     * unit-limit-enforcement (Decision 2): Orion already wraps batchStore in ONE
     * transaction (transactions enabled — config/orion.php:33), so even a mid-batch
     * `creating()` throw from UnitObserver rolls back all rows — atomicity holds
     * regardless. This pre-flight exists for an ACCURATE all-or-nothing error
     * (a row-6 failure would otherwise report a mid-batch count) and to avoid
     * wasted inserts + charge generation before the rollback.
     */
    protected function beforeBatchStore(Request $request)
    {
        app(UnitLimitService::class)->assertCanIncrease(count($this->retrieve($request, 'resources', [])));
    }

    /**
     * unit-limit-enforcement (Decision 2): only the CURRENTLY trashed ids among
     * the requested keys count toward the increase — a caller mixing a live id
     * with trashed ones must only be charged for the trashed ones, consistent
     * with UnitObserver::restoring()'s per-row guard.
     */
    protected function beforeBatchRestore(Request $request)
    {
        $keys = $this->resolveResourceKeys($request);

        $trashedCount = Unit::onlyTrashed()->whereIn('id', $keys)->count();

        app(UnitLimitService::class)->assertCanIncrease($trashedCount);
    }

    /**
     * §6.5 r2 — a unit with charge/payment history can NEVER be deleted (soft or
     * force): it must be marked inactive instead, so the financial trail stays
     * attached to a live row.
     */
    protected function beforeDestroy(Request $request, Model $entity): void
    {
        $hasHistory = Charge::withTrashed()->where('unit_id', $entity->id)->exists()
            || Payment::withTrashed()->where('unit_id', $entity->id)->exists();

        if ($hasHistory) {
            throw new UnprocessableEntityHttpException(
                'No se puede eliminar una unidad con histórico de cargos o pagos. Márcala como inactiva.'
            );
        }
    }

    /**
     * GET /api/unit/my — vista "Mi unidad" (§6.4.1): the authenticated user's own
     * unit(s) with full detail, resolved via their ACTIVE unit_user associations.
     */
    public function myUnits(HttpRequest $request): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();

        if (! $user || ! ($user->tokenCan('read.unit') || $user->tokenCan('*'))) {
            throw new AuthorizationException('Unauthorized action.');
        }

        $units = Unit::with([
            'unitType', 'unitStatus', 'tower', 'section', 'features', 'vehicles',
            'unitUsers' => static fn ($query) => $query->whereNull('end_date'),
            'unitUsers.user', 'unitUsers.unitRelationship',
        ])
            ->whereIn('id', $this->activeUnitIdsFor($user->id))
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => $units]);
    }

    /**
     * POST /api/unit/{id}/change-owner — §6.3.5: atomically closes the current
     * owner association and creates the new owner as titular de pago, notifying
     * previous and new owners (§6.7). Admin-only via the change_owner.unit ability.
     */
    public function changeOwner(HttpRequest $request, UnitUserService $service, string $unitId): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();

        if (! $user || ! ($user->tokenCan('change_owner.unit') || $user->tokenCan('*'))) {
            throw new AuthorizationException('Unauthorized action.');
        }

        $validated = $request->validate([
            // user is a CENTRAL table → exists() must NOT be tenant-scoped (42703).
            'user_id' => ['required', 'uuid', Rule::exists('user', 'id')],
            'change_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $unit = Unit::findOrFail($unitId);

        try {
            $newOwner = $service->changeOwner($unit, $validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Propietario actualizado correctamente.',
            'data' => $newOwner->load(['unit', 'user', 'unitRelationship']),
        ]);
    }
}
