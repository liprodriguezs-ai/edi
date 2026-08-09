<?php

namespace Modules\Projects\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Modules\Projects\Models\ProjectComment;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;

class ProjectCommentController extends Controller
{
    protected $model = ProjectComment::class;

    protected $keyForPermission = 'project_comment';

    public function includes(): array
    {
        return [
            'user',
            'parent',
            'replies',
        ];
    }

    public function filterableBy(): array
    {
        return [
            'community_project_id',
            'parent_id',
            'hidden',
        ];
    }

    public function sortableBy(): array
    {
        return ['created_at'];
    }

    public function searchableBy(): array
    {
        return ['body'];
    }

    /**
     * §11.8.8 (finding projects-08): hidden (moderated) comments only remain visible
     * to moderators (update.project_comment). Residents never read them.
     */
    protected function buildIndexFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return $this->scopeVisibleComments(parent::buildIndexFetchQuery($request, $requestedRelations));
    }

    protected function buildShowFetchQuery(OrionRequest $request, array $requestedRelations): Builder
    {
        return $this->scopeVisibleComments(parent::buildShowFetchQuery($request, $requestedRelations));
    }

    /**
     * Finding projects-07: the author is ALWAYS the authenticated user and a new
     * comment is never born hidden — impersonation through mass assignment is
     * impossible.
     */
    protected function performStore(OrionRequest $request, Model $entity, array $attributes): void
    {
        unset($attributes['user_id'], $attributes['hidden']);

        $entity->fill($attributes);
        $entity->user_id = auth()->guard('sanctum')->id();
        $entity->hidden = false;
        $entity->save();
    }

    protected function performUpdate(OrionRequest $request, Model $entity, array $attributes): void
    {
        unset(
            $attributes['user_id'],
            $attributes['hidden'],
            $attributes['community_project_id'],
            $attributes['parent_id'],
        );

        $entity->fill($attributes);
        $entity->save();
    }

    /**
     * POST /project-comment/{id}/hide
     * Hide a comment (board moderation — does not delete).
     */
    public function hide(int|string $id): JsonResponse
    {
        $this->authorize('update.project_comment', ProjectComment::class);

        $comment = ProjectComment::findOrFail($id);
        $comment->hidden = true;
        $comment->save();

        return response()->json(['data' => $comment]);
    }

    protected function scopeVisibleComments(Builder $query): Builder
    {
        $user = auth()->guard('sanctum')->user();
        $isModerator = $user !== null && ($user->tokenCan('update.project_comment') || $user->tokenCan('*'));

        if ($isModerator) {
            return $query;
        }

        return $query->where('hidden', false);
    }
}
