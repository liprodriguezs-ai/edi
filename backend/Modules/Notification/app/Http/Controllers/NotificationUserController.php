<?php

namespace Modules\Notification\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Modules\Notification\Models\NotificationUser;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

/**
 * Notification center (§17.2) — strictly per-user.
 *
 * Every fetch path (index/show/search/update/destroy/restore + batch) is forced
 * to the authenticated user's own rows via buildFetchQuery(), so authorize() can
 * stay open (no Spatie permission needed to read YOUR OWN notifications) without
 * exposing other members' rows: foreign ids resolve to 404.
 */
class NotificationUserController extends Controller
{
    protected $model = NotificationUser::class;

    public function searchableBy(): array
    {
        return [
            'user_id',
            'notification_id',
            'is_read',
        ];
    }

    public function includes(): array
    {
        return [
            'notification',
            'user',
            'company',
            'country',
        ];
    }

    /**
     * The relations and fields that are allowed to be aggregated on a resource.
     */
    public function aggregates(): array
    {
        return ['notification'];
    }

    public function filterableBy(): array
    {
        return [
            'user_id',
            'notification_id',
            'is_read',
        ];
    }

    /**
     * Scope EVERY fetch (index/show/update/destroy/restore/batch) to the
     * authenticated user — the notification center is per-user (§17.2).
     */
    protected function buildFetchQuery(Request $request, array $requestedRelations): Builder
    {
        return parent::buildFetchQuery($request, $requestedRelations)
            ->where('notification_user.user_id', (string) auth()->guard('sanctum')->id());
    }

    /**
     * A user can only create notification-center rows for themself.
     */
    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $attributes['user_id'] = (string) auth()->guard('sanctum')->id();

        parent::performStore($request, $entity, $attributes);
    }

    /**
     * Ownership is immutable: updates may toggle is_read but never reassign the row.
     */
    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        unset($attributes['user_id']);

        parent::performUpdate($request, $entity, $attributes);
    }

    /**
     * POST /api/notification_user/mark-all-read — bulk "[Marcar todas leídas]"
     * (§17.2). One query over the authenticated user's OWN unread rows; the
     * tenant scope (BelongsToTenant) still applies on top.
     */
    public function markAllRead(): JsonResponse
    {
        $marked = NotificationUser::query()
            ->where('user_id', (string) auth()->guard('sanctum')->id())
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'message' => 'Notificaciones marcadas como leídas.',
            'data' => ['marked' => $marked],
        ]);
    }

    /**
     * Authorize a given action for the current user.
     *
     * Open on purpose: residents have no Spatie permission for their own center.
     * Row-level safety is enforced by buildFetchQuery()/performStore() above.
     *
     * @param  mixed  $ability
     * @param  mixed|array  $arguments
     * @return Response
     *
     * @throws AuthorizationException
     */
    public function authorize($ability, $arguments = [])
    {
        return $this->authorized();
    }
}
