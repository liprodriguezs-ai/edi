<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Channels\FirebasePushChannel;
use App\Notifications\Contracts\NotificationChannelInterface;
use App\Notifications\Services\NotificationDispatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

/**
 * Notification preferences + quiet hours (§17.3).
 *
 * The dispatcher routes user-id recipients; per-user preferences filter each channel.
 * We use a spy channel bound for 'firebase_push' to assert exactly which recipients a
 * push reached, without real network calls. The dispatcher resolves user ids to their
 * stored FCM device token (token_sender), so the spy observes TOKENS, never user ids.
 */
final class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeUser(string $name = 'Pref User'): User
    {
        return User::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid7(),
            'name' => $name,
            'email' => Str::random(8).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
            'token_sender' => 'fcm-'.Str::random(20),
        ]);
    }

    private function makeTenant(string $timezone = 'America/Mexico_City'): Tenant
    {
        return Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid7(),
                'name' => 'Quiet Hours Organization',
                'status' => Tenant::STATUS_ACTIVO,
                'timezone' => $timezone,
            ]),
            [TenantCreated::class],
        );
    }

    /**
     * Bind a spy push channel that records the recipients it was asked to send to.
     *
     * @return object{recipients: array<int, mixed>}
     */
    private function spyPushChannel(): object
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

        $this->app->instance(FirebasePushChannel::class, $spy);

        return $spy;
    }

    // =========================================================================
    // Endpoints
    // =========================================================================

    public function test_get_preferences_returns_default_when_none_exists(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->withToken($token)->getJson('/api/user/notification-preferences')
            ->assertStatus(200)
            ->assertJsonPath('data.channels', [])
            ->assertJsonPath('data.quiet_hours_start', null);
    }

    public function test_put_preferences_persists_and_round_trips(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $payload = [
            'channels' => ['payments' => ['push' => false, 'email' => true]],
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '07:00',
        ];

        $this->withToken($token)->putJson('/api/user/notification-preferences', $payload)
            ->assertStatus(200)
            ->assertJsonPath('data.channels.payments.push', false);

        $this->assertDatabaseHas('notification_preference', ['user_id' => $user->id]);

        $this->withToken($token)->getJson('/api/user/notification-preferences')
            ->assertStatus(200)
            ->assertJsonPath('data.channels.payments.push', false)
            ->assertJsonPath('data.channels.payments.email', true);
    }

    public function test_put_rejects_unknown_channel_key(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->withToken($token)->putJson('/api/user/notification-preferences', [
            'channels' => ['payments' => ['carrier_pigeon' => true]],
        ])->assertStatus(422);
    }

    // =========================================================================
    // Dispatcher honors preferences
    // =========================================================================

    public function test_default_no_preference_row_pushes_to_everyone(): void
    {
        $spy = $this->spyPushChannel();
        $a = $this->makeUser('A');
        $b = $this->makeUser('B');

        app(NotificationDispatcher::class)->dispatch(
            [$a->id, $b->id],
            'Title',
            'Body',
            [],
            ['firebase_push'],
        );

        $this->assertContains($a->token_sender, $spy->recipients);
        $this->assertContains($b->token_sender, $spy->recipients);
    }

    public function test_user_who_disabled_push_does_not_receive_push(): void
    {
        $spy = $this->spyPushChannel();
        $optedOut = $this->makeUser('OptedOut');
        $optedIn = $this->makeUser('OptedIn');

        NotificationPreference::create([
            'user_id' => $optedOut->id,
            'channels' => ['_global' => ['push' => false]],
        ]);

        app(NotificationDispatcher::class)->dispatch(
            [$optedOut->id, $optedIn->id],
            'Title',
            'Body',
            [],
            ['firebase_push'],
        );

        $this->assertNotContains($optedOut->token_sender, $spy->recipients, 'User who disabled push must not be pushed');
        $this->assertContains($optedIn->token_sender, $spy->recipients);
    }

    public function test_category_specific_opt_out_only_affects_that_category(): void
    {
        $spy = $this->spyPushChannel();
        $user = $this->makeUser('CategoryUser');

        NotificationPreference::create([
            'user_id' => $user->id,
            'channels' => ['announcements' => ['push' => false]],
        ]);

        // Push for the muted category → suppressed.
        app(NotificationDispatcher::class)->dispatch(
            [$user->id], 'A', 'B', ['category' => 'announcements'], ['firebase_push'],
        );
        $this->assertNotContains($user->token_sender, $spy->recipients);

        // Push for a different category → still delivered.
        app(NotificationDispatcher::class)->dispatch(
            [$user->id], 'A', 'B', ['category' => 'payments'], ['firebase_push'],
        );
        $this->assertContains($user->token_sender, $spy->recipients);
    }

    public function test_quiet_hours_suppress_non_urgent_push_but_allow_urgent(): void
    {
        // Freeze "now" inside the quiet-hours window (23:30).
        Carbon::setTestNow(Carbon::parse('2026-06-01 23:30:00'));

        $spy = $this->spyPushChannel();
        $user = $this->makeUser('Quiet');

        NotificationPreference::create([
            'user_id' => $user->id,
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '07:00',
        ]);

        // Non-urgent push inside quiet hours → suppressed.
        app(NotificationDispatcher::class)->dispatch(
            [$user->id], 'A', 'B', [], ['firebase_push'],
        );
        $this->assertNotContains($user->token_sender, $spy->recipients, 'Non-urgent push must be suppressed in quiet hours');

        // Urgent push inside quiet hours → still delivered.
        app(NotificationDispatcher::class)->dispatch(
            [$user->id], 'A', 'B', ['urgent' => true], ['firebase_push'],
        );
        $this->assertContains($user->token_sender, $spy->recipients, 'Urgent push must bypass quiet hours');
    }

    public function test_quiet_hours_do_not_suppress_outside_the_window(): void
    {
        // Freeze "now" OUTSIDE quiet hours (12:00).
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));

        $spy = $this->spyPushChannel();
        $user = $this->makeUser('Daytime');

        NotificationPreference::create([
            'user_id' => $user->id,
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '07:00',
        ]);

        app(NotificationDispatcher::class)->dispatch(
            [$user->id], 'A', 'B', [], ['firebase_push'],
        );

        $this->assertContains($user->token_sender, $spy->recipients);
    }

    // =========================================================================
    // Quiet hours are evaluated in the TENANT's timezone (§17.3)
    // =========================================================================

    public function test_quiet_hours_use_tenant_timezone_not_server_clock(): void
    {
        // 23:30 UTC = 17:30 in America/Mexico_City (UTC-6): INSIDE the window for a
        // UTC clock, OUTSIDE for the tenant's wall clock → must be delivered.
        Carbon::setTestNow(Carbon::parse('2026-06-01 23:30:00', 'UTC'));

        $spy = $this->spyPushChannel();
        $user = $this->makeUser('TenantTz Delivered');
        $tenant = $this->makeTenant('America/Mexico_City');

        NotificationPreference::create([
            'user_id' => $user->id,
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '07:00',
        ]);

        tenancy()->initialize($tenant);
        app(NotificationDispatcher::class)->dispatch(
            [$user->id], 'A', 'B', [], ['firebase_push'],
        );
        tenancy()->end();

        $this->assertContains(
            $user->token_sender,
            $spy->recipients,
            'Quiet hours must follow the tenant wall clock (17:30 local is not quiet)',
        );
    }

    public function test_quiet_hours_suppress_when_tenant_local_time_is_inside_window(): void
    {
        // 10:00 UTC = 04:00 in America/Mexico_City: OUTSIDE the window for a UTC
        // clock, INSIDE for the tenant's wall clock → must be suppressed.
        Carbon::setTestNow(Carbon::parse('2026-06-01 10:00:00', 'UTC'));

        $spy = $this->spyPushChannel();
        $user = $this->makeUser('TenantTz Suppressed');
        $tenant = $this->makeTenant('America/Mexico_City');

        NotificationPreference::create([
            'user_id' => $user->id,
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '07:00',
        ]);

        tenancy()->initialize($tenant);
        app(NotificationDispatcher::class)->dispatch(
            [$user->id], 'A', 'B', [], ['firebase_push'],
        );
        tenancy()->end();

        $this->assertNotContains(
            $user->token_sender,
            $spy->recipients,
            'Quiet hours must suppress when the tenant wall clock is inside the window (04:00 local)',
        );
    }
}
