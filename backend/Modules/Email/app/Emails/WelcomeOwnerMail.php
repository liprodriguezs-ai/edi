<?php

namespace Modules\Email\Emails;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * WelcomeOwnerMail — sent to the FIRST user of a organization (the account owner) when
 * their account is provisioned. System email: always delivered via SystemMailer.
 *
 * Renders the compiled `email::welcome-owner` Blade view (source:
 * frontend/emails/welcome-owner.tsx), which interpolates $user->name, $url and
 * $emailBaseUrl directly (no @if), so all three are always provided non-null.
 */
class WelcomeOwnerMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user
    ) {}

    public function build(): self
    {
        $emailBaseUrl = config('email.assets_url')
            ?? rtrim(config('app.url'), '/').'/email';

        return $this->subject('Bienvenido a EDI')
            ->view('email::welcome-owner')
            ->with([
                'user' => $this->user,
                'url' => config('app.frontend_url').'/dashboard',
                'emailBaseUrl' => $emailBaseUrl,
            ]);
    }
}
