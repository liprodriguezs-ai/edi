<?php

namespace App\Notifications\Contracts;

interface NotificationChannelInterface
{
    /**
     * Send a notification to one or more recipients.
     *
     * @param  array<string|int, mixed>  $recipients  Identifiers (user ids, emails, phone numbers, FCM tokens, etc.)
     * @param  array<string, mixed>  $data  Additional payload (click_action, entity_id, …)
     */
    public function send(array $recipients, string $title, string $body, array $data = []): void;
}
