<?php

namespace App\Notifications\Channels;

use App\Notifications\Contracts\NotificationChannelInterface;
use Illuminate\Support\Facades\Log;

/**
 * LogChannel — default driver used in dev and test environments.
 *
 * Writes all dispatch calls to the application log so callers can verify
 * behaviour without making real network calls.
 */
class LogChannel implements NotificationChannelInterface
{
    public function send(array $recipients, string $title, string $body, array $data = []): void
    {
        Log::info('[NotificationChannel:log] Notification dispatched', [
            'recipients' => $recipients,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);
    }
}
