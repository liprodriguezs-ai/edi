<?php

namespace Modules\Tracking\Contracts;

use Modules\Tracking\DTO\TrackingEvent;

interface TrackingChannelInterface
{
    /**
     * Send one tracking event to this channel's destination (Facebook Graph
     * API, GA4 Measurement Protocol, log). Errors PROPAGATE on purpose: this
     * runs inside DeliverTrackingEventJob, so a transport failure must surface
     * for the job to retry — mirrors NotificationChannelInterface.
     */
    public function send(TrackingEvent $event): void;
}
