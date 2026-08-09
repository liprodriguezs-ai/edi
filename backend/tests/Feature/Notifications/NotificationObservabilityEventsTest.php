<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Notifications\Channels\SmsChannel;
use App\Notifications\Contracts\NotificationChannelInterface;
use App\Notifications\DispatchedNotification;
use App\Notifications\Events\AmenityReservationApprovedNotification;
use App\Notifications\Events\PaymentAcceptedNotification;
use App\Notifications\Services\NotificationDispatcher;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Modules\Notification\Jobs\DeliverChannelMessageJob;
use RuntimeException;
use Tests\TestCase;

/**
 * The engine is custom, so nothing in this app emits Laravel's notification
 * events and APM tooling (Nightwatch) reports zero notifications. The delivery
 * job now emits the NotificationSending/NotificationSent pair around every send,
 * naming the BUSINESS EVENT via a per-event notification class.
 *
 * The job is exercised directly on purpose: the test suite pins
 * NOTIFICATION_CHANNELS=log and the `log` channel is delivered INLINE by the
 * dispatcher (never queued), so going through the full dispatcher would never
 * reach this job.
 */
final class NotificationObservabilityEventsTest extends TestCase
{
    /**
     * Swap the SMS channel for an in-memory spy so the job performs no I/O.
     */
    private function fakeSmsChannel(?\Throwable $failure = null): object
    {
        $channel = new class($failure) implements NotificationChannelInterface
        {
            /** @var array<int, array<string|int, mixed>> */
            public array $sent = [];

            public function __construct(private ?\Throwable $failure) {}

            public function send(array $recipients, string $title, string $body, array $data = []): void
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                $this->sent[] = $recipients;
            }
        };

        $this->app->instance(SmsChannel::class, $channel);

        return $channel;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function runJob(array $data, string $channel = 'sms'): void
    {
        (new DeliverChannelMessageJob($channel, ['+5215500000000'], 'Título', 'Cuerpo', $data))
            ->handle($this->app->make(NotificationDispatcher::class));
    }

    public function test_it_emits_the_event_pair_with_the_channel_and_the_event_specific_class(): void
    {
        Event::fake([NotificationSending::class, NotificationSent::class]);
        $channel = $this->fakeSmsChannel();

        $this->runJob(['email_template' => 'payment_accepted']);

        $this->assertCount(1, $channel->sent, 'the channel must still deliver');

        Event::assertDispatched(
            NotificationSending::class,
            static fn (NotificationSending $event): bool => $event->channel === 'sms'
                && $event->notification instanceof PaymentAcceptedNotification
                && $event->notification->eventKey === 'payment_accepted',
        );

        Event::assertDispatched(
            NotificationSent::class,
            static fn (NotificationSent $event): bool => $event->channel === 'sms'
                && $event->notification instanceof PaymentAcceptedNotification
                && $event->response === null,
        );
    }

    public function test_dotted_event_keys_resolve_to_their_own_notification_class(): void
    {
        Event::fake([NotificationSending::class, NotificationSent::class]);
        $this->fakeSmsChannel();

        $this->runJob(['email_template' => 'amenity_reservation.approved']);

        Event::assertDispatched(
            NotificationSent::class,
            static fn (NotificationSent $event): bool => $event->notification::class
                === AmenityReservationApprovedNotification::class,
        );
    }

    public function test_an_unknown_event_key_falls_back_to_the_generic_notification(): void
    {
        Event::fake([NotificationSending::class, NotificationSent::class]);
        $this->fakeSmsChannel();

        $this->runJob(['email_template' => 'brand_new_event_nobody_declared']);

        Event::assertDispatched(
            NotificationSent::class,
            static fn (NotificationSent $event): bool => $event->notification::class === DispatchedNotification::class
                && $event->notification->eventKey === 'brand_new_event_nobody_declared',
        );
    }

    public function test_a_payload_without_an_event_key_still_reports_a_generic_notification(): void
    {
        Event::fake([NotificationSending::class, NotificationSent::class]);
        $this->fakeSmsChannel();

        $this->runJob([]);

        Event::assertDispatched(
            NotificationSending::class,
            static fn (NotificationSending $event): bool => $event->notification::class === DispatchedNotification::class
                && $event->notification->eventKey === 'notification',
        );
    }

    public function test_a_failed_delivery_emits_sending_but_never_sent(): void
    {
        Event::fake([NotificationSending::class, NotificationSent::class]);
        $this->fakeSmsChannel(new RuntimeException('transport down'));

        try {
            $this->runJob(['email_template' => 'payment_accepted']);
            $this->fail('the transport exception must propagate so the job retries');
        } catch (RuntimeException $e) {
            $this->assertSame('transport down', $e->getMessage());
        }

        Event::assertDispatched(NotificationSending::class);
        Event::assertNotDispatched(NotificationSent::class);
    }
}
