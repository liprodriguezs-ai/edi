<?php

declare(strict_types=1);

namespace Tests\Feature\Tracking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Tracking\Channels\FacebookConversionsChannel;
use Modules\Tracking\DTO\TrackingEvent;
use Tests\TestCase;

/**
 * FacebookConversionsChannel: correct Graph API URL/payload shape (hashed
 * Advanced Matching fields), and the no-op-without-credentials contract shared
 * by every channel in this project (e.g. App\Notifications\Channels\SmsChannel).
 */
final class FacebookConversionsChannelTest extends TestCase
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
            'services.facebook.test_event_code' => 'TEST12345',
            'tracking.api_version' => 'v20.0',
        ]);
    }

    public function test_sends_hashed_payload_to_the_correct_graph_api_url(): void
    {
        Http::fake();

        $event = TrackingEvent::fromArray([
            'name' => 'Purchase',
            'event_id' => 'evt-fb-1',
            'event_time' => 1700000000,
            'source_url' => 'https://edi.example.com/checkout',
            'action_source' => 'website',
            'params' => ['value' => 199.0, 'currency' => 'MXN'],
            'user_data' => [
                'email' => 'Juan@Example.com',
                'fbp' => 'fb.1.111.222',
                'client_ip' => '10.0.0.1',
                'client_user_agent' => 'test-agent',
            ],
        ]);

        (new FacebookConversionsChannel)->send($event);

        Http::assertSent(function ($request) {
            $this->assertSame(
                'https://graph.facebook.com/v20.0/pixel-123/events',
                $request->url(),
            );
            $this->assertStringNotContainsString('token-abc', $request->url());

            $data = $request->data()['data'][0];

            $this->assertSame('Purchase', $data['event_name']);
            $this->assertSame('evt-fb-1', $data['event_id']);
            $this->assertSame(1700000000, $data['event_time']);
            $this->assertSame('website', $data['action_source']);
            $this->assertSame('https://edi.example.com/checkout', $data['event_source_url']);
            $this->assertSame([hash('sha256', 'juan@example.com')], $data['user_data']['em']);
            $this->assertSame('fb.1.111.222', $data['user_data']['fbp']);
            $this->assertSame('10.0.0.1', $data['user_data']['client_ip_address']);
            $this->assertSame('test-agent', $data['user_data']['client_user_agent']);
            $this->assertSame(['value' => 199.0, 'currency' => 'MXN'], $data['custom_data']);
            $this->assertSame('TEST12345', $request->data()['test_event_code']);
            $this->assertSame('token-abc', $request->data()['access_token']);

            return true;
        });
    }

    /**
     * SECURITY (plan 066): access_token must travel in the POST body, never the
     * URL query string — outgoing request URIs get persisted verbatim by
     * Telescope's ClientRequestWatcher and by proxy/APM access logs, either of
     * which would otherwise leak the live token on a store/log dump.
     */
    public function test_access_token_never_appears_in_the_request_url(): void
    {
        Http::fake();

        (new FacebookConversionsChannel)->send(TrackingEvent::fromArray(['name' => 'Lead']));

        Http::assertSent(function ($request) {
            $this->assertStringNotContainsString('access_token', $request->url());
            $this->assertStringNotContainsString('token-abc', $request->url());
            $this->assertSame('token-abc', $request->data()['access_token']);

            return true;
        });
    }

    /**
     * REGRESSION (FIX 4): Facebook's action_source enum has NO "system" value —
     * only email|website|phone_call|chat|physical_store|system_generated|other.
     * Sending the wrong literal made Meta reject every server-side hit (Purchase,
     * CompleteRegistration), Http::throw() turned that into an exception, and the
     * job silently died after 3 retries. The channel must forward whatever value
     * the DTO carries UNCHANGED — this pins that a system-originated event
     * actually carries "system_generated", not "system".
     */
    public function test_forwards_system_generated_action_source_unchanged(): void
    {
        Http::fake();

        $event = TrackingEvent::fromArray([
            'name' => 'Purchase',
            'event_id' => 'evt-fb-2',
            'action_source' => 'system_generated',
        ]);

        (new FacebookConversionsChannel)->send($event);

        Http::assertSent(function ($request) {
            $this->assertSame('system_generated', $request->data()['data'][0]['action_source']);

            return true;
        });
    }

    public function test_no_ops_without_pixel_id(): void
    {
        config(['services.facebook.pixel_id' => null]);
        Http::fake();

        (new FacebookConversionsChannel)->send(TrackingEvent::fromArray(['name' => 'Lead']));

        Http::assertNothingSent();
    }

    public function test_no_ops_without_access_token(): void
    {
        config(['services.facebook.capi_access_token' => null]);
        Http::fake();

        (new FacebookConversionsChannel)->send(TrackingEvent::fromArray(['name' => 'Lead']));

        Http::assertNothingSent();
    }
}
