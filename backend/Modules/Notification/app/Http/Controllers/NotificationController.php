<?php

namespace Modules\Notification\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Notification\Http\Requests\NotificationCreateRequest;
use Modules\Notification\Models\Notification;
use Modules\Notification\Services\NotificationService;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class NotificationController extends Controller
{
    protected $model = Notification::class;

    protected $keyForPermission = 'notification';

    public function includes(): array
    {
        return ['user'];
    }

    public function authorize($ability, $arguments = [])
    {
        [$ability, $arguments] = $this->parseAbilityAndArguments($ability, $arguments);
        if ($ability == "read.{$this->keyForPermission}") {
            return $this->authorized();
        }

        return parent::authorize($ability, $arguments);
    }

    /**
     * Read is open to every member (§17.2), but without the real
     * read.notification ability the listing is limited to the notifications
     * the user actually received (own notification_user rows) — broadcast
     * content of other units (amounts, receipt numbers) must not leak.
     */
    protected function buildFetchQuery(Request $request, array $requestedRelations): Builder
    {
        $query = parent::buildFetchQuery($request, $requestedRelations);

        $user = auth()->guard('sanctum')->user();

        if ($user && ! $user->tokenCan("read.{$this->keyForPermission}")) {
            $query->whereHas('notification_user', static function (Builder $subQuery) use ($user): void {
                $subQuery->where('notification_user.user_id', (string) $user->getKey());
            });
        }

        return $query;
    }

    /**
     * Delegates create to NotificationService while keeping Orion's store flow.
     * Only validated attributes reach the model — a client can never choose the
     * primary key or any non-whitelisted column.
     */
    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $validated = app(NotificationCreateRequest::class)->validated();

        $created = app(NotificationService::class)->create($validated);
        $entity->setRawAttributes($created->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }
}
