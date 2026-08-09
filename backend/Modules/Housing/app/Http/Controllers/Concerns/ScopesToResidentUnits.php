<?php

namespace Modules\Housing\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Housing\Models\UnitUser;
use Orion\Http\Requests\Request;
use QCH\Core\Models\Role;

/**
 * §6.2 / §6.4.1 — residents and renters may only see THEIR unit(s).
 *
 * Overrides Orion's buildFetchQuery (shared by index, search, show, update,
 * destroy and restore) so that, when the authenticated user's ONLY roles are
 * resident/renter, every fetch is limited to the units the user is actively
 * associated with via the unit_user pivot (end_date IS NULL). Staff and board
 * roles keep the full cluster view (§6.2 matrix).
 */
trait ScopesToResidentUnits
{
    protected function buildFetchQuery(Request $request, array $requestedRelations): Builder
    {
        $query = parent::buildFetchQuery($request, $requestedRelations);

        return $this->applyResidentUnitScope($query);
    }

    protected function applyResidentUnitScope(Builder $query): Builder
    {
        $user = auth()->guard('sanctum')->user();

        if (! $user instanceof User || ! $this->isResidentOnlyActor($user)) {
            return $query;
        }

        return $query->whereIn(
            $query->qualifyColumn($this->residentUnitScopeColumn()),
            $this->activeUnitIdsFor($user->id)
        );
    }

    /**
     * Column on the controller's model that references the unit
     * ('id' on unit itself, 'unit_id' on unit-owned resources).
     */
    protected function residentUnitScopeColumn(): string
    {
        return 'unit_id';
    }

    /**
     * Active unit ids for a user via the unit_user pivot (tenant-scoped model).
     *
     * @return array<int, string>
     */
    protected function activeUnitIdsFor(string $userId): array
    {
        return UnitUser::where('user_id', $userId)
            ->whereNull('end_date')
            ->pluck('unit_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * True when the user's ONLY roles are resident/renter. A person who also
     * holds a staff/board role (treasurer, president, security, …) keeps the
     * full cluster view.
     */
    protected function isResidentOnlyActor(User $user): bool
    {
        $roleNames = $user->roles->pluck('name');

        return $roleNames->isNotEmpty()
            && $roleNames->every(
                static fn (string $name): bool => in_array($name, [Role::SLUG_RESIDENT, Role::SLUG_RENTER], true)
            );
    }
}
