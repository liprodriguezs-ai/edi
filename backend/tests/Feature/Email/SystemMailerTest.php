<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Collaborator\Models\Collaborator;
use Modules\Email\Emails\OwnerTransferredMail;
use Modules\Email\Emails\UserInvitationMail;
use Modules\Email\Emails\WelcomeOwnerMail;
use Modules\Email\Services\AccountOwnerNotificationService;
use Modules\Email\Services\SystemMailer;
use Modules\Notification\Models\NotificationSetting;
use Modules\Tenant\Models\Tenant;
use Modules\User\Emails\ResetPasswordMail;
use Modules\User\Models\UserInvitation;
use Modules\User\Services\UserInvitationService;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Cambio 5 — unified email path + the SYSTEM-email guard.
 *
 * System emails (invitations, password resets, ownership welcome/transfer) are
 * transactional/legal/security messages that MUST always be delivered. They go
 * through SystemMailer, which uses the SAME canonical Laravel Mail transport +
 * the SAME compiled `email::` Blade templates as domain notifications, but
 * DELIBERATELY bypasses the notification engine (NotificationSetting per tenant,
 * NotificationPreference per user, quiet hours). A toggle must never silence a
 * security/legal email.
 *
 * The system mailables implement ShouldQueue (async + retry; keeps the Resend
 * call off the request path), so SystemMailer::send() QUEUES them. Under
 * Mail::fake() a ShouldQueue mailable is recorded as queued — assertions use
 * Mail::assertQueued, not assertSent.
 *
 * Domain-email preference/setting gating is covered separately by
 * NotificationPreferenceTest / NotificationSettingServiceTest — those must keep
 * passing (this change does not touch that path).
 */
final class SystemMailerTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    public function test_system_mailer_sends_mailable_through_the_mail_facade(): void
    {
        Mail::fake();

        app(SystemMailer::class)->send('vecino@example.com', new UserInvitationMail('https://app.test/accept/tok'));

        Mail::assertQueued(
            UserInvitationMail::class,
            static fn (UserInvitationMail $mail): bool => $mail->hasTo('vecino@example.com'),
        );
    }

    public function test_invitation_email_is_sent_even_when_tenant_notifications_are_disabled(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant('Guarded Cluster');
        $this->seedTenant($tenant);

        // Disable EVERY per-tenant notification setting and strip the dispatcher's
        // configured channels: if the invitation still arrives, it proves the
        // system-email path does not route through the gated notification engine.
        $this->inTenant($tenant, static function (): void {
            NotificationSetting::query()->update([
                'is_active' => false,
                'channel_email' => false,
                'channel_push' => false,
            ]);
        });
        config(['notifications.channels' => []]);

        $invitation = new UserInvitation([
            'token' => null,
            'token_hash' => hash('sha256', 'plain-token'),
            'tenant_id' => $tenant->id,
            'inviteable_type' => Collaborator::class,
            'inviteable_id' => (string) Str::uuid(),
            'email' => 'invitee@example.com',
            'expires_at' => now()->addDay(),
        ]);
        $invitation->save();

        app(UserInvitationService::class)->sendInvitationEmail($invitation, 'plain-token');

        Mail::assertQueued(
            UserInvitationMail::class,
            static fn (UserInvitationMail $mail): bool => $mail->hasTo('invitee@example.com'),
        );
    }

    public function test_password_reset_email_is_sent_even_when_user_email_preference_is_off(): void
    {
        Mail::fake();

        $user = User::create([
            'name' => 'Reset Me',
            'email' => 'reset@example.com',
            'password' => bcrypt('secret1234'),
            'auth_active' => true,
        ]);

        // The user disabled email for every category — a domain notification would
        // be suppressed, but a security email (password reset) must NOT be.
        NotificationPreference::create([
            'user_id' => $user->id,
            'channels' => ['default' => ['email' => false, 'push' => false, 'sms' => false]],
        ]);

        $user->sendPasswordResetNotification('reset-token');

        Mail::assertQueued(
            ResetPasswordMail::class,
            static fn (ResetPasswordMail $mail): bool => $mail->hasTo('reset@example.com'),
        );
    }

    public function test_account_owner_welcome_email_is_sent_via_system_mailer(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant('Owner Cluster');
        $user = $this->makeUser($tenant);

        app(AccountOwnerNotificationService::class)->notify($user);

        Mail::assertQueued(
            WelcomeOwnerMail::class,
            static fn (WelcomeOwnerMail $mail): bool => $mail->hasTo($user->email),
        );
        Mail::assertNotQueued(OwnerTransferredMail::class);
    }

    public function test_system_mailer_skips_and_does_not_throw_when_recipient_is_empty(): void
    {
        // "Everything is User": $user->email is nullable (passwordless / email-less
        // people). The choke point must skip a null / blank / recipient-less array
        // without a TypeError and without a pointless empty send.
        Mail::fake();

        $mailer = app(SystemMailer::class);
        $mailer->send(null, new UserInvitationMail('https://app.test/accept/tok'));
        $mailer->send('', new UserInvitationMail('https://app.test/accept/tok'));
        $mailer->send(['', null], new UserInvitationMail('https://app.test/accept/tok'));

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }
}
