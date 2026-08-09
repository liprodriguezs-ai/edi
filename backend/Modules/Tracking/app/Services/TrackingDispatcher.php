<?php

namespace Modules\Tracking\Services;

use InvalidArgumentException;
use Modules\Tracking\Channels\FacebookConversionsChannel;
use Modules\Tracking\Channels\GoogleAnalyticsChannel;
use Modules\Tracking\Channels\LogChannel;
use Modules\Tracking\Contracts\TrackingChannelInterface;
use Modules\Tracking\DTO\TrackingEvent;
use Modules\Tracking\Jobs\DeliverTrackingEventJob;

/**
 * TrackingDispatcher — resolves configured channel drivers and enqueues one
 * DeliverTrackingEventJob per channel. Mirrors
 * App\Notifications\Services\NotificationDispatcher.
 *
 * Active channels come from config('tracking.channels') unless the caller
 * passes an explicit override — used by server-only hooks (Stripe webhook,
 * registration) that must reach BOTH Facebook and GA4, and by the public
 * /api/track endpoint, which dispatches to Facebook ONLY (GA4 is sent
 * client-side by gtag — dispatching it here too would double-count).
 */
class TrackingDispatcher
{
    /**
     * Maps channel name → concrete implementation class.
     *
     * @var array<string, class-string<TrackingChannelInterface>>
     */
    private const CHANNEL_MAP = [
        'log' => LogChannel::class,
        'facebook' => FacebookConversionsChannel::class,
        'google_analytics' => GoogleAnalyticsChannel::class,
    ];

    /**
     * Dispatch a tracking event through the given (or configured) channels.
     *
     * @param  string[]|null  $channels  explicit channel list; falls back to config('tracking.channels') when null
     */
    public function dispatch(TrackingEvent $event, ?array $channels = null): void
    {
        $channels ??= config('tracking.channels', ['log']);

        foreach ($channels as $name) {
            // Validate synchronously so an unknown channel fails fast in the
            // request — never silently swallowed by a queued worker.
            $this->assertChannelRegistered($name);

            DeliverTrackingEventJob::dispatch($name, $event);
        }
    }

    /**
     * Resolve a channel driver by name. PUBLIC so the queued
     * DeliverTrackingEventJob can rebuild the channel on the worker from the
     * registered CHANNEL_MAP (resolving through the container so test spies
     * bound to the channel class still apply).
     *
     * @throws InvalidArgumentException when the channel name is not registered.
     */
    public function makeChannel(string $name): TrackingChannelInterface
    {
        $this->assertChannelRegistered($name);

        return app(self::CHANNEL_MAP[$name]);
    }

    /**
     * @throws InvalidArgumentException when the channel name is not registered.
     */
    private function assertChannelRegistered(string $name): void
    {
        if (! array_key_exists($name, self::CHANNEL_MAP)) {
            throw new InvalidArgumentException(
                "Tracking channel '{$name}' is not registered. Available: ".implode(', ', array_keys(self::CHANNEL_MAP))
            );
        }
    }
}
