<?php

namespace Modules\Board\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Modules\Board\Models\BoardMember;
use Modules\Board\Services\BoardService;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class BoardMemberController extends Controller
{
    protected $model = BoardMember::class;

    protected $keyForPermission = 'board_member';

    public function includes(): array
    {
        return ['board_period', 'board_role', 'user'];
    }

    public function filterableBy(): array
    {
        return ['board_period_id', 'board_role_id', 'user_id'];
    }

    public function sortableBy(): array
    {
        return ['created_at'];
    }

    /**
     * Direct seat creation must grant the mapped Spatie role exactly like the
     * startPeriod flow (§7.3.2: "al asignar cargo, el sistema asigna el rol").
     */
    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $entity->fill($attributes);
        $entity->save();

        if ($entity->board_period()->first()?->isCurrent()) {
            app(BoardService::class)->grantSpatieRole($entity);
        }
    }

    /**
     * Changing a seat (user, role or period) revokes the previous grant and
     * grants the new one — only while the seat's period is CURRENT.
     */
    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        $previous = clone $entity;

        $entity->fill($attributes);
        $entity->save();

        $seatChanged = $previous->user_id !== $entity->user_id
            || $previous->board_role_id !== $entity->board_role_id
            || $previous->board_period_id !== $entity->board_period_id;

        if (! $seatChanged) {
            return;
        }

        $boardService = app(BoardService::class);

        if ($previous->board_period()->first()?->isCurrent()) {
            $boardService->revokeSpatieRole($previous);
        }

        if ($entity->board_period()->first()?->isCurrent()) {
            $boardService->grantSpatieRole($entity);
        }
    }

    /**
     * Removing a seat from a current period revokes the mapped Spatie role.
     */
    protected function performDestroy(Model $entity): void
    {
        if ($entity->board_period()->first()?->isCurrent()) {
            app(BoardService::class)->revokeSpatieRole($entity);
        }

        $entity->delete();
    }

    /**
     * Restoring a seat into a current period re-grants the mapped Spatie role.
     */
    protected function performRestore(Model $entity): void
    {
        $entity->restore();

        if ($entity->board_period()->first()?->isCurrent()) {
            app(BoardService::class)->grantSpatieRole($entity);
        }
    }
}
