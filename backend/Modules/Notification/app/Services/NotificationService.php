<?php

namespace Modules\Notification\Services;

use Modules\Notification\Jobs\NotificationJob;
use Modules\Notification\Models\Notification;

class NotificationService
{
    /**
     * Create an admin broadcast Notification and queue its fan-out:
     * NotificationJob → NotificationUserJob creates the per-user in-app rows
     * and sends FCM push to the recipients' stored device tokens.
     *
     * @param  array<string, mixed>  $attributes  validated attributes (NotificationCreateRequest)
     */
    public function create(array $attributes): Notification
    {
        $attributes['status'] = 'pending';

        $notification = Notification::create($attributes);

        NotificationJob::dispatch($notification);

        return $notification;
    }
}
