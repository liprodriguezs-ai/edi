<?php

namespace Modules\Email\Services;

use App\Models\User;
use Modules\Email\Emails\OwnerTransferredMail;
use Modules\Email\Emails\WelcomeOwnerMail;
use Modules\Tenant\Models\Tenant;

class AccountOwnerNotificationService
{
    public function __construct(
        protected SystemMailer $systemMailer
    ) {}

    public function notify(User $user): void
    {
        $tenant = $user->tenants()->first();

        if (! $tenant instanceof Tenant) {
            return;
        }

        $isFirstUser = $tenant->users()->count() === 1;

        // Welcome / ownership-transfer are system emails: routed through SystemMailer
        // (canonical Mail transport + email:: Blade templates), never the gated
        // notification engine.
        $mailable = $isFirstUser
            ? new WelcomeOwnerMail($user)
            : new OwnerTransferredMail($user);

        $this->systemMailer->send($user->email, $mailable);
    }
}
