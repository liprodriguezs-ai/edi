<?php

namespace Modules\Tracking\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Tracking\Contracts\TrackingChannelInterface;
use Modules\Tracking\DTO\TrackingEvent;

/**
 * FacebookConversionsChannel — sends events to the Meta Conversions API.
 *
 * Reads credentials from:
 *   config('services.facebook.pixel_id')          — Meta Pixel id
 *   config('services.facebook.capi_access_token')  — Conversions API access token
 *   config('services.facebook.test_event_code')    — optional, shows up in Test Events
 *
 * The SAME event_id sent by the browser Pixel (fbq) is sent here so Meta
 * deduplicates the browser + server hit into a single event.
 *
 * No-ops with a warning when credentials are absent (convention shared by every
 * channel in this project, e.g. App\Notifications\Channels\SmsChannel).
 */
class FacebookConversionsChannel implements TrackingChannelInterface
{
    public function send(TrackingEvent $event): void
    {
        $pixelId = config('services.facebook.pixel_id');
        $accessToken = config('services.facebook.capi_access_token');

        if (! $pixelId || ! $accessToken) {
            Log::warning('[TrackingChannel:facebook] Credentials not configured — skipping Conversions API dispatch.');

            return;
        }

        $apiVersion = config('tracking.api_version', 'v20.0');
        $testEventCode = config('services.facebook.test_event_code');

        $payload = [
            'data' => [[
                'event_name' => $event->name,
                'event_time' => $event->eventTime,
                'event_id' => $event->eventId,
                'action_source' => $event->actionSource,
                'event_source_url' => $event->sourceUrl,
                'user_data' => $this->buildUserData($event),
                'custom_data' => $event->params,
            ]],
        ];

        if ($testEventCode) {
            $payload['test_event_code'] = $testEventCode;
        }

        // access_token travels in the JSON body (Meta's Graph API accepts it there
        // for POST requests), never in the URL query string — outgoing request URIs
        // get persisted verbatim by Telescope's ClientRequestWatcher and by proxy/APM
        // access logs, which would otherwise leak the live token.
        $url = "https://graph.facebook.com/{$apiVersion}/{$pixelId}/events";
        $body = $payload;
        $body['access_token'] = (string) $accessToken;

        // Errors PROPAGATE on purpose: this runs inside DeliverTrackingEventJob,
        // so a Graph API failure must surface for the job to retry.
        Http::asJson()->throw()->post($url, $body);
    }

    /**
     * Facebook expects em/ph/external_id as ARRAYS of hashed values (Advanced
     * Matching), and only forwards the identifiers it understands — gclid and
     * client_id (GA4-only) are dropped here.
     *
     * @return array<string, mixed>
     */
    private function buildUserData(TrackingEvent $event): array
    {
        $hashed = $event->hashedUserData();

        $userData = [];

        foreach (['email' => 'em', 'phone' => 'ph', 'external_id' => 'external_id'] as $source => $target) {
            if (! empty($hashed[$source])) {
                $userData[$target] = [$hashed[$source]];
            }
        }

        foreach (['fbp', 'fbc'] as $key) {
            if (! empty($hashed[$key])) {
                $userData[$key] = $hashed[$key];
            }
        }

        if (! empty($hashed['client_ip'])) {
            $userData['client_ip_address'] = $hashed['client_ip'];
        }

        if (! empty($hashed['client_user_agent'])) {
            $userData['client_user_agent'] = $hashed['client_user_agent'];
        }

        return $userData;
    }
}
