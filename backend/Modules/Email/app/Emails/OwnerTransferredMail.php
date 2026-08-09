<?php

namespace Modules\Email\Emails;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * OwnerTransferredMail — sent when an additional owner is added to a cluster that
 * already has users (ownership transfer / co-owner). System email: always
 * delivered via SystemMailer.
 *
 * Renders the compiled `email::owner-transferred` Blade view (source:
 * frontend/emails/owner-transferred.tsx), which interpolates $user->name, $url
 * and $emailBaseUrl directly (no @if), so all three are always provided non-null.
 */
class OwnerTransferredMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user
    ) {}

    public function build(): self
    {
        $emailBaseUrl = config('email.assets_url')
            ?? rtrim(config('app.url'), '/').'/email';

        return $this->subject('Eres titular de una cuenta en Kivo7')
            ->view('email::owner-transferred')
            ->with([
                'user' => $this->user,
                'url' => config('app.frontend_url').'/dashboard',
                'emailBaseUrl' => $emailBaseUrl,
            ]);
    }
}
