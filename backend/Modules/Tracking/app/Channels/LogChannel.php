<?php

namespace Modules\Tracking\Channels;

use Illuminate\Support\Facades\Log;
use Modules\Tracking\Contracts\TrackingChannelInterface;
use Modules\Tracking\DTO\TrackingEvent;

/**
 * LogChannel — default driver used in dev and test environments.
 *
 * Writes the event to the application log without making a real network call,
 * mirroring App\Notifications\Channels\LogChannel.
 */
class LogChannel implements TrackingChannelInterface
{
    public function send(TrackingEvent $event): void
    {
        Log::info('[TrackingChannel:log] Event dispatched', [
            'name' => $event->name,
            'event_id' => $event->eventId,
            'params' => $event->params,
        ]);
    }
}
