<?php

namespace Modules\Notification\Jobs;

use App\Notifications\DispatchedNotification;
use App\Notifications\Services\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Off-request delivery of ONE notification to ONE recipient through ONE channel.
 *
 * The dispatcher resolves addresses + filters preferences/quiet-hours INLINE (in
 * the request, where the tenant context is correct), then enqueues one of these
 * jobs per (channel × recipient) with the address ALREADY resolved. So the job is
 * a dumb sender: it instantiates the channel and calls send() — it does NO
 * tenant-scoped queries, which keeps it safe to run on a worker.
 *
 * Per-recipient (not per-batch) on purpose: a retry of one failed delivery never
 * re-sends to recipients that already succeeded (no duplicate email/SMS/push), and
 * each email goes to its own `To` (recipients never see each other).
 *
 * ShouldQueue → async on a real queue (redis), inline on `sync` (tests/dev).
 * Channels let transport exceptions PROPAGATE so $tries actually retries.
 */
class DeliverChannelMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<int, string>  $recipients  already-resolved channel addresses (usually 1)
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $channel,
        public array $recipients,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    /**
     * Escalating backoff between retries (seconds).
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * Deliver the message and surface it to notification observability tooling.
     *
     * The engine is custom, so Laravel never emits NotificationSending /
     * NotificationSent for these deliveries and APMs (Nightwatch) report zero
     * notifications. Wrapping the send in that event pair restores the panel:
     * DispatchedNotification::for() materialises the business event key as a
     * class, which is the only per-event identity the sensor persists (it stores
     * channel + notification class + duration).
     *
     * `Sending` is emitted BEFORE the send because the sensor throws when a
     * `Sent` arrives without its start time, and the send is NOT wrapped in a
     * try/catch: on a transport failure no `Sent` is emitted (the count reflects
     * real deliveries) and $tries retries exactly as before. `$response` is null
     * because the channel contract returns void.
     */
    public function handle(NotificationDispatcher $dispatcher): void
    {
        $notifiable = (new AnonymousNotifiable)->route($this->channel, $this->recipients[0] ?? 'unknown');
        $notification = DispatchedNotification::for(
            isset($this->data['email_template']) ? (string) $this->data['email_template'] : 'notification',
        );

        event(new NotificationSending($notifiable, $notification, $this->channel));

        $dispatcher->makeChannel($this->channel)
            ->send($this->recipients, $this->title, $this->body, $this->data);

        event(new NotificationSent($notifiable, $notification, $this->channel, null));
    }

    public function failed(\Throwable $e): void
    {
        report($e);
    }
}
