<?php

namespace App\Notifications\Channels;

use App\Notifications\Contracts\NotificationChannelInterface;
use Illuminate\Support\Facades\Log;
use Resend\Laravel\Facades\Resend;

/**
 * ResendEmailChannel — sends email notifications via resend/resend-laravel.
 *
 * Credentials resolved from config('services.resend.key').
 * Recipients are treated as email addresses.
 * No-ops with a warning when credentials are absent.
 */
class ResendEmailChannel implements NotificationChannelInterface
{
    public function send(array $recipients, string $title, string $body, array $data = []): void
    {
        $apiKey = config('services.resend.key');

        if (! $apiKey) {
            Log::warning('[NotificationChannel:resend_email] API key not configured — skipping email dispatch.');

            return;
        }

        $toAddresses = array_values(array_filter($recipients, fn ($r) => is_string($r) && str_contains($r, '@')));

        if ($toAddresses === []) {
            return;
        }

        $fromAddress = config('mail.from.address', 'noreply@kivo7.com');
        $fromName = config('mail.from.name', 'Kivo7');

        // Errors PROPAGATE on purpose: this runs inside DeliverChannelMessageJob, so a
        // send failure must surface for the job to retry ($tries) — not be swallowed.
        Resend::emails()->send([
            'from' => "{$fromName} <{$fromAddress}>",
            'to' => $toAddresses,
            'subject' => $title,
            'html' => nl2br(htmlspecialchars($body)),
        ]);
    }
}
