<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Listeners\StripeWebhookListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Cashier\Events\WebhookReceived;
use Modules\Tenant\Models\Tenant;
use Modules\Tracking\DTO\TrackingEvent;
use Modules\Tracking\Services\TrackingDispatcher;
use RuntimeException;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

/**
 * Coverage for the Purchase tracking hook added to StripeWebhookListener:
 * checkout.session.completed dispatches a Purchase event to BOTH Facebook and
 * GA4 (server-side conversion), and a tracking failure must NEVER break the
 * webhook's entitlement activation (the listener never lets it bubble up —
 * mirrors StripeWebhookEntitlementTest's isolation style).
 */
final class StripeWebhookTrackingTest extends TestCase
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

    public function test_checkout_completed_dispatches_purchase_to_facebook_and_google_analytics(): void
    {
        $tenant = $this->makeTenant();

        $fakeDispatcher = $this->createMock(TrackingDispatcher::class);
        $fakeDispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->callback(function (TrackingEvent $event) use ($tenant): bool {
                    return $event->name === 'Purchase'
                        && $event->eventId === 'evt-purchase-1'
                        && $event->actionSource === 'system_generated'
                        && $event->params['value'] === 450.0
                        && $event->params['currency'] === 'MXN'
                        && $event->params['transaction_id'] === (string) $tenant->getKey()
                        && $event->userData['client_id'] === 'ga-client-1';
                }),
                ['facebook', 'google_analytics'],
            );
        $this->app->instance(TrackingDispatcher::class, $fakeDispatcher);

        $this->dispatchWebhook($tenant, [
            'amount_total' => 45000,
            'currency' => 'mxn',
            'metadata' => [
                'tenant_id' => $tenant->id,
                'event_id' => 'evt-purchase-1',
                'ga_client_id' => 'ga-client-1',
            ],
        ]);

        $this->assertSame(Tenant::STATUS_ACTIVO, Tenant::find($tenant->id)->status);
    }

    public function test_checkout_completed_activates_tenant_even_when_tracking_dispatch_throws(): void
    {
        $tenant = $this->makeTenant(Tenant::STATUS_PENDIENTE_PAGO);

        $throwingDispatcher = $this->createMock(TrackingDispatcher::class);
        $throwingDispatcher->method('dispatch')->willThrowException(new RuntimeException('tracking down'));
        $this->app->instance(TrackingDispatcher::class, $throwingDispatcher);

        $this->dispatchWebhook($tenant, [
            'amount_total' => 45000,
            'currency' => 'mxn',
            'metadata' => ['tenant_id' => $tenant->id],
        ]);

        // Entitlement activation already committed BEFORE the tracking hook runs,
        // and the hook's own try/catch swallows the failure — the webhook's
        // primary effect must be unaffected.
        $this->assertSame(Tenant::STATUS_ACTIVO, Tenant::find($tenant->id)->status);
    }

    private function makeTenant(string $status = Tenant::STATUS_PENDIENTE_PAGO): Tenant
    {
        return Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid7(),
                'name' => 'WH Tracking Tenant '.Str::random(5),
                'status' => $status,
            ]),
            [TenantCreated::class],
        );
    }

    /**
     * @param  array<string, mixed>  $objectOverrides
     */
    private function dispatchWebhook(Tenant $tenant, array $objectOverrides): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        app(StripeWebhookListener::class)->handle(
            new WebhookReceived([
                'id' => 'evt_'.Str::lower(Str::random(16)),
                'type' => 'checkout.session.completed',
                'data' => [
                    'object' => array_merge([
                        'id' => 'cs_track_'.Str::random(8),
                        'payment_status' => 'paid',
                        'metadata' => ['tenant_id' => $tenant->id],
                    ], $objectOverrides),
                ],
            ]),
        );
    }
}
