<?php

declare(strict_types=1);

namespace Tests\Feature\Tracking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Tracking\Channels\GoogleAnalyticsChannel;
use Modules\Tracking\DTO\TrackingEvent;
use Tests\TestCase;

/**
 * GoogleAnalyticsChannel: correct Measurement Protocol URL/payload (GA4 event
 * name mapping, client_id resolution/fallback), and the shared
 * no-op-without-credentials contract.
 */
final class GoogleAnalyticsChannelTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_analytics.measurement_id' => 'G-ABC123',
            'services.google_analytics.api_secret' => 'secret-xyz',
        ]);
    }

    public function test_sends_mapped_event_name_and_client_id_to_measurement_protocol(): void
    {
        Http::fake();

        $event = TrackingEvent::fromArray([
            'name' => 'Purchase',
            'event_id' => 'evt-ga-1',
            'params' => ['value' => 199.0, 'currency' => 'MXN'],
            'user_data' => ['client_id' => 'ga-client-1'],
        ]);

        (new GoogleAnalyticsChannel)->send($event);

        Http::assertSent(function ($request) {
            $this->assertSame(
                'https://www.google-analytics.com/mp/collect?measurement_id=G-ABC123&api_secret=secret-xyz',
                $request->url(),
            );

            $payload = $request->data();
            $this->assertSame('ga-client-1', $payload['client_id']);
            $this->assertSame('purchase', $payload['events'][0]['name']);
            $this->assertSame(199.0, $payload['events'][0]['params']['value']);
            $this->assertSame('MXN', $payload['events'][0]['params']['currency']);
            $this->assertSame('evt-ga-1', $payload['events'][0]['params']['transaction_id']);

            return true;
        });
    }

    public function test_generates_a_client_id_when_none_is_provided(): void
    {
        Http::fake();

        (new GoogleAnalyticsChannel)->send(TrackingEvent::fromArray(['name' => 'Lead']));

        Http::assertSent(function ($request) {
            $this->assertSame('generate_lead', $request->data()['events'][0]['name']);
            $this->assertNotEmpty($request->data()['client_id']);

            return true;
        });
    }

    public function test_no_ops_without_measurement_id(): void
    {
        config(['services.google_analytics.measurement_id' => null]);
        Http::fake();

        (new GoogleAnalyticsChannel)->send(TrackingEvent::fromArray(['name' => 'Lead']));

        Http::assertNothingSent();
    }

    public function test_no_ops_without_api_secret(): void
    {
        config(['services.google_analytics.api_secret' => null]);
        Http::fake();

        (new GoogleAnalyticsChannel)->send(TrackingEvent::fromArray(['name' => 'Lead']));

        Http::assertNothingSent();
    }
}
