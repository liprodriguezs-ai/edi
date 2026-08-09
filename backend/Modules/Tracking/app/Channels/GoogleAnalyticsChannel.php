<?php

namespace Modules\Tracking\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Tracking\Contracts\TrackingChannelInterface;
use Modules\Tracking\DTO\TrackingEvent;

/**
 * GoogleAnalyticsChannel — sends events to GA4 via the Measurement Protocol.
 *
 * Reads credentials from:
 *   config('services.google_analytics.measurement_id')
 *   config('services.google_analytics.api_secret')
 *
 * Used ONLY for server-only conversions (Stripe webhook Purchase, registration
 * fallback) — events originated in the browser are sent to GA4 by gtag
 * client-side; POST /api/track never dispatches to this channel, so GA4 is
 * never double-counted (MP does not deduplicate against gtag).
 *
 * No-ops with a warning when credentials are absent, mirroring every other
 * channel in this project.
 */
class GoogleAnalyticsChannel implements TrackingChannelInterface
{
    /**
     * Facebook-style event name → its GA4 equivalent. Lives here (not on the
     * DTO) because it is GA4-specific wire vocabulary.
     *
     * @var array<string, string>
     */
    private const GA4_EVENT_NAME_MAP = [
        'Lead' => 'generate_lead',
        'StartTrial' => 'start_trial',
        'CompleteRegistration' => 'sign_up',
        'InitiateCheckout' => 'begin_checkout',
        'Purchase' => 'purchase',
    ];

    public function send(TrackingEvent $event): void
    {
        $measurementId = config('services.google_analytics.measurement_id');
        $apiSecret = config('services.google_analytics.api_secret');

        if (! $measurementId || ! $apiSecret) {
            Log::warning('[TrackingChannel:google_analytics] Credentials not configured — skipping GA4 Measurement Protocol dispatch.');

            return;
        }

        // client_id is the GA4 cookie id (_ga). It is internal-only: server call
        // sites (Stripe webhook, AuthController::register) thread it through
        // user_data.client_id when they have it; otherwise a random id is used
        // so the hit is still counted (it just won't stitch to a browser session).
        $clientId = $event->userData['client_id'] ?? (string) Str::uuid();

        $params = $event->params;
        unset($params['client_id']);

        // transaction_id doubles as the event_id so Purchase hits can be traced
        // back to their originating request even without gtag deduplication.
        $params['transaction_id'] ??= $event->eventId;

        $payload = [
            'client_id' => $clientId,
            'events' => [[
                'name' => self::GA4_EVENT_NAME_MAP[$event->name] ?? $event->name,
                'params' => $params,
            ]],
        ];

        $url = 'https://www.google-analytics.com/mp/collect?'.http_build_query([
            'measurement_id' => $measurementId,
            'api_secret' => $apiSecret,
        ]);

        // Errors PROPAGATE on purpose: this runs inside DeliverTrackingEventJob,
        // so a Measurement Protocol failure must surface for the job to retry.
        Http::asJson()->throw()->post($url, $payload);
    }
}
