<?php

namespace Modules\User\Emails;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $url;

    public $user;

    /**
     * Create a new message instance.
     */
    public function __construct($url, $user)
    {
        $this->url = $url;
        $this->user = $user;
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        $expireMinutes = (int) config('auth.passwords.users.expire', 60);
        $assetsUrl = config('email.assets_url')
            ?? rtrim(config('app.url'), '/').'/email';

        return $this->view('email::reset-password')->with([
            'url' => $this->url,
            'count' => $expireMinutes,
            'emailBaseUrl' => $assetsUrl,
        ]);
    }
}
