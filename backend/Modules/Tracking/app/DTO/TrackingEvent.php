<?php

namespace Modules\Tracking\DTO;

use Illuminate\Support\Str;

/**
 * TrackingEvent — normalized value object for a single marketing conversion
 * event, shared by every channel (Facebook Conversions API, GA4 Measurement
 * Protocol, log). This is the wire contract between `POST /api/track`, the
 * server-side hooks (Stripe webhook, registration), and TrackingDispatcher.
 *
 * Canonical event names (Facebook-style; GA4_EVENT_NAME_MAP in
 * GoogleAnalyticsChannel maps them to their GA4 equivalent):
 *   Lead, StartTrial, CompleteRegistration, InitiateCheckout, Purchase.
 *
 * `userData` carries PII + tracking identifiers exactly as received: email,
 * phone, external_id (hashed on demand via hashedUserData()), fbp/fbc (Meta
 * click/browser ids), gclid (Google click id), client_id (GA4 cookie id,
 * internal-only — never accepted from the public /api/track body), client_ip
 * and client_user_agent (server-enriched, never sent by the client).
 */
final class TrackingEvent
{
    /**
     * The only event names this product tracks. Kept here (not per-channel) so
     * validation (TrackEventRequest) and every channel share one source of truth.
     *
     * @var array<int, string>
     */
    public const CANONICAL_EVENTS = [
        'Lead',
        'StartTrial',
        'CompleteRegistration',
        'InitiateCheckout',
        'Purchase',
    ];

    /**
     * PII keys in `userData` that MUST be SHA-256-hashed before leaving the
     * server (Facebook CAPI requirement). fbp/fbc/gclid/client_id/client_ip/
     * client_user_agent are NOT hashed — Meta and Google expect them raw.
     *
     * @var array<int, string>
     */
    private const HASHED_USER_DATA_KEYS = ['email', 'phone', 'external_id'];

    /**
     * @param  array<string, mixed>  $params  value, currency, content_ids, content_name, transaction_id, …
     * @param  array<string, mixed>  $userData  email, phone, external_id, fbp, fbc, gclid, client_id, client_ip, client_user_agent
     */
    public function __construct(
        public readonly string $name,
        public readonly string $eventId,
        public readonly int $eventTime,
        public readonly array $params = [],
        public readonly array $userData = [],
        public readonly ?string $sourceUrl = null,
        public readonly string $actionSource = 'website',
    ) {}

    /**
     * Build from the normalized array shape shared by the request payload and
     * every internal call site (Stripe webhook, AuthController::register).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            eventId: (string) ($data['event_id'] ?? (string) Str::uuid()),
            eventTime: (int) ($data['event_time'] ?? now()->timestamp),
            params: is_array($data['params'] ?? null) ? $data['params'] : [],
            userData: is_array($data['user_data'] ?? null) ? $data['user_data'] : [],
            sourceUrl: $data['source_url'] ?? null,
            actionSource: (string) ($data['action_source'] ?? 'website'),
        );
    }

    /**
     * SHA-256 hash of a normalized (lowercased + trimmed) PII value, per the
     * Facebook Conversions API "advanced matching" spec.
     */
    public static function hashPii(string $value): string
    {
        return hash('sha256', mb_strtolower(trim($value)));
    }

    /**
     * `userData` with email/phone/external_id SHA-256-hashed and empty values
     * dropped. fbp/fbc/gclid/client_id/client_ip/client_user_agent pass through
     * unhashed. This is what FacebookConversionsChannel sends — never the raw
     * userData array.
     *
     * @return array<string, string>
     */
    public function hashedUserData(): array
    {
        $hashed = $this->userData;

        foreach (self::HASHED_USER_DATA_KEYS as $key) {
            if (! empty($hashed[$key]) && is_string($hashed[$key])) {
                $hashed[$key] = self::hashPii($hashed[$key]);
            } else {
                unset($hashed[$key]);
            }
        }

        return array_filter($hashed, static fn ($value): bool => $value !== null && $value !== '');
    }
}
