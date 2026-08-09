<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Listeners\StripeWebhookListener;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Events\WebhookReceived;
use Mockery;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;
use Tests\Feature\E2E\ClusterScenario;
use Tests\TestCase;

/**
 * Stripe webhook (Cashier v16) integration for the cross-cutting cluster concern:
 * flipping tenant.status. Access is granted by tenant_entitlement (billing v2);
 * the legacy single-plan checkout / change-plan endpoints have been retired.
 *
 * Network-free: webhook transitions are driven by dispatching Cashier's
 * WebhookReceived event directly with a crafted payload — no real Stripe round-trip.
 */
final class StripeCheckoutTest extends TestCase
{
    use ClusterScenario;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /** @var array<string, mixed> */
    private array $scn;

    private Tenant $tenant;

    private User $owner;

    private string $ownerEmail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scn = $this->buildScenario();
        $this->tenant = $this->scn['tenant'];

        // The scenario has no account.owner by default — add one. The Stripe
        // subscription/card always belongs to this person.
        $this->ownerEmail = 'owner.'.$this->scn['emails']['laura'];
        $this->owner = $this->makeAppUser(
            $this->tenant,
            'Owner Encinos',
            $this->ownerEmail,
            Role::SLUG_ACCOUNT_OWNER,
        );
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Mockery::close();

        parent::tearDown();
    }

    /**
     * FIX 7 (S2): an async-method session (OXXO/transfer) arrives with
     * payment_status='unpaid' BEFORE the money lands — must NOT activate.
     */
    public function test_webhook_checkout_completed_unpaid_does_not_activate(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_PENDIENTE_PAGO]);

        $this->dispatchWebhook('checkout.session.completed', [
            'object' => [
                'id' => 'cs_test_unpaid',
                'payment_status' => 'unpaid',
                'metadata' => [
                    'tenant_id' => $this->tenant->id,
                ],
            ],
        ]);

        $reloaded = Tenant::find($this->tenant->id);
        $this->assertSame(Tenant::STATUS_PENDIENTE_PAGO, $reloaded->status);
    }

    public function test_webhook_invoice_payment_failed_marks_tenant_pending_payment(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_ACTIVO]);

        // No subscription metadata on invoices → resolve via the Stripe customer
        // mapped to the owner (who holds account.owner and one tenant).
        $this->owner->forceFill(['stripe_id' => 'cus_failed_123'])->save();

        $this->dispatchWebhook('invoice.payment_failed', [
            'object' => [
                'id' => 'in_test_failed',
                'customer' => 'cus_failed_123',
            ],
        ]);

        $reloaded = Tenant::find($this->tenant->id);
        $this->assertSame(Tenant::STATUS_PENDIENTE_PAGO, $reloaded->status);
    }

    public function test_webhook_subscription_deleted_blocks_tenant(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_ACTIVO]);

        $this->dispatchWebhook('customer.subscription.deleted', [
            'object' => [
                'id' => 'sub_test_deleted',
                'metadata' => ['tenant_id' => $this->tenant->id],
            ],
        ]);

        $reloaded = Tenant::find($this->tenant->id);
        $this->assertSame(Tenant::STATUS_BLOQUEADO, $reloaded->status);
    }

    public function test_webhook_subscription_updated_past_due_marks_pending(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_ACTIVO]);

        $this->dispatchWebhook('customer.subscription.updated', [
            'object' => [
                'id' => 'sub_test_pastdue',
                'status' => 'past_due',
                'metadata' => ['tenant_id' => $this->tenant->id],
            ],
        ]);

        $reloaded = Tenant::find($this->tenant->id);
        $this->assertSame(Tenant::STATUS_PENDIENTE_PAGO, $reloaded->status);
    }

    /**
     * subscription.updated(active) must NOT first-time activate a tenant with no
     * active entitlement — checkout.session.completed (which grants the
     * entitlements) is the sole first-time activator. Out-of-order delivery of
     * subscription.* must not grant access for free.
     */
    public function test_webhook_subscription_updated_active_does_not_activate_tenant_without_entitlement(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_PENDIENTE_PAGO]);

        $this->dispatchWebhook('customer.subscription.updated', [
            'object' => [
                'id' => 'sub_test_active_no_entitlement',
                'status' => 'active',
                'metadata' => ['tenant_id' => $this->tenant->id],
            ],
        ]);

        $this->assertSame(
            Tenant::STATUS_PENDIENTE_PAGO,
            Tenant::find($this->tenant->id)->status,
        );
    }

    /**
     * Resume guard: a subscription that is NOT on its grace period (active, not
     * scheduled to cancel) cannot be resumed → 409. The guard runs BEFORE any
     * Stripe call, so no network mock is needed.
     */
    public function test_resume_conflicts_when_not_on_grace_period(): void
    {
        Subscription::create([
            'user_id' => $this->owner->id,
            'type' => 'default',
            'stripe_id' => 'sub_resume_active',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test_resume',
            'quantity' => 1,
            'ends_at' => null,
        ]);

        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/config/subscription/resume')
            ->assertStatus(409);
    }

    /**
     * Resume without any subscription → 404.
     */
    public function test_resume_fails_without_subscription(): void
    {
        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/config/subscription/resume')
            ->assertStatus(404);
    }

    /**
     * FIX 9 (W8): the public webhook route returns a controller response even when
     * the listener hits a bad payload (the listener try/catches and logs).
     * Signature verification is what gates this route, so without a valid signature
     * the controller returns before the listener — assert the route exists and is
     * public (NOT 401/404: no auth redirect, route is registered).
     */
    public function test_webhook_route_is_public_and_swallows_bad_payload(): void
    {
        // No STRIPE_WEBHOOK_SECRET in the test env → the controller constructor
        // (FIX 4) fails closed with 500. That itself proves the route is public
        // (reached without auth: not 401/404) and that the fail-closed guard fires.
        config(['cashier.webhook.secret' => null]);

        $response = $this->postJson('/api/stripe/webhook', [
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['garbage' => true]],
        ]);

        // Public route: NOT an auth rejection and NOT a missing route.
        $this->assertNotContains($response->status(), [401, 404]);
        $this->assertSame(500, $response->status());
    }

    /**
     * Invoke the listener through Cashier's WebhookReceived event with a crafted
     * payload (no real Stripe call). The webhook runs unauthenticated and with no
     * tenancy — mirror that by ending tenancy first.
     *
     * @param  array<string, mixed>  $data
     */
    private function dispatchWebhook(string $type, array $data): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        app(StripeWebhookListener::class)->handle(
            new WebhookReceived([
                'type' => $type,
                'data' => $data,
            ]),
        );
    }
}
