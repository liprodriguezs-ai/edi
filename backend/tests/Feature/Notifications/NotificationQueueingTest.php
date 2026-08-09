<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Notification\Jobs\DeliverChannelMessageJob;
use Modules\Notification\Models\NotificationUser;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

/**
 * Cambio 6 — every channel that does external I/O is QUEUED (one
 * DeliverChannelMessageJob per channel × recipient) so the request never blocks on
 * email/SMS/push I/O. The 'log' channel and the in-app record (recordInApp) stay
 * SYNCHRONOUS so the bell badge appears instantly.
 */
final class NotificationQueueingTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeUser(array $overrides = []): User
    {
        return User::withoutGlobalScopes()->create(array_merge([
            'id' => (string) Str::uuid(),
            'name' => 'Queue User '.Str::random(4),
            'email' => Str::random(8).'@test.com',
            'password' => bcrypt('secret1234'),
            'auth_active' => true,
            'phone' => '+52155'.mt_rand(1000000, 9999999),
            'token_sender' => 'fcm-'.Str::random(16),
        ], $overrides));
    }

    public function test_io_channels_are_queued_one_job_per_channel_and_recipient(): void
    {
        Queue::fake();
        $a = $this->makeUser();
        $b = $this->makeUser();

        app(NotificationDispatcher::class)->dispatch(
            [$a->id, $b->id], 'Título', 'Cuerpo', [], ['resend_email', 'sms'],
        );

        // 2 recipients × 2 I/O channels = 4 queued delivery jobs.
        Queue::assertPushed(DeliverChannelMessageJob::class, 4);
    }

    public function test_log_channel_is_never_queued(): void
    {
        Queue::fake();
        $user = $this->makeUser();

        app(NotificationDispatcher::class)->dispatch(
            [$user->id], 'Título', 'Cuerpo', [], ['log'],
        );

        Queue::assertNotPushed(DeliverChannelMessageJob::class);
    }

    public function test_in_app_record_is_synchronous_even_when_deliveries_are_queued(): void
    {
        Queue::fake();

        $tenant = Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => 'Queue Cluster',
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );

        $user = $this->makeUser();
        $user->tenants()->attach($tenant->id);

        $harness = new class
        {
            use NotifiesResidents;

            /**
             * @param  array<int, string>  $ids
             */
            public function go(array $ids): void
            {
                $this->notifyUsers($ids, 'Hola', 'Cuerpo', [], true, ['log', 'firebase_push']);
            }
        };

        $tenant->run(function () use ($harness, $user): void {
            $harness->go([$user->id]);

            // In-app row recorded SYNCHRONOUSLY (instant badge) despite the queued push.
            $this->assertTrue(
                NotificationUser::where('user_id', $user->id)->exists(),
                'recordInApp must run synchronously, not on a queue worker',
            );
        });

        // The push delivery was QUEUED, not executed inline.
        Queue::assertPushed(DeliverChannelMessageJob::class);
    }
}
