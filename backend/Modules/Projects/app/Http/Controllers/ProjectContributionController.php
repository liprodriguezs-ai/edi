<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Modules\Housing\Models\UnitUser;
use Modules\Projects\Models\CommunityProjectStatus;
use Modules\Projects\Models\ProjectContribution;
use Modules\Projects\Models\ProjectContributionStatus;
use Modules\Projects\Services\ContributionService;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;

class ProjectContributionController extends Controller
{
    protected $model = ProjectContribution::class;

    protected $keyForPermission = 'project_contribution';

    public function includes(): array
    {
        return [
            'project',
            'unit',
            'contributionStatus',
        ];
    }

    public function filterableBy(): array
    {
        return [
            'community_project_id',
            'unit_id',
            'project_contribution_status_id',
            'declined',
        ];
    }

    public function sortableBy(): array
    {
        return ['contributed_amount', 'expected_amount', 'created_at'];
    }

    /**
     * Only text columns / dotted relations — NEVER a uuid column (lower(uuid) 500s in
     * Postgres) and NEVER unit.full_identifier (an accessor, not a real column).
     */
    public function searchableBy(): array
    {
        return ['unit.number', 'project.title'];
    }

    /**
     * Privacy scoping (server-side security fix): a resident only sees contribution
     * rows of VISIBLE, non-draft projects, and within those only their OWN units'
     * rows UNLESS the project has payments_visible_to_residents=true (then all rows).
     * Board/admin (update.project_contribution) see everything.
     */
    protected function buildIndexFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return $this->scopeVisibleContributions(parent::buildIndexFetchQuery($request, $requestedRelations));
    }

    protected function buildShowFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return $this->scopeVisibleContributions(parent::buildShowFetchQuery($request, $requestedRelations));
    }

    protected function scopeVisibleContributions(Builder $query): Builder
    {
        $user = auth()->guard('sanctum')->user();

        if ($user && ($user->tokenCan('update.project_contribution') || $user->tokenCan('*'))) {
            return $query; // board sees everything
        }

        $ownUnitIds = UnitUser::query()
            ->where('user_id', $user?->id)
            ->whereNull('end_date')
            ->pluck('unit_id');

        return $query
            ->whereHas('project', fn (Builder $p) => $p
                ->where('visible_to_residents', true)
                ->where('community_project_status_id', '!=', CommunityProjectStatus::DRAFT))
            ->where(fn (Builder $q) => $q
                ->whereHas('project', fn (Builder $p) => $p->where('payments_visible_to_residents', true))
                ->orWhereIn('unit_id', $ownUnitIds));
    }

    /**
     * Derived money/status columns are owned by ContributionService — a stored
     * placeholder always starts at zero/pending (findings projects-09 / sec-input-09).
     */
    protected function performStore(OrionRequest $request, Model $entity, array $attributes): void
    {
        unset($attributes['contributed_amount'], $attributes['declined'], $attributes['project_contribution_status_id']);

        $entity->fill($attributes);
        $entity->contributed_amount = 0;
        $entity->declined = false;
        $entity->project_contribution_status_id = ProjectContributionStatus::PENDING;
        $entity->save();
    }

    protected function performUpdate(OrionRequest $request, Model $entity, array $attributes): void
    {
        unset(
            $attributes['contributed_amount'],
            $attributes['declined'],
            $attributes['project_contribution_status_id'],
            $attributes['community_project_id'],
            $attributes['unit_id'],
        );

        $entity->fill($attributes);
        $entity->save();
    }

    /**
     * POST /project-contribution/{id}/decline
     * Mark contribution as declined (voluntary projects with allow_decline only).
     */
    public function decline(int|string $id): JsonResponse
    {
        $contribution = ProjectContribution::findOrFail($id);
        $this->authorizeContributionAction($contribution);

        try {
            $updated = app(ContributionService::class)->decline($contribution);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $updated->load('contributionStatus')]);
    }

    /**
     * POST /project-contribution/{id}/revert-decline
     * Revert a declined contribution back to pending (resident changed their mind).
     */
    public function revertDecline(int|string $id): JsonResponse
    {
        $contribution = ProjectContribution::findOrFail($id);
        $this->authorizeContributionAction($contribution);

        try {
            $updated = app(ContributionService::class)->revertDecline($contribution);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $updated->load('contributionStatus')]);
    }

    /**
     * Authorize a contribution mutation: board/admin (broad update.project_contribution)
     * may act on any contribution; otherwise only a member of the contributing unit may
     * act on their own. Mirrors the decline authorization used by revert-decline.
     *
     * @throws AuthorizationException
     */
    private function authorizeContributionAction(ProjectContribution $contribution): void
    {
        $user = auth()->guard('sanctum')->user();
        $hasBoardAccess = $user && ($user->tokenCan('update.project_contribution') || $user->tokenCan('*'));

        if ($hasBoardAccess) {
            return;
        }

        $belongsToUnit = $user && $contribution->unit_id && UnitUser::where('unit_id', $contribution->unit_id)
            ->where('user_id', $user->id)
            ->whereNull('end_date')
            ->exists();

        if (! $belongsToUnit) {
            throw new AuthorizationException('Unauthorized action.');
        }
    }
}
