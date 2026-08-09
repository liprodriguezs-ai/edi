<?php

namespace App\Notifications\Channels;

use App\Notifications\Contracts\NotificationChannelInterface;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;

/**
 * FirebasePushChannel — sends FCM push via kreait/firebase-php.
 *
 * Delivery is SYNCHRONOUS here on purpose: the dispatcher already queues this
 * channel through DeliverChannelMessageJob (one job per recipient, off-request),
 * so wrapping it in a second job would double-enqueue. The actual FCM call runs
 * on the worker.
 *
 * No-ops (logs a warning) when credentials are absent so tests/dev can safely
 * wire this driver. Transport errors PROPAGATE so the job's $tries can retry;
 * per-token failures (e.g. an unregistered token) are only logged — retrying
 * those is pointless and would re-push to the tokens that already succeeded.
 */
class FirebasePushChannel implements NotificationChannelInterface
{
    public function send(array $recipients, string $title, string $body, array $data = []): void
    {
        $tokens = collect($recipients)
            ->filter(static fn ($token): bool => ! empty($token))
            ->map(static fn ($token): string => trim((string) $token))
            ->unique()
            ->values()
            ->all();

        if ($tokens === []) {
            return;
        }

        $credentials = $this->resolveCredentials();
        if ($credentials === null) {
            Log::warning('[NotificationChannel:firebase_push] Credentials not configured — skipping push dispatch.');

            return;
        }

        $messaging = (new Factory)->withServiceAccount($credentials)->createMessaging();

        $message = CloudMessage::fromArray([
            'notification' => [
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
            ],
            'data' => array_merge(
                $this->stringifyData($data),
                ['click_action' => (string) ($data['click_action'] ?? '/')],
            ),
        ]);

        $result = $messaging->sendMulticast($message, $tokens);

        if ($result->hasFailures()) {
            // Per-token failures are permanent (invalid/unregistered token) — log, don't
            // throw: a retry would re-push to the tokens that already succeeded.
            Log::warning('FCM push failures', [
                'successes' => $result->successes()->count(),
                'failures' => $result->failures()->count(),
            ]);
        }
    }

    /**
     * Flatten dispatch metadata into FCM's required string→string `data` map.
     *
     * FCM only accepts string values, and casting blindly explodes: $data can carry
     * NESTED ARRAYS (e.g. 'email_context', the email template variables) that are
     * irrelevant to push. We keep only scalar/null values (stringified) and drop
     * arrays/objects. This guard is load-bearing, not cosmetic: in production a stray
     * `(string) $array` is promoted to a fatal ErrorException ("Array to string
     * conversion"), which fails the delivery job.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function stringifyData(array $data): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $flat[(string) $key] = (string) $value;
            }
        }

        return $flat;
    }

    /**
     * Resolve the Firebase service account. Prefers the JSON passed via env
     * (FIREBASE_CREDENTIALS_JSON — raw OR base64, for managed hosting like Laravel
     * Cloud where shipping a secret file isn't practical), and falls back to a
     * filesystem path (FIREBASE_CREDENTIALS, for local/dev).
     *
     * @return array<string, mixed>|string|null array of credentials, a path, or null when unconfigured
     */
    private function resolveCredentials(): array|string|null
    {
        $json = config('services.firebase.credentials_json');
        if (is_string($json) && trim($json) !== '') {
            $decoded = json_decode($json, true);
            if (! is_array($decoded)) {
                // Allow a base64-encoded JSON value (safer to paste into env editors).
                $decoded = json_decode((string) base64_decode($json, true), true);
            }
            if (is_array($decoded)) {
                return $decoded;
            }
            Log::warning('[NotificationChannel:firebase_push] FIREBASE_CREDENTIALS_JSON is set but is not valid JSON nor base64-encoded JSON.');
        }

        $path = config('services.firebase.credentials');
        if (is_string($path) && $path !== '' && is_file($path)) {
            return $path;
        }

        return null;
    }
}
