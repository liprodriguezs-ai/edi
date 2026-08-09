<?php

namespace App\Notifications\Channels;

use App\Notifications\Contracts\NotificationChannelInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SmsChannel — generic Twilio-ready SMS dispatcher.
 *
 * Reads credentials from:
 *   config('services.sms.sid')    — Twilio Account SID
 *   config('services.sms.token')  — Twilio Auth Token
 *   config('services.sms.from')   — Sender phone number
 *
 * Recipients are treated as E.164 phone numbers.
 * No-ops with a warning when credentials are absent.
 */
class SmsChannel implements NotificationChannelInterface
{
    public function send(array $recipients, string $title, string $body, array $data = []): void
    {
        $sid = config('services.sms.sid');
        $token = config('services.sms.token');
        $from = config('services.sms.from');

        if (! $sid || ! $token || ! $from) {
            Log::warning('[NotificationChannel:sms] Credentials not configured — skipping SMS dispatch.');

            return;
        }

        $message = $title."\n".$body;

        // Errors PROPAGATE on purpose: this runs inside DeliverChannelMessageJob (one
        // job per recipient), so a Twilio failure must surface for the job to retry.
        // ->throw() turns a 4xx/5xx Twilio response into an exception (Http does not
        // throw by default). Per-recipient jobs mean a retry never re-sends to numbers
        // that already succeeded.
        foreach ($recipients as $phone) {
            Http::withBasicAuth($sid, $token)
                ->asForm()
                ->throw()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'From' => $from,
                    'To' => (string) $phone,
                    'Body' => mb_substr($message, 0, 1600),
                ]);
        }
    }
}
