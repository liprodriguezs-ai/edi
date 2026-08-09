<?php

namespace Modules\Tracking\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Tracking\DTO\TrackingEvent;
use Modules\Tracking\Models\TrackingEvent as TrackingEventLog;
use Modules\Tracking\Services\TrackingDispatcher;
use Throwable;

/**
 * Off-request delivery of ONE tracking event through ONE channel. Copies the
 * shape of Modules\Notification\Jobs\DeliverChannelMessageJob: a dumb sender
 * that resolves the channel from the container and calls send() — no
 * tenant-scoped queries, safe to run on a worker.
 *
 * One job per (channel × event) so a Facebook outage never blocks GA4, and a
 * retry of one channel never re-sends to a channel that already succeeded.
 *
 * Also writes the tracking_event audit row (queued → sent|failed) so
 * conversions can be debugged: what was sent, what the channel replied.
 */
class DeliverTrackingEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $channel,
        public TrackingEvent $event,
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

    public function handle(TrackingDispatcher $dispatcher): void
    {
        $this->recordOutcome(TrackingEventLog::STATUS_QUEUED);

        try {
            $dispatcher->makeChannel($this->channel)->send($this->event);
            $this->recordOutcome(TrackingEventLog::STATUS_SENT);
        } catch (Throwable $e) {
            $this->recordOutcome(TrackingEventLog::STATUS_FAILED, mb_substr($e->getMessage(), 0, 1000));

            throw $e;
        }
    }

    /**
     * Called when retries are EXHAUSTED (all $tries failed) — but ALSO the only
     * place we hear about a worker dying mid-handle() (Octane timeout/OOM, where
     * the catch in handle() never runs). Without this, the audit row would stay
     * "queued" forever even though delivery permanently failed.
     */
    public function failed(Throwable $e): void
    {
        report($e);
        $this->recordOutcome(TrackingEventLog::STATUS_FAILED, mb_substr($e->getMessage(), 0, 1000));
    }

    /**
     * Upsert the audit row keyed by (event_id, channel) — retries update the
     * SAME row instead of creating duplicates. user_data is stored HASHED
     * (never raw PII) via hashedUserData().
     */
    private function recordOutcome(string $status, ?string $responseSnippet = null): void
    {
        TrackingEventLog::updateOrCreate(
            ['event_id' => $this->event->eventId, 'channel' => $this->channel],
            [
                'event_name' => $this->event->name,
                'status' => $status,
                'request_payload' => [
                    'event_time' => $this->event->eventTime,
                    'action_source' => $this->event->actionSource,
                    'source_url' => $this->event->sourceUrl,
                    'params' => $this->event->params,
                    'user_data' => $this->event->hashedUserData(),
                ],
                'response_snippet' => $responseSnippet,
            ],
        );
    }
}
