<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\Channels\FirebasePushChannel;
use App\Notifications\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Notification\Jobs\DeliverChannelMessageJob;
use Tests\TestCase;

/**
 * Push delivery must be QUEUED off-request — never a synchronous FCM HTTP call in
 * the request. The dispatcher resolves device tokens INLINE, then enqueues
 * DeliverChannelMessageJob(s). Tokens are BATCHED in chunks of ≤500 (FCM
 * sendMulticast limit): one job per chunk, each carrying up to 500 tokens.
 */
final class FirebasePushChannelTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private function makeUser(?string $token = 'fcm-token'): User
    {
        return User::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid7(),
            'name' => 'Push User',
            'email' => Str::random(8).'@test.com',
            'password' => bcrypt('secret1234'),
            'auth_active' => true,
            'token_sender' => $token,
        ]);
    }

    public function test_push_tokens_are_batched_into_a_single_job_under_the_chunk_size(): void
    {
        Queue::fake();
        $a = $this->makeUser('token-a');
        $b = $this->makeUser('token-b');

        app(NotificationDispatcher::class)->dispatch(
            [$a->id, $b->id], 'Aviso', 'Cuerpo', ['click_action' => '/announcement'], ['firebase_push'],
        );

        // Both tokens ride ONE chunked job (≤500), not one job per recipient.
        Queue::assertPushed(DeliverChannelMessageJob::class, 1);
        Queue::assertPushed(
            DeliverChannelMessageJob::class,
            static fn (DeliverChannelMessageJob $job): bool => $job->channel === 'firebase_push'
                && count($job->recipients) === 2
                && in_array('token-a', $job->recipients, true)
                && in_array('token-b', $job->recipients, true),
        );
    }

    public function test_push_is_chunked_into_jobs_of_up_to_500_tokens(): void
    {
        Queue::fake();

        // Raw (non-UUID) tokens pass straight through address resolution, so we can
        // exercise the 500-token chunk boundary without creating 501 user rows.
        $tokens = array_map(static fn (int $i): string => "token-{$i}", range(1, 501));

        app(NotificationDispatcher::class)->dispatch(
            $tokens, 'Aviso', 'Cuerpo', [], ['firebase_push'],
        );

        // 501 tokens → 2 jobs: one of 500, one of 1.
        Queue::assertPushed(DeliverChannelMessageJob::class, 2);
        Queue::assertPushed(
            DeliverChannelMessageJob::class,
            static fn (DeliverChannelMessageJob $job): bool => $job->channel === 'firebase_push' && count($job->recipients) === 500,
        );
        Queue::assertPushed(
            DeliverChannelMessageJob::class,
            static fn (DeliverChannelMessageJob $job): bool => $job->channel === 'firebase_push' && count($job->recipients) === 1,
        );
    }

    public function test_no_job_is_queued_when_the_recipient_has_no_token(): void
    {
        Queue::fake();
        $user = $this->makeUser(null);

        app(NotificationDispatcher::class)->dispatch(
            [$user->id], 'Aviso', 'Cuerpo', [], ['firebase_push'],
        );

        Queue::assertNotPushed(DeliverChannelMessageJob::class);
    }

    public function test_delivery_job_retries_up_to_three_times(): void
    {
        $job = new DeliverChannelMessageJob('firebase_push', ['token-a'], 'A', 'B');

        $this->assertSame(3, $job->tries);
    }

    public function test_channel_no_ops_without_credentials(): void
    {
        config(['services.firebase.credentials' => null]);

        // Must not throw nor attempt an FCM call when credentials are absent.
        (new FirebasePushChannel)->send(['token-a'], 'Aviso', 'Cuerpo', []);

        $this->assertTrue(true);
    }

    /**
     * Regression: $data may carry NESTED ARRAYS (e.g. 'email_context', the email
     * template variables). FCM's data map must be string→string; a stray
     * `(string) $array` is a fatal ErrorException in production ("Array to string
     * conversion"). stringifyData must DROP arrays and stringify scalars/null.
     */
    public function test_stringify_data_drops_nested_arrays_and_stringifies_scalars(): void
    {
        $channel = new FirebasePushChannel;
        $method = new \ReflectionMethod($channel, 'stringifyData');
        $method->setAccessible(true);

        $out = $method->invoke($channel, [
            'click_action' => '/payment/1',
            'payment_id' => 'abc',
            'email_context' => ['resident_name' => 'Juan', 'unit' => 'Casa 1'],
            'flag' => true,
            'count' => 5,
            'nothing' => null,
        ]);

        $this->assertSame([
            'click_action' => '/payment/1',
            'payment_id' => 'abc',
            'flag' => '1',
            'count' => '5',
            'nothing' => '',
        ], $out);
        $this->assertArrayNotHasKey('email_context', $out);
    }
}
