<?php

namespace App\Notifications\Channels;

use App\Notifications\Contracts\NotificationChannelInterface;
use Illuminate\Support\Facades\Log;

/**
 * WhatsAppChannel — placeholder for a future WhatsApp delivery driver.
 *
 * No WhatsApp API provider is configured yet (Twilio / Meta Business API).
 * Until one is wired up, this channel logs a single info-level message and
 * returns without sending anything. Selecting WhatsApp in the organization's
 * Notifications configuration will not break anything — it simply has no
 * effect until a provider is connected.
 */
class WhatsAppChannel implements NotificationChannelInterface
{
    public function send(array $recipients, string $title, string $body, array $data = []): void
    {
        Log::info('[NotificationChannel:whatsapp] no provider configured — skipping', [
            'recipient_count' => count($recipients),
            'title' => $title,
        ]);
    }
}
