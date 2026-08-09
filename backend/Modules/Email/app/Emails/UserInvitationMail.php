<?php

namespace Modules\Email\Emails;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class UserInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $acceptUrl
    ) {}

    public function build(): self
    {
        $emailBaseUrl = config('email.assets_url')
            ?? rtrim(config('app.url'), '/').'/email';

        return $this->subject('Completa tu cuenta')
            ->view('email::invitation')
            ->with([
                'acceptUrl' => $this->acceptUrl,
                'emailBaseUrl' => $emailBaseUrl,
            ]);
    }
}
