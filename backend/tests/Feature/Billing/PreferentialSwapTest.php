<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Cashier\Invoice;
use Laravel\Cashier\Subscription;
use Mockery;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\PreferentialSwapService;
use Modules\Tenant\Models\Tenant;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * Preferential (hidden, is_public=false) PLAN swap (billing v2). A super-admin
 * moves a cluster's per-module entitlements from its current plan to a hidden
 * preferential plan (cheaper plan_module_price never shown publicly).
 *
 * CARD paths run at the SERVICE level against a Mockery Subscription/Invoice double
 * (no Stripe network). MANUAL paths run at the service level AND through the HTTP
 * endpoints (a transfer cluster never touches Stripe).
 *
 * Dropped vs. the legacy suite: the old per-package (billing_package) swap cases —
 * the swap is now plan-level and re-points every active per-module entitlement.
 */
final class PreferentialSwapTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private const PREF_PRICE_ID = 'price_esencial_pref_base_month';

    private const PRORATION_DATE = 1750000000;

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
    // CARD preview / apply (service level, Stripe mocked)
    // =========================================================================

    public function test_preview_card_returns_proration_date_and_amount(): void
    {
        $pref = $this->makePreferentialPlan(withStripe: true);

        $invoice = Mockery::mock(Invoice::class);
        $invoice->shouldReceive('subscriptionProrationDate')->andReturn(self::PRORATION_DATE);
        $invoice->shouldReceive('rawAmountDue')->andReturn(31500);
        $invoice->shouldReceive('invoiceLineItems')->andReturn([]);

        $subscription = Mockery::mock(Subscription::class);
        $subscription->shouldReceive('previewInvoice')
            ->once()
            ->with([self::PREF_PRICE_ID => ['quantity' => 1]])
            ->andReturn($invoice);

        $result = app(PreferentialSwapService::class)->previewCard($subscription, $pref, ['base'], 'month');

        $this->assertFalse($result['manual']);
        $this->assertSame(self::PRORATION_DATE, $result['proration_date']);
        $this->assertSame(31500, $result['amount_due']);
        $this->assertSame([], $result['lines']);
    }

    public function test_apply_card_swaps_without_pinning_a_client_date_and_repoints_entitlement(): void
    {
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_CARD]);
        [$publicPlan, $base] = $this->makePublicPlanWithBase();
        $pref = $this->makePreferentialPlan(withStripe: true);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $publicPlan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 10,
            'stripe_subscription_id' => 'sub_card_123',
            'stripe_item_id' => 'si_card_123',
            'expires_at' => now()->addDays(20),
        ]);

        // swap() receives ONLY the prices — no proration_date option — so Stripe
        // dates the proration at now and the client cannot pin it (plan 055).
        $subscription = Mockery::mock(Subscription::class);
        $subscription->shouldReceive('hasIncompletePayment')->once()->andReturnFalse();
        $subscription->shouldReceive('swap')
            ->once()
            ->with([self::PREF_PRICE_ID => ['quantity' => 1]]);

        $entitlement = app(PreferentialSwapService::class)->applyCard(
            $tenant,
            $subscription,
            $pref,
            ['base'],
            'month',
        );

        $this->assertSame($pref->id, $entitlement->billing_package_id);
        $this->assertSame($pref->id, $tenant->fresh()->billing_package_id);
    }

    public function test_apply_card_is_blocked_by_an_incomplete_payment(): void
    {
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_CARD]);
        [$publicPlan, $base] = $this->makePublicPlanWithBase();
        $pref = $this->makePreferentialPlan(withStripe: true);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $publicPlan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'expires_at' => now()->addDays(20),
        ]);

        $subscription = Mockery::mock(Subscription::class);
        $subscription->shouldReceive('hasIncompletePayment')->once()->andReturnTrue();
        $subscription->shouldNotReceive('swap');

        $this->expectException(\InvalidArgumentException::class);

        app(PreferentialSwapService::class)->applyCard($tenant, $subscription, $pref, ['base'], 'month');
    }

    // =========================================================================
    // MANUAL preview / apply (service level — no Stripe)
    // =========================================================================

    public function test_preview_manual_reports_next_cycle_and_new_price(): void
    {
        $pref = $this->makePreferentialPlan(withStripe: false);

        $result = app(PreferentialSwapService::class)->previewManual($pref, ['base'], 'month');

        $this->assertTrue($result['manual']);
        $this->assertArrayHasKey('message', $result);
        $this->assertSame(31500, $result['new_price']);
    }

    public function test_apply_manual_repoints_entitlement_preserving_source_and_expiry(): void
    {
        $tenant = $this->makeTenant();
        [$publicPlan, $base] = $this->makePublicPlanWithBase();
        $pref = $this->makePreferentialPlan(withStripe: false);

        $expiry = now()->addMonth();
        $from = TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $publicPlan->id,
            'source' => TenantEntitlement::SOURCE_SUPER_ADMIN_FREE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'expires_at' => $expiry,
        ]);

        $entitlement = app(PreferentialSwapService::class)->applyManual($tenant, $pref);

        // The (same) base-module entitlement now points at the preferential plan,
        // source/expiry preserved.
        $this->assertSame($base->id, $entitlement->billable_module_id);
        $this->assertSame($pref->id, $entitlement->billing_package_id);
        $this->assertSame(TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, $entitlement->source);
        $this->assertEqualsWithDelta($expiry->timestamp, $entitlement->expires_at->timestamp, 2);
        $this->assertSame($pref->id, $tenant->fresh()->billing_package_id);
    }

    // =========================================================================
    // MANUAL HTTP endpoints (transfer cluster, super-admin gate)
    // =========================================================================

    public function test_http_preview_manual_returns_manual_true(): void
    {
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_TRANSFER]);
        $super = $this->makeUser();
        [$publicPlan, $base] = $this->makePublicPlanWithBase();
        $this->makePreferentialPlan(withStripe: false);
        $this->grantBase($tenant, $base, $publicPlan);

        $this->request($this->superAdminToken($super))
            ->postJson("/api/tenant/{$tenant->id}/preferential-swap/preview", [
                'to_plan_key' => 'esencial_pref',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.manual', true)
            ->assertJsonPath('data.new_price', 31500);
    }

    public function test_http_swap_manual_repoints_entitlement(): void
    {
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_TRANSFER]);
        $super = $this->makeUser();
        [$publicPlan, $base] = $this->makePublicPlanWithBase();
        $pref = $this->makePreferentialPlan(withStripe: false);
        $this->grantBase($tenant, $base, $publicPlan);

        $this->request($this->superAdminToken($super))
            ->postJson("/api/tenant/{$tenant->id}/preferential-swap", [
                'to_plan_key' => 'esencial_pref',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.billing_package_id', $pref->id);

        $this->assertTrue(
            TenantEntitlement::query()
                ->where('tenant_id', $tenant->id)
                ->where('billing_package_id', $pref->id)
                ->active()
                ->exists(),
        );
    }

    public function test_http_swap_rejects_a_public_target_plan(): void
    {
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_TRANSFER]);
        $super = $this->makeUser();
        [$publicPlan, $base] = $this->makePublicPlanWithBase();
        $this->makePreferentialPlan(withStripe: false);
        $this->grantBase($tenant, $base, $publicPlan);

        // The public plan is NOT a valid preferential (hidden) target.
        $this->request($this->superAdminToken($super))
            ->postJson("/api/tenant/{$tenant->id}/preferential-swap", [
                'to_plan_key' => 'esencial',
            ])
            ->assertStatus(422);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

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

    /**
     * Public plan 'esencial' + a base billable module (no modules → recompute no-op),
     * base cell priced 45000.
     *
     * @return array{0: BillingPackage, 1: BillableModule}
     */
    private function makePublicPlanWithBase(): array
    {
        $base = BillableModule::firstOrCreate(
            ['key' => 'base'],
            ['name' => 'Plan Base', 'description' => 'Plan Base', 'sort_order' => 0, 'is_active' => true, 'is_base' => true],
        );
        $plan = $this->makePlan('esencial', ['is_public' => true]);
        $this->makePlanModulePrice($plan, $base, 'month', 45000);

        return [$plan, $base];
    }

    /**
     * Hidden preferential plan 'esencial_pref' (is_public=false) carrying a base cell
     * priced 31500. With a stripe_price_id for the CARD path; without for MANUAL.
     */
    private function makePreferentialPlan(bool $withStripe): BillingPackage
    {
        $base = BillableModule::firstOrCreate(
            ['key' => 'base'],
            ['name' => 'Plan Base', 'description' => 'Plan Base', 'sort_order' => 0, 'is_active' => true, 'is_base' => true],
        );

        $pref = $this->makePlan('esencial_pref', ['is_public' => false, 'name' => 'Esencial Preferencial']);
        $this->makePlanModulePrice($pref, $base, 'month', 31500, [
            'stripe_price_id' => $withStripe ? self::PREF_PRICE_ID : null,
            'stripe_product_id' => $withStripe ? 'prod_base_pref' : null,
        ]);

        return $pref;
    }

    private function grantBase(Tenant $tenant, BillableModule $base, BillingPackage $plan): TenantEntitlement
    {
        return TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_SUPER_ADMIN_FREE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'expires_at' => now()->addMonth(),
        ]);
    }
}
