<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Listeners\StripeWebhookListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Cashier\Events\WebhookReceived;
use Modules\Module\Models\Module;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\EntitlementService;
use Modules\System\Models\System;
use Modules\Tenant\Models\Tenant;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * Webhook IDEMPOTENCY + ORDERING and entitlement-source CONSISTENCY (per-module).
 *
 * Stripe delivers at-least-once and may re-order; this exercises:
 *   - replay of the same event id is a no-op (Cache::add dedupe),
 *   - customer.subscription.updated(active) arriving BEFORE checkout.completed
 *     still grants + activates and a later checkout.completed is idempotent,
 *   - a LATE invoice.payment_succeeded carrying an OLDER period MUST NOT shorten
 *     the entitlement's expiry,
 *   - super-admin grant-free MUST NOT clobber an ACTIVE Stripe entitlement (the
 *     grant() clobber guard protects the Stripe linkage).
 *
 * The listener is invoked directly through Cashier's WebhookReceived event with a
 * crafted payload (no real Stripe round-trip).
 */
final class BillingEntitlementConsistencyTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    private const BASE_PRICE_ID = 'price_base_consistency';

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        $this->cleanBillingTables();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // =========================================================================
    // Idempotency: replayed event id is a no-op
    // =========================================================================

    public function test_replayed_checkout_event_with_same_id_is_a_noop(): void
    {
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_PENDIENTE_PAGO]);
        [$system, $module, $base, $plan] = $this->seedCatalog();

        $eventId = 'evt_replay_consistency_fixed';

        $payload = [
            'object' => [
                'id' => 'cs_replay',
                'payment_status' => 'paid',
                'subscription' => $this->subscriptionPayload('sub_replay', [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, now()->addMonth()->timestamp),
                ]),
                'metadata' => ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month'],
            ],
        ];

        $this->dispatchWebhook('checkout.session.completed', $payload, $eventId);
        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->fresh()->status);
        $this->assertSame(1, TenantEntitlement::where('tenant_id', $tenant->id)->count());

        // Simulate an out-of-band block, then REPLAY the identical event id.
        $tenant->update(['status' => Tenant::STATUS_BLOQUEADO]);
        $this->dispatchWebhook('checkout.session.completed', $payload, $eventId);

        $this->assertSame(Tenant::STATUS_BLOQUEADO, $tenant->fresh()->status, 'replayed event must be a no-op');
        $this->assertSame(1, TenantEntitlement::where('tenant_id', $tenant->id)->count());
    }

    // =========================================================================
    // Ordering: subscription.updated(active) before checkout.completed
    // =========================================================================

    public function test_subscription_updated_active_before_checkout_grants_and_activates(): void
    {
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_PENDIENTE_PAGO]);
        [$system, $module, $base, $plan] = $this->seedCatalog();

        $this->dispatchWebhook('customer.subscription.updated', [
            'object' => [
                'id' => 'sub_ooo',
                'status' => 'active',
                'metadata' => ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month'],
                'items' => ['data' => [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, now()->addMonth()->timestamp),
                ]],
            ],
        ]);

        $this->assertTrue($this->entitlementExists($tenant, $base), 'updated-first must grant the entitlement');
        $this->assertTrue($this->systemAttached($tenant, $system));
        $this->assertTrue($this->moduleEnabled($tenant, $module));
        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->fresh()->status);

        $this->dispatchWebhook('checkout.session.completed', [
            'object' => [
                'id' => 'cs_ooo',
                'payment_status' => 'paid',
                'subscription' => $this->subscriptionPayload('sub_ooo', [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, now()->addMonth()->timestamp),
                ]),
                'metadata' => ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month'],
            ],
        ]);

        $this->assertSame(1, TenantEntitlement::where('tenant_id', $tenant->id)->count(), 'no duplicate entitlement');
        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->fresh()->status);
    }

    // =========================================================================
    // A late invoice with an older period must NOT shorten expiry
    // =========================================================================

    public function test_late_invoice_does_not_shorten_entitlement_expiry(): void
    {
        $tenant = $this->makeTenant();
        [$system, $module, $base, $plan] = $this->seedCatalog();

        $farPeriodEnd = now()->addDays(60);

        $this->dispatchWebhook('checkout.session.completed', [
            'object' => [
                'id' => 'cs_late_inv',
                'payment_status' => 'paid',
                'subscription' => $this->subscriptionPayload('sub_late_inv', [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, $farPeriodEnd->timestamp),
                ]),
                'metadata' => ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month'],
            ],
        ]);

        $this->assertSame($farPeriodEnd->timestamp, $this->entitlement($tenant, $base)->expires_at->timestamp);

        $earlierPeriodEnd = now()->addDays(30);

        $this->dispatchWebhook('invoice.payment_succeeded', [
            'object' => [
                'id' => 'in_late',
                'subscription' => 'sub_late_inv',
                'subscription_details' => ['metadata' => ['tenant_id' => $tenant->id]],
                'lines' => ['data' => [
                    ['period' => ['end' => $earlierPeriodEnd->timestamp]],
                ]],
            ],
        ]);

        $this->assertSame(
            $farPeriodEnd->timestamp,
            $this->entitlement($tenant, $base)->expires_at->timestamp,
            'a late invoice with an older period shortened the entitlement expiry',
        );
    }

    // =========================================================================
    // grant-free must not silently clobber an active Stripe entitlement
    // =========================================================================

    public function test_grant_free_must_not_silently_clobber_an_active_stripe_entitlement(): void
    {
        $tenant = $this->makeTenant();
        [$system, $module, $base, $plan] = $this->seedCatalog();

        $this->dispatchWebhook('checkout.session.completed', [
            'object' => [
                'id' => 'cs_clobber',
                'payment_status' => 'paid',
                'subscription' => $this->subscriptionPayload('sub_clobber', [
                    $this->itemPayload('si_base', self::BASE_PRICE_ID, 10, now()->addDays(30)->timestamp),
                ]),
                'metadata' => ['tenant_id' => $tenant->id, 'plan_key' => 'esencial', 'interval' => 'month'],
            ],
        ]);

        $before = $this->entitlement($tenant, $base);
        $this->assertSame(TenantEntitlement::SOURCE_STRIPE, $before->source);
        $this->assertSame('sub_clobber', $before->stripe_subscription_id);

        // grant() has the centralized clobber guard: a non-stripe grant over an
        // ACTIVE stripe entitlement is a no-op that returns the live row unchanged.
        app(EntitlementService::class)->grant(
            $tenant,
            $base,
            $plan,
            TenantEntitlement::SOURCE_SUPER_ADMIN_FREE,
            ['expires_at' => null],
        );

        $after = $this->entitlement($tenant, $base);

        $this->assertSame(TenantEntitlement::SOURCE_STRIPE, $after->source, 'the guard must keep the stripe source');
        $this->assertSame(
            'sub_clobber',
            $after->stripe_subscription_id,
            'grant-free must not destroy the active Stripe subscription linkage',
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @return array{0: System, 1: Module, 2: BillableModule, 3: BillingPackage}
     */
    private function seedCatalog(): array
    {
        $system = $this->makeSystem();
        $module = $this->makeModuleIn($system, 'Cobros');
        $base = $this->makeBillableModule('base', 'Plan Base', [$module], isBase: true);
        $plan = $this->makePlan('esencial');
        $this->makePlanModulePrice($plan, $base, 'month', 95000, [
            'stripe_price_id' => self::BASE_PRICE_ID,
            'stripe_product_id' => 'prod_'.self::BASE_PRICE_ID,
        ]);

        return [$system, $module, $base, $plan];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function subscriptionPayload(string $id, array $items): array
    {
        return ['id' => $id, 'items' => ['data' => $items]];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemPayload(string $itemId, string $priceId, int $quantity, int $currentPeriodEnd): array
    {
        return [
            'id' => $itemId,
            'quantity' => $quantity,
            'current_period_end' => $currentPeriodEnd,
            'price' => ['id' => $priceId, 'recurring' => ['interval' => 'month']],
        ];
    }

    private function entitlement(Tenant $tenant, BillableModule $module): TenantEntitlement
    {
        return TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_module_id', $module->id)
            ->firstOrFail();
    }

    private function entitlementExists(Tenant $tenant, BillableModule $module): bool
    {
        return TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_module_id', $module->id)
            ->exists();
    }

    private function systemAttached(Tenant $tenant, System $system): bool
    {
        return $tenant->systems()
            ->wherePivot('is_addon', false)
            ->where('system.id', $system->id)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function dispatchWebhook(string $type, array $data, ?string $eventId = null): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        app(StripeWebhookListener::class)->handle(
            new WebhookReceived([
                'id' => $eventId ?? 'evt_'.Str::lower(Str::random(16)),
                'type' => $type,
                'data' => $data,
            ]),
        );
    }
}
