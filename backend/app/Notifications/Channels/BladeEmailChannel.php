<?php

namespace App\Notifications\Channels;

use App\Notifications\Contracts\NotificationChannelInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * BladeEmailChannel — sends notification emails rendered from a Blade view.
 *
 * When $data['email_template'] (an event key such as 'payment_accepted' or a
 * dotted key such as 'amenity_reservation.created') is present, the channel
 * renders view('email::{slug-with-hyphens}', $context) where $context =
 * $data['email_context'] merged with $emailBaseUrl. The slug flattens BOTH
 * underscores and dots to hyphens because the compiled Blade files follow the
 * React Email naming convention and are always FLAT (e.g.
 * payment-accepted.blade.php, amenity-reservation-created.blade.php — never a
 * nested amenity-reservation/created.blade.php).
 *
 * Falls back to nl2br-escaped plain text when no template is specified,
 * preserving backward-compatibility with plain-text callers.
 *
 * Skips silently when no recipients have a valid email address. Delivery errors
 * PROPAGATE (this runs inside DeliverChannelMessageJob, which retries on failure);
 * they are no longer swallowed. The template-missing → plain-text fallback is not
 * an error and is preserved.
 */
class BladeEmailChannel implements NotificationChannelInterface
{
    public function send(array $recipients, string $title, string $body, array $data = []): void
    {
        $toAddresses = array_values(array_filter(
            $recipients,
            static fn ($r): bool => is_string($r) && str_contains($r, '@'),
        ));

        if ($toAddresses === []) {
            return;
        }

        // Errors PROPAGATE on purpose: this runs inside DeliverChannelMessageJob, so a
        // send failure must surface for the job to retry ($tries) — not be swallowed.
        // (The template-missing → plain-text fallback in renderContent is NOT an error.)
        $html = $this->renderContent($body, $data);

        $fromAddress = config('mail.from.address', 'noreply@kivo7.com');
        $fromName = config('mail.from.name', 'Kivo7');

        Mail::html($html, static function ($message) use ($toAddresses, $title, $fromAddress, $fromName): void {
            $message->to($toAddresses)
                ->subject($title)
                ->from($fromAddress, $fromName);
        });
    }

    /**
     * Render the email body as HTML.
     *
     * Converts the underscore- and dot-keyed event slug to a FLAT hyphenated
     * Blade view name: 'payment_accepted' → 'email::payment-accepted';
     * 'amenity_reservation.created' → 'email::amenity-reservation-created'
     * (the dot is flattened too, so Laravel's view resolver never treats it
     * as a directory separator). Falls back to nl2br-escaped plain text when
     * the view does not exist or no template is specified.
     *
     * @param  array<string, mixed>  $data
     */
    private function renderContent(string $body, array $data): string
    {
        $templateSlug = isset($data['email_template']) ? (string) $data['email_template'] : null;

        if ($templateSlug !== null && $templateSlug !== '') {
            // 'payment_accepted' → 'email::payment-accepted'
            // 'amenity_reservation.created' → 'email::amenity-reservation-created'
            $viewName = 'email::'.str_replace(['_', '.'], '-', $templateSlug);

            if (view()->exists($viewName)) {
                $context = array_merge(
                    (array) ($data['email_context'] ?? []),
                    ['emailBaseUrl' => $this->emailBaseUrl()],
                );

                return view($viewName, $context)->render();
            }

            Log::warning('[NotificationChannel:blade_email] Template view not found — falling back to plain text', [
                'view' => $viewName,
            ]);
        }

        // Plain-text fallback: preserve line breaks and escape HTML entities.
        return '<p>'.nl2br(e($body)).'</p>';
    }

    /**
     * Resolve the base URL for email static assets (logo, images).
     * Mirrors the pattern used by ResetPasswordMail.
     */
    private function emailBaseUrl(): string
    {
        return config('email.assets_url') ?: rtrim((string) config('app.url'), '/').'/email';
    }
}
