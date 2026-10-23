<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\Channels\FirebasePushChannel;
use App\Notifications\Channels\LogChannel;
use App\Notifications\Channels\ResendEmailChannel;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Concerns\DispatchesNotifications;
use App\Notifications\Contracts\NotificationChannelInterface;
use App\Notifications\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Address resolution per channel (§17.1) — the dispatcher receives USER IDS and
 * must hand each delivery channel the user's REAL address: email for
 * 'resend_email', phone for 'sms', token_sender (FCM device token) for
 * 'firebase_push'. The 'log' channel keeps user ids (in-app/dev record).
 *
 * Regression for notifications-01 (channels used to receive raw UUIDs, so email
 * was silently dropped and SMS/push hit the providers with invalid targets) and
 * notifications-02 (no emitter could ever reach real push).
 */
final class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeUser(array $overrides = []): User
    {
        return User::withoutGlobalScopes()->create(array_merge([
            'id' => (string) Str::uuid7(),
            'name' => 'Delivery User '.Str::random(4),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
            'phone' => '+5215512'.mt_rand(100000, 999999),
            'token_sender' => 'fcm-'.Str::random(20),
        ], $overrides));
    }

    /**
     * Bind a spy for the given channel class that records the recipients it got.
     *
     * @return object{recipients: array<int, mixed>}
     */
    private function spyChannel(string $channelClass): object
    {
        $spy = new class implements NotificationChannelInterface
        {
            /** @var array<int, mixed> */
            public array $recipients = [];

            public function send(array $recipients, string $title, string $body, array $data = []): void
            {
                $this->recipients = array_merge($this->recipients, $recipients);
            }
        };

        $this->app->instance($channelClass, $spy);

        return $spy;
    }

    public function test_email_channel_receives_email_addresses_not_user_ids(): void
    {
        $spy = $this->spyChannel(ResendEmailChannel::class);
        $a = $this->makeUser();
        $b = $this->makeUser();

        app(NotificationDispatcher::class)->dispatch(
            [$a->id, $b->id], 'Título', 'Cuerpo', [], ['resend_email'],
        );

        $this->assertEqualsCanonicalizing([$a->email, $b->email], $spy->recipients);
        $this->assertNotContains($a->id, $spy->recipients, 'Email channel must never receive a user UUID');
    }

    public function test_sms_channel_receives_phone_numbers_not_user_ids(): void
    {
        $spy = $this->spyChannel(SmsChannel::class);
        $user = $this->makeUser();

        app(NotificationDispatcher::class)->dispatch(
            [$user->id], 'Título', 'Cuerpo', [], ['sms'],
        );

        $this->assertSame([$user->phone], $spy->recipients);
    }

    public function test_push_channel_receives_device_tokens_not_user_ids(): void
    {
        $spy = $this->spyChannel(FirebasePushChannel::class);
        $user = $this->makeUser();

        app(NotificationDispatcher::class)->dispatch(
            [$user->id], 'Título', 'Cuerpo', [], ['firebase_push'],
        );

        $this->assertSame([$user->token_sender], $spy->recipients);
    }

    public function test_users_without_the_channel_address_are_skipped(): void
    {
        $emailSpy = $this->spyChannel(ResendEmailChannel::class);
        $pushSpy = $this->spyChannel(FirebasePushChannel::class);

        $complete = $this->makeUser();
        $addressless = $this->makeUser(['email' => null, 'token_sender' => null]);

        app(NotificationDispatcher::class)->dispatch(
            [$complete->id, $addressless->id], 'Título', 'Cuerpo', [], ['resend_email', 'firebase_push'],
        );

        $this->assertSame([$complete->email], $emailSpy->recipients);
        $this->assertSame([$complete->token_sender], $pushSpy->recipients);
    }

    public function test_channel_with_no_resolvable_recipient_is_not_invoked(): void
    {
        $spy = $this->spyChannel(ResendEmailChannel::class);
        $addressless = $this->makeUser(['email' => null]);

        app(NotificationDispatcher::class)->dispatch(
            [$addressless->id], 'Título', 'Cuerpo', [], ['resend_email'],
        );

        $this->assertSame([], $spy->recipients);
    }

    public function test_raw_addresses_pass_through_unchanged(): void
    {
        $spy = $this->spyChannel(ResendEmailChannel::class);

        app(NotificationDispatcher::class)->dispatch(
            ['vecino@example.com'], 'Título', 'Cuerpo', [], ['resend_email'],
        );

        $this->assertSame(['vecino@example.com'], $spy->recipients);
    }

    public function test_log_channel_still_receives_user_ids(): void
    {
        $spy = $this->spyChannel(LogChannel::class);
        $user = $this->makeUser();

        app(NotificationDispatcher::class)->dispatch(
            [$user->id], 'Título', 'Cuerpo', [], ['log'],
        );

        $this->assertSame([$user->id], $spy->recipients);
    }

    // =========================================================================
    // notifications-02: notifyUsers() appends firebase_push when globally enabled
    // =========================================================================

    /**
     * @return object harness exposing DispatchesNotifications::notifyUsers
     */
    private function notifierHarness(): object
    {
        return new class
        {
            use DispatchesNotifications;

            /**
             * @param  array<int, string>  $userIds
             * @param  string[]  $channels
             */
            public function notify(array $userIds, array $channels): void
            {
                $this->notifyUsers($userIds, 'Título', 'Cuerpo', [], false, $channels);
            }
        };
    }

    public function test_notify_users_appends_push_channel_when_enabled_in_config(): void
    {
        config(['notifications.channels' => ['log', 'firebase_push']]);

        $spy = $this->spyChannel(FirebasePushChannel::class);
        $user = $this->makeUser();

        $this->notifierHarness()->notify([$user->id], ['log']);

        $this->assertSame(
            [$user->token_sender],
            $spy->recipients,
            'firebase_push must be appended automatically when enabled in config',
        );
    }

    public function test_notify_users_does_not_push_when_config_disables_it(): void
    {
        config(['notifications.channels' => ['log']]);

        $spy = $this->spyChannel(FirebasePushChannel::class);
        $user = $this->makeUser();

        $this->notifierHarness()->notify([$user->id], ['log']);

        $this->assertSame([], $spy->recipients);
    }
}
