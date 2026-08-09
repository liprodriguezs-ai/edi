<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Cashier\Subscription;
use Mockery;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\SubscriptionPayment;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\SubscriptionPaymentService;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * Coverage for the manual-payment billing flow (billing v2, per module):
 * SubscriptionPaymentService::recordManual (ONE payment covering several MODULES —
 * per-module ledger + entitlement extension with source=MANUAL, period
 * stacking/anchoring, courtesy exclusion, unblock), changePaymentMethod (Stripe
 * cancel paths), and the super-admin HTTP endpoints.
 *
 * Network-free: cancel paths use a Mockery subscription double. Central-connection
 * writes → transaction wrapping disabled and central tables reset in tearDown.
 */
final class SubscriptionPaymentFlowTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        // PHPUnit runs in console: owen-it only registers its observer at model boot
        // when audit.console is true. Set BEFORE any Tenant/BaseModel boots so the
        // payment-method audit assertion is real.
        config(['audit.console' => true]);
    }

    protected function tearDown(): void
    {
        $this->cleanBillingTables();
        DB::table('subscriptions')->delete();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Mockery::close();

        parent::tearDown();
    }

    // =========================================================================
    // recordManual — period stacking / anchoring + source=MANUAL
    // =========================================================================

    public function test_record_manual_extends_an_active_monthly_entitlement_by_stacking(): void
    {
        $tenant = $this->makeTenant();
        [$plan, $base] = $this->planAndBase();
        $recorder = $this->makeUser();

        $currentExpiry = now()->addDays(10);
        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_MANUAL,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'expires_at' => $currentExpiry,
        ]);

        $payment = $this->service()->recordManual(
            $tenant,
            [$this->line($base, $plan, 95000)],
            SubscriptionPayment::METHOD_TRANSFER,
            now(),
            'Transferencia ref 1001',
            $recorder,
        )->first();

        $expected = $currentExpiry->copy()->addMonth();
        $this->assertEqualsWithDelta($expected->timestamp, $payment->period_end->timestamp, 2);
        $this->assertEqualsWithDelta($currentExpiry->timestamp, $payment->period_start->timestamp, 2);

        $entitlement = $this->entitlementFor($tenant, $base);

        $this->assertEqualsWithDelta($expected->timestamp, $entitlement->expires_at->timestamp, 2);
        $this->assertSame(TenantEntitlement::SOURCE_MANUAL, $entitlement->source);
        $this->assertSame($entitlement->id, $payment->tenant_entitlement_id);
        $this->assertSame($payment->id, $entitlement->metadata['subscription_payment_id'] ?? null);
    }

    public function test_record_manual_records_source_manual_not_courtesy(): void
    {
        $tenant = $this->makeTenant();
        [$plan, $base] = $this->planAndBase();

        $this->service()->recordManual(
            $tenant,
            [$this->line($base, $plan, 95000)],
            SubscriptionPayment::METHOD_TRANSFER,
            now(),
            null,
            $this->makeUser(),
        );

        $entitlement = $this->entitlementFor($tenant, $base);

        $this->assertSame(TenantEntitlement::SOURCE_MANUAL, $entitlement->source);
        $this->assertNotSame(TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, $entitlement->source);
    }

    public function test_record_manual_anchors_at_now_when_entitlement_is_expired(): void
    {
        $tenant = $this->makeTenant();
        [$plan, $base] = $this->planAndBase();
        $recorder = $this->makeUser();

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_MANUAL,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'expires_at' => now()->subDays(5),
        ]);

        $payment = $this->service()->recordManual(
            $tenant,
            [$this->line($base, $plan, 95000)],
            SubscriptionPayment::METHOD_CASH,
            now(),
            null,
            $recorder,
        )->first();

        $this->assertEqualsWithDelta(now()->timestamp, $payment->period_start->timestamp, 2);
        $this->assertEqualsWithDelta(now()->addMonth()->timestamp, $payment->period_end->timestamp, 2);
    }

    public function test_record_manual_extends_a_yearly_cycle_from_passed_interval(): void
    {
        $tenant = $this->makeTenant();
        [$plan, $base] = $this->planAndBase();
        $recorder = $this->makeUser();

        $payment = $this->service()->recordManual(
            $tenant,
            [$this->line($base, $plan, 1140000, PlanModulePrice::INTERVAL_YEAR)],
            SubscriptionPayment::METHOD_TRANSFER,
            now(),
            null,
            $recorder,
        )->first();

        $this->assertEqualsWithDelta(now()->addYear()->timestamp, $payment->period_end->timestamp, 2);

        $entitlement = $this->entitlementFor($tenant, $base);
        $this->assertSame(PlanModulePrice::INTERVAL_YEAR, $entitlement->interval);
    }

    public function test_record_manual_unblocks_a_blocked_tenant(): void
    {
        $tenant = $this->makeTenant(['status' => Tenant::STATUS_BLOQUEADO]);
        [$plan, $base] = $this->planAndBase();
        $recorder = $this->makeUser();

        $this->service()->recordManual(
            $tenant,
            [$this->line($base, $plan, 95000)],
            SubscriptionPayment::METHOD_TRANSFER,
            now(),
            null,
            $recorder,
        );

        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->fresh()->status);
    }

    public function test_record_manual_rejects_an_invalid_method(): void
    {
        $tenant = $this->makeTenant();
        [$plan, $base] = $this->planAndBase();

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->recordManual(
            $tenant,
            [$this->line($base, $plan, 95000)],
            'paypal',
            now(),
            null,
            $this->makeUser(),
        );
    }

    public function test_record_manual_rejects_an_empty_line_set(): void
    {
        $tenant = $this->makeTenant();

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->recordManual(
            $tenant,
            [],
            SubscriptionPayment::METHOD_TRANSFER,
            now(),
            null,
            $this->makeUser(),
        );
    }

    // =========================================================================
    // recordManual — ONE payment covering SEVERAL modules
    // =========================================================================

    public function test_record_manual_covers_two_modules_in_one_payment(): void
    {
        $tenant = $this->makeTenant();
        [$plan, $base] = $this->planAndBase();
        $addon = $this->addonModule();
        $recorder = $this->makeUser();

        $payments = $this->service()->recordManual(
            $tenant,
            [
                $this->line($base, $plan, 95000),
                $this->line($addon, $plan, 30000),
            ],
            SubscriptionPayment::METHOD_TRANSFER,
            now(),
            'Pago que cubre todo',
            $recorder,
        );

        $this->assertCount(2, $payments);
        $this->assertSame(2, SubscriptionPayment::query()->where('tenant_id', $tenant->id)->count());

        foreach ([$base, $addon] as $module) {
            $entitlement = $this->entitlementFor($tenant, $module);

            $this->assertSame(TenantEntitlement::SOURCE_MANUAL, $entitlement->source);
            $this->assertEqualsWithDelta(now()->addMonth()->timestamp, $entitlement->expires_at->timestamp, 2);
        }

        $this->assertSame(95000, (int) SubscriptionPayment::query()->where('billable_module_id', $base->id)->value('amount'));
        $this->assertSame(30000, (int) SubscriptionPayment::query()->where('billable_module_id', $addon->id)->value('amount'));
    }

    public function test_record_manual_excludes_a_courtesy_module_from_the_charge(): void
    {
        $tenant = $this->makeTenant();
        [$plan, $base] = $this->planAndBase();
        $courtesy = $this->addonModule();
        $recorder = $this->makeUser();

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $courtesy->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_SUPER_ADMIN_FREE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'expires_at' => null,
        ]);

        $payments = $this->service()->recordManual(
            $tenant,
            [
                $this->line($base, $plan, 95000),
                $this->line($courtesy, $plan, 30000),
            ],
            SubscriptionPayment::METHOD_TRANSFER,
            now(),
            null,
            $recorder,
        );

        $this->assertCount(1, $payments);
        $this->assertSame(0, SubscriptionPayment::query()->where('billable_module_id', $courtesy->id)->count());
        $this->assertSame(1, SubscriptionPayment::query()->where('billable_module_id', $base->id)->count());

        $courtesyEntitlement = $this->entitlementFor($tenant, $courtesy);
        $this->assertSame(TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, $courtesyEntitlement->source);
        $this->assertNull($courtesyEntitlement->expires_at);
    }

    public function test_record_manual_throws_when_every_line_is_courtesy(): void
    {
        $tenant = $this->makeTenant();
        [$plan, $base] = $this->planAndBase();

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_SUPER_ADMIN_FREE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'expires_at' => null,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->recordManual(
            $tenant,
            [$this->line($base, $plan, 95000)],
            SubscriptionPayment::METHOD_TRANSFER,
            now(),
            null,
            $this->makeUser(),
        );
    }

    // =========================================================================
    // changePaymentMethod — Stripe cancel paths
    // =========================================================================

    public function test_change_payment_method_cancels_subscription_at_period_end(): void
    {
        $tenant = $this->makeTenant();

        $subscription = Mockery::mock(Subscription::class);
        $subscription->shouldReceive('active')->once()->andReturn(true);
        $subscription->shouldReceive('cancel')->once();
        $subscription->shouldNotReceive('cancelNow');

        $this->service()->changePaymentMethod(
            $tenant,
            Tenant::PAYMENT_METHOD_TRANSFER,
            $subscription,
            SubscriptionPaymentService::CANCEL_AT_PERIOD_END,
        );

        $this->assertSame(Tenant::PAYMENT_METHOD_TRANSFER, $tenant->fresh()->payment_method);
    }

    public function test_change_payment_method_cancels_subscription_now(): void
    {
        $tenant = $this->makeTenant();

        $subscription = Mockery::mock(Subscription::class);
        $subscription->shouldReceive('active')->once()->andReturn(true);
        $subscription->shouldReceive('cancelNow')->once();
        $subscription->shouldNotReceive('cancel');

        $this->service()->changePaymentMethod(
            $tenant,
            Tenant::PAYMENT_METHOD_CASH,
            $subscription,
            SubscriptionPaymentService::CANCEL_NOW,
        );

        $this->assertSame(Tenant::PAYMENT_METHOD_CASH, $tenant->fresh()->payment_method);
    }

    public function test_change_payment_method_to_card_never_cancels(): void
    {
        $tenant = $this->makeTenant();

        $subscription = Mockery::mock(Subscription::class);
        $subscription->shouldNotReceive('cancel');
        $subscription->shouldNotReceive('cancelNow');

        $this->service()->changePaymentMethod(
            $tenant,
            Tenant::PAYMENT_METHOD_CARD,
            $subscription,
        );

        $this->assertSame(Tenant::PAYMENT_METHOD_CARD, $tenant->fresh()->payment_method);
    }

    // =========================================================================
    // HTTP endpoints (super-admin gate)
    // =========================================================================

    public function test_store_endpoint_records_a_manual_payment(): void
    {
        $tenant = $this->makeTenant();
        [$plan, $base] = $this->planAndBase();
        $super = $this->makeUser();

        $this->request($this->superAdminToken($super))
            ->postJson("/api/tenant/{$tenant->id}/subscription-payment", [
                'method' => SubscriptionPayment::METHOD_TRANSFER,
                'notes' => 'Pago manual',
                'lines' => [
                    ['module_key' => $base->key, 'plan_key' => $plan->key, 'amount' => 95000],
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.0.method', SubscriptionPayment::METHOD_TRANSFER)
            ->assertJsonPath('data.0.amount', 95000);

        $this->assertDatabaseHas('subscription_payment', [
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'method' => SubscriptionPayment::METHOD_TRANSFER,
            'recorded_by_user_id' => $super->id,
        ]);
    }

    public function test_store_endpoint_records_a_payment_covering_two_modules(): void
    {
        $tenant = $this->makeTenant();
        [$plan, $base] = $this->planAndBase();
        $addon = $this->addonModule();
        $super = $this->makeUser();

        $this->request($this->superAdminToken($super))
            ->postJson("/api/tenant/{$tenant->id}/subscription-payment", [
                'method' => SubscriptionPayment::METHOD_TRANSFER,
                'lines' => [
                    ['module_key' => $base->key, 'plan_key' => $plan->key, 'amount' => 95000],
                    ['module_key' => $addon->key, 'plan_key' => $plan->key, 'amount' => 30000],
                ],
            ])
            ->assertStatus(201)
            ->assertJsonCount(2, 'data');

        $this->assertSame(2, SubscriptionPayment::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_store_endpoint_rejects_a_future_paid_at(): void
    {
        $tenant = $this->makeTenant();
        [$plan, $base] = $this->planAndBase();
        $super = $this->makeUser();

        $this->request($this->superAdminToken($super))
            ->postJson("/api/tenant/{$tenant->id}/subscription-payment", [
                'method' => SubscriptionPayment::METHOD_TRANSFER,
                'paid_at' => now()->addDay()->toIso8601String(),
                'lines' => [
                    ['module_key' => $base->key, 'plan_key' => $plan->key, 'amount' => 95000],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('paid_at');

        $this->assertDatabaseMissing('subscription_payment', [
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
        ]);
    }

    public function test_store_endpoint_requires_at_least_one_line(): void
    {
        $tenant = $this->makeTenant();
        $super = $this->makeUser();

        $this->request($this->superAdminToken($super))
            ->postJson("/api/tenant/{$tenant->id}/subscription-payment", [
                'method' => SubscriptionPayment::METHOD_TRANSFER,
                'lines' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines');
    }

    public function test_store_endpoint_is_forbidden_for_non_super_admin(): void
    {
        $tenant = $this->makeTenant();
        [$plan, $base] = $this->planAndBase();
        $user = $this->makeUser();

        $token = $user->createToken('limited', ['read.tenant', 'create.payment'])->plainTextToken;

        $this->request($token)
            ->postJson("/api/tenant/{$tenant->id}/subscription-payment", [
                'method' => SubscriptionPayment::METHOD_TRANSFER,
                'lines' => [
                    ['module_key' => $base->key, 'plan_key' => $plan->key, 'amount' => 95000],
                ],
            ])
            ->assertStatus(403);
    }

    public function test_index_endpoint_lists_the_tenant_ledger(): void
    {
        $tenant = $this->makeTenant();
        [$plan, $base] = $this->planAndBase();
        $super = $this->makeUser();

        $this->service()->recordManual($tenant, [$this->line($base, $plan, 95000)], SubscriptionPayment::METHOD_TRANSFER, now()->subMonth(), 'Primer pago', $super);
        $this->service()->recordManual($tenant, [$this->line($base, $plan, 95000)], SubscriptionPayment::METHOD_CASH, now(), 'Segundo pago', $super);

        // A second tenant's payment must NOT appear in this ledger.
        $otherTenant = $this->makeTenant();
        $this->service()->recordManual($otherTenant, [$this->line($base, $plan, 50000)], SubscriptionPayment::METHOD_CASH, now(), 'Otro organización', $super);

        $response = $this->request($this->superAdminToken($super))
            ->getJson("/api/tenant/{$tenant->id}/subscription-payment")
            ->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame(SubscriptionPayment::METHOD_CASH, $data[0]['method']);
    }

    public function test_patch_payment_method_validates_and_audits(): void
    {
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_CARD]);
        $super = $this->makeUser();

        $this->request($this->superAdminToken($super))
            ->patchJson("/api/tenant/{$tenant->id}/payment-method", ['payment_method' => 'paypal'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');

        $before = $tenant->audits()->count();

        $this->request($this->superAdminToken($super))
            ->patchJson("/api/tenant/{$tenant->id}/payment-method", ['payment_method' => Tenant::PAYMENT_METHOD_TRANSFER])
            ->assertStatus(200)
            ->assertJsonPath('data.payment_method', Tenant::PAYMENT_METHOD_TRANSFER);

        $this->assertSame(Tenant::PAYMENT_METHOD_TRANSFER, $tenant->fresh()->payment_method);
        $this->assertGreaterThan($before, $tenant->fresh()->audits()->count());
    }

    // =========================================================================
    // `card` is not a manual method
    // =========================================================================

    public function test_record_manual_rejects_card_as_a_manual_method(): void
    {
        $tenant = $this->makeTenant();
        [$plan, $base] = $this->planAndBase();

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->recordManual(
            $tenant,
            [$this->line($base, $plan, 95000)],
            SubscriptionPayment::METHOD_CARD,
            now(),
            null,
            $this->makeUser(),
        );
    }

    public function test_store_endpoint_rejects_card_method(): void
    {
        $tenant = $this->makeTenant();
        [$plan, $base] = $this->planAndBase();
        $super = $this->makeUser();

        $this->request($this->superAdminToken($super))
            ->postJson("/api/tenant/{$tenant->id}/subscription-payment", [
                'method' => SubscriptionPayment::METHOD_CARD,
                'lines' => [
                    ['module_key' => $base->key, 'plan_key' => $plan->key, 'amount' => 95000],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('method');
    }

    // =========================================================================
    // idempotency against a double submit
    // =========================================================================

    public function test_record_manual_is_idempotent_against_a_double_submit(): void
    {
        $tenant = $this->makeTenant();
        [$plan, $base] = $this->planAndBase();
        $recorder = $this->makeUser();

        $paidAt = now();

        $this->service()->recordManual(
            $tenant,
            [$this->line($base, $plan, 95000)],
            SubscriptionPayment::METHOD_TRANSFER,
            $paidAt,
            'Ref 1001',
            $recorder,
        );

        $entitlement = $this->entitlementFor($tenant, $base);
        $expiryAfterFirst = $entitlement->expires_at->timestamp;

        try {
            $this->service()->recordManual(
                $tenant,
                [$this->line($base, $plan, 95000)],
                SubscriptionPayment::METHOD_TRANSFER,
                $paidAt,
                'Ref 1001',
                $recorder,
            );
            $this->fail('Expected InvalidArgumentException on a duplicate manual payment.');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->assertSame(1, SubscriptionPayment::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_module_id', $base->id)
            ->count());

        $this->assertSame($expiryAfterFirst, $entitlement->fresh()->expires_at->timestamp);
    }

    // =========================================================================
    // never clobber a card / active-stripe module
    // =========================================================================

    public function test_record_manual_rejects_a_card_paying_tenant(): void
    {
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_CARD]);
        [$plan, $base] = $this->planAndBase();

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->recordManual(
            $tenant,
            [$this->line($base, $plan, 95000)],
            SubscriptionPayment::METHOD_TRANSFER,
            now(),
            null,
            $this->makeUser(),
        );
    }

    public function test_record_manual_refuses_to_clobber_an_active_stripe_entitlement(): void
    {
        $tenant = $this->makeTenant();
        [$plan, $base] = $this->planAndBase();
        $recorder = $this->makeUser();

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_keepme_123',
            'stripe_item_id' => 'si_keepme_123',
            'expires_at' => now()->addDays(20),
        ]);

        try {
            $this->service()->recordManual(
                $tenant,
                [$this->line($base, $plan, 95000)],
                SubscriptionPayment::METHOD_TRANSFER,
                now(),
                null,
                $recorder,
            );
            $this->fail('Expected InvalidArgumentException guarding the stripe entitlement.');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $entitlement = $this->entitlementFor($tenant, $base);

        $this->assertSame(TenantEntitlement::SOURCE_STRIPE, $entitlement->source);
        $this->assertSame('sub_keepme_123', $entitlement->stripe_subscription_id);

        $this->assertSame(0, SubscriptionPayment::query()->where('tenant_id', $tenant->id)->count());
    }

    // =========================================================================
    // ambiguous owner must not orphan an active Stripe subscription
    // =========================================================================

    public function test_patch_payment_method_refuses_when_owner_is_ambiguous_and_subscription_active(): void
    {
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_CARD]);
        $super = $this->makeUser();

        $subscriber = $this->makeUser();
        $tenant->users()->attach($subscriber->id);

        Subscription::create([
            'user_id' => $subscriber->id,
            'type' => 'default',
            'stripe_id' => 'sub_orphan_guard_123',
            'stripe_status' => 'active',
            'stripe_price' => 'price_x',
            'quantity' => 1,
        ]);

        $this->request($this->superAdminToken($super))
            ->patchJson("/api/tenant/{$tenant->id}/payment-method", ['payment_method' => Tenant::PAYMENT_METHOD_TRANSFER])
            ->assertStatus(422);

        $this->assertSame(Tenant::PAYMENT_METHOD_CARD, $tenant->fresh()->payment_method);
        $this->assertDatabaseHas('subscriptions', [
            'stripe_id' => 'sub_orphan_guard_123',
            'stripe_status' => 'active',
        ]);
    }

    // =========================================================================
    // DB-level dedupe backstop (now keyed by billable_module_id)
    // =========================================================================

    public function test_duplicate_manual_payment_row_is_rejected_by_the_unique_constraint(): void
    {
        $tenant = $this->makeTenant();
        [$plan, $base] = $this->planAndBase();
        $paidAt = now();

        $attributes = [
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'amount' => 45000,
            'currency' => 'MXN',
            'method' => SubscriptionPayment::METHOD_TRANSFER,
            'paid_at' => $paidAt,
        ];

        SubscriptionPayment::create($attributes);

        $this->expectException(QueryException::class);
        SubscriptionPayment::create($attributes);
    }

    public function test_soft_deleted_manual_payment_does_not_block_a_fresh_identical_row(): void
    {
        $tenant = $this->makeTenant();
        [$plan, $base] = $this->planAndBase();
        $paidAt = now();

        $attributes = [
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'amount' => 45000,
            'currency' => 'MXN',
            'method' => SubscriptionPayment::METHOD_TRANSFER,
            'paid_at' => $paidAt,
        ];

        $first = SubscriptionPayment::create($attributes);
        $first->delete();

        $second = SubscriptionPayment::create($attributes);

        $this->assertNotNull($second->id);
        $this->assertNotSame($first->id, $second->id);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function service(): SubscriptionPaymentService
    {
        return app(SubscriptionPaymentService::class);
    }

    /**
     * A plan + a base billable module (no system modules → recompute is a no-op).
     *
     * @return array{0: BillingPackage, 1: BillableModule}
     */
    private function planAndBase(): array
    {
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);

        return [$plan, $base];
    }

    private function addonModule(): BillableModule
    {
        return $this->makeBillableModule('package', 'Paquetería');
    }

    /**
     * One recordManual line for a module under a plan.
     *
     * @return array{module: BillableModule, plan: BillingPackage, amount: int, interval: string|null}
     */
    private function line(BillableModule $module, BillingPackage $plan, int $amount, ?string $interval = null): array
    {
        return ['module' => $module, 'plan' => $plan, 'amount' => $amount, 'interval' => $interval];
    }

    private function entitlementFor(Tenant $tenant, BillableModule $module): TenantEntitlement
    {
        return TenantEntitlement::query()
            ->where('tenant_id', $tenant->id)
            ->where('billable_module_id', $module->id)
            ->firstOrFail();
    }

    /**
     * Local override of the trait helper: default to transfer (recording a MANUAL
     * payment requires the organization to be off card), and accept status/payment_method.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeTenant(array $attributes = []): Tenant
    {
        return Event::fakeFor(
            static fn (): Tenant => Tenant::create(array_merge([
                'id' => (string) Str::uuid(),
                'name' => 'Test Tenant '.Str::random(5),
                'status' => Tenant::STATUS_ACTIVO,
                'currency' => 'MXN',
                'payment_method' => Tenant::PAYMENT_METHOD_TRANSFER,
            ], $attributes)),
            [TenantCreated::class],
        );
    }

    private function makeUser(): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Super '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);
    }

    private function superAdminToken(User $user): string
    {
        return $user->createToken('super', ['*'])->plainTextToken;
    }

    private function request(string $token): self
    {
        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        return $this->withToken($token);
    }
}
