<?php

namespace Modules\Email\Services;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SystemMailer — the single canonical path for SYSTEM email.
 *
 * System emails are transactional / legal / security messages — account
 * invitations, password resets, ownership welcome & transfer — that MUST always
 * be delivered. Unlike domain notifications, they DELIBERATELY bypass the
 * notification engine (NotificationDispatcher, per-tenant NotificationSetting,
 * per-user NotificationPreference and quiet hours): a cluster toggle or a user
 * preference must NEVER be able to suppress a security or legal email.
 *
 * Transport is the application's default Laravel mailer
 * (config('mail.default') — the Resend driver in production) and the templates
 * are the SAME compiled React Email Blade views (namespace email::) used by
 * domain notifications. So there is exactly ONE email transport and ONE template
 * source across the whole application; only the gating differs.
 *
 * Domain (notifiable) emails keep going through DispatchesNotifications::notifyEvent()
 * → NotificationDispatcher → BladeEmailChannel, where preferences and per-tenant
 * settings still apply. Use this service ONLY for must-deliver system mail.
 */
class SystemMailer
{
    /**
     * Send a system Mailable to one or more recipients via the canonical transport.
     *
     * The recipient is accepted as nullable on purpose: callers pass values like
     * $user->email, which is NULLABLE in this domain ("Everything is User" —
     * passwordless / email-less people exist). Guarding here, at the single choke
     * point, protects EVERY system-email path from a TypeError and from a pointless
     * empty send: a null / blank / recipient-less array is skipped (logged), never
     * thrown.
     *
     * @param  string|array<int, string|null>|null  $to
     */
    public function send(string|array|null $to, Mailable $mailable): void
    {
        $recipients = array_values(array_filter(
            is_array($to) ? $to : [$to],
            static fn ($address): bool => is_string($address) && trim($address) !== '',
        ));

        if ($recipients === []) {
            Log::warning('SystemMailer: skipping email, recipient empty', [
                'mailable' => $mailable::class,
            ]);

            return;
        }

        Mail::to($recipients)->send($mailable);
    }
}
