<?php

declare(strict_types=1);

namespace Tests\Feature\Tracking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Tracking\Models\TrackingEvent as TrackingEventLog;
use Tests\TestCase;

/**
 * Feature coverage for POST /api/track — the public, unauthenticated beacon
 * shared by the landing and the React app.
 *
 * QUEUE_CONNECTION=sync in testing (phpunit.xml), so DeliverTrackingEventJob
 * runs INLINE within the request — no Queue::fake() needed to observe the
 * real FacebookConversionsChannel HTTP call via Http::fake().
 */
final class TrackEndpointTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.facebook.pixel_id' => 'pixel-123',
            'services.facebook.capi_access_token' => 'token-abc',
            'services.google_analytics.measurement_id' => 'G-ABC123',
            'services.google_analytics.api_secret' => 'secret-xyz',
        ]);
    }

    public function test_valid_event_is_accepted_and_dispatched_to_facebook_only(): void
    {
        Http::fake();

        $response = $this->postJson('/api/track', [
            'event' => 'Lead',
            'event_id' => '11111111-1111-1111-1111-111111111111',
            'action_source' => 'website',
            'source_url' => 'https://kivo7.com/pricing',
            'user_data' => ['email' => 'juan@example.com'],
        ]);

        $response->assertStatus(202);
        $response->assertJson([
            'status' => 'queued',
            'event_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        // ONLY Facebook is called — GA4 is sent client-side by gtag, so
        // dispatching it here too would double-count (§ dedup rule).
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $this->assertStringContainsString('graph.facebook.com', $request->url());

            $data = $request->data()['data'][0];
            $this->assertSame('Lead', $data['event_name']);
            $this->assertSame('11111111-1111-1111-1111-111111111111', $data['event_id']);
            $this->assertSame('website', $data['action_source']);
            $this->assertSame([hash('sha256', 'juan@example.com')], $data['user_data']['em']);
            // Server-enriched, never sent by the client.
            $this->assertNotEmpty($data['user_data']['client_ip_address']);
            $this->assertNotEmpty($data['user_data']['client_user_agent']);

            return true;
        });

        $this->assertDatabaseHas((new TrackingEventLog)->getTable(), [
            'event_id' => '11111111-1111-1111-1111-111111111111',
            'channel' => 'facebook',
            'status' => TrackingEventLog::STATUS_SENT,
        ]);
    }

    public function test_missing_event_name_is_rejected(): void
    {
        $response = $this->postJson('/api/track', [
            'event_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['event']);
    }

    public function test_unknown_event_name_is_rejected(): void
    {
        $response = $this->postJson('/api/track', [
            'event' => 'NotACanonicalEvent',
            'event_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['event']);
    }

    public function test_missing_event_id_is_rejected(): void
    {
        $response = $this->postJson('/api/track', [
            'event' => 'Lead',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['event_id']);
    }

    public function test_endpoint_requires_no_authentication(): void
    {
        Http::fake();

        // Deliberately NOT authenticated (no Sanctum actor) — the landing and
        // anonymous visitors must be able to hit this endpoint.
        $response = $this->postJson('/api/track', [
            'event' => 'Lead',
            'event_id' => '22222222-2222-2222-2222-222222222222',
        ]);

        $response->assertStatus(202);
    }
}
