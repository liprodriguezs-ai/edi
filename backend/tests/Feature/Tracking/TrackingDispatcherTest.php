<?php

declare(strict_types=1);

namespace Tests\Feature\Tracking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Modules\Tracking\Channels\FacebookConversionsChannel;
use Modules\Tracking\DTO\TrackingEvent;
use Modules\Tracking\Jobs\DeliverTrackingEventJob;
use Modules\Tracking\Services\TrackingDispatcher;
use Tests\TestCase;

/**
 * Coverage for TrackingDispatcher: channel resolution, config-driven defaults,
 * explicit overrides, and one queued job per channel — mirrors
 * NotificationChannelTest for App\Notifications\Services\NotificationDispatcher.
 */
final class TrackingDispatcherTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private function event(string $name = 'Lead'): TrackingEvent
    {
        return TrackingEvent::fromArray(['name' => $name]);
    }

    public function test_dispatch_uses_configured_channels_by_default(): void
    {
        Queue::fake();
        config(['tracking.channels' => ['log']]);

        app(TrackingDispatcher::class)->dispatch($this->event());

        Queue::assertPushed(
            DeliverTrackingEventJob::class,
            static fn (DeliverTrackingEventJob $job): bool => $job->channel === 'log',
        );
        Queue::assertPushed(DeliverTrackingEventJob::class, 1);
    }

    public function test_dispatch_explicit_channel_list_overrides_config(): void
    {
        Queue::fake();
        config(['tracking.channels' => ['log']]);

        app(TrackingDispatcher::class)->dispatch($this->event('Purchase'), ['facebook', 'google_analytics']);

        Queue::assertPushed(DeliverTrackingEventJob::class, 2);
        Queue::assertPushed(
            DeliverTrackingEventJob::class,
            static fn (DeliverTrackingEventJob $job): bool => $job->channel === 'facebook' && $job->event->name === 'Purchase',
        );
        Queue::assertPushed(
            DeliverTrackingEventJob::class,
            static fn (DeliverTrackingEventJob $job): bool => $job->channel === 'google_analytics',
        );
    }

    public function test_dispatch_throws_for_unknown_channel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not registered/');

        app(TrackingDispatcher::class)->dispatch($this->event(), ['nonexistent_channel']);
    }

    public function test_make_channel_resolves_registered_drivers(): void
    {
        $dispatcher = app(TrackingDispatcher::class);

        $this->assertInstanceOf(FacebookConversionsChannel::class, $dispatcher->makeChannel('facebook'));
    }
}
