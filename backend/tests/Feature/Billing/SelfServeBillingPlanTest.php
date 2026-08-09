<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModule;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\PreferentialSwapService;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;
use Tests\Feature\E2E\ClusterScenario;
use Tests\TestCase;

/**
 * OWNER self-serve billing endpoints:
 *   - POST config/subscription/change-billing-plan {plan_key, proration_date?}
 *   - POST config/subscription/change-billing-plan/preview {plan_key}
 *
 * Owner-scoped (single account.owner), gated by manage.subscription. The owner logs
 * in for real (token carries their true role abilities). change-billing-plan reuses
 * PreferentialSwapService: CARD path is asserted at the controller-wiring level with
 * a service mock (no Stripe network); MANUAL path runs through the real service.
 */
final class SelfServeBillingPlanTest extends TestCase
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

    private BillingPackage $esencial;

    private BillingPackage $pro;

    private BillingPackage $lite;

    private BillableModule $base;

    private BillableModule $addon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scn = $this->buildScenario();
        $this->tenant = $this->scn['tenant'];

        $this->ownerEmail = 'owner.'.$this->scn['emails']['laura'];
        $this->owner = $this->makeAppUser($this->tenant, 'Owner Encinos', $this->ownerEmail, Role::SLUG_ACCOUNT_OWNER);

        $this->seedMatrix();
        $this->tenant->update(['billing_package_id' => $this->esencial->id]);
    }

    protected function tearDown(): void
    {
        DB::table('subscriptions')->delete();
        DB::table('system_tenant')->delete();
        DB::table('module_tenant')->delete();
        DB::table('tenant_entitlement')->delete();
        DB::table('plan_module_price')->delete();
        DB::table('plan_module')->delete();
        DB::table('billable_module_system_module')->delete();
        DB::table('billable_module')->delete();
        DB::table('billing_package')->delete();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Mockery::close();

        parent::tearDown();
    }

    public function test_start_module_trial_endpoint_no_longer_exists(): void
    {
        // Per-module self-serve was removed in the 2026-07 collapse.
        $this->grantActive($this->base, TenantEntitlement::SOURCE_STRIPE);

        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/config/subscription/start-module-trial', ['module_key' => 'package'])
            ->assertStatus(404);
    }

    // =========================================================================
    // change-billing-plan — MANUAL (no active Stripe subscription)
    // =========================================================================

    public function test_owner_change_billing_plan_manual_repoints_entitlements(): void
    {
        // A contracted (manual) base entitlement, no Cashier subscription → MANUAL path.
        $baseEnt = $this->grantActive($this->base, TenantEntitlement::SOURCE_MANUAL);

        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/config/subscription/change-billing-plan', ['plan_key' => 'pro'])
            ->assertStatus(200)
            ->assertJsonPath('data.billing_package_id', $this->pro->id);

        $this->assertSame($this->pro->id, $this->tenant->fresh()->billing_package_id);
        $this->assertSame($this->pro->id, $baseEnt->fresh()->billing_package_id);
    }

    public function test_owner_change_billing_plan_rejects_a_target_missing_a_contracted_module(): void
    {
        // Contracted base + package; the target 'lite' offers ONLY base.
        $this->grantActive($this->base, TenantEntitlement::SOURCE_MANUAL);
        $this->grantActive($this->addon, TenantEntitlement::SOURCE_MANUAL);

        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/config/subscription/change-billing-plan', ['plan_key' => 'lite'])
            ->assertStatus(422);

        // Nothing changed.
        $this->assertSame($this->esencial->id, $this->tenant->fresh()->billing_package_id);
    }

    public function test_owner_change_billing_plan_preview_manual_reports_the_new_price(): void
    {
        $this->grantActive($this->base, TenantEntitlement::SOURCE_MANUAL);

        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/config/subscription/change-billing-plan/preview', ['plan_key' => 'pro'])
            ->assertStatus(200)
            ->assertJsonPath('data.manual', true)
            ->assertJsonPath('data.new_price', 150000);
    }

    // =========================================================================
    // change-billing-plan — CARD (active Stripe subscription, service mocked)
    // =========================================================================

    public function test_owner_change_billing_plan_card_swaps_via_service_ignoring_any_client_proration_date(): void
    {
        $baseEnt = $this->grantActive($this->base, TenantEntitlement::SOURCE_STRIPE);
        $this->seedActiveCashierSubscription('sub_change_plan');

        // The service is invoked with EXACTLY five args (no proration_date): even
        // though the request body carries a bogus proration_date, the controller no
        // longer forwards it, so a caller cannot pin a favorable date (plan 055).
        $service = Mockery::mock(PreferentialSwapService::class);
        $service->shouldReceive('applyCard')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::any(),
                Mockery::on(fn ($to): bool => $to instanceof BillingPackage && $to->key === 'pro'),
                Mockery::on(fn ($keys): bool => $keys === ['base']),
                'month',
            )
            ->andReturn($baseEnt);
        $service->shouldNotReceive('applyManual');
        $this->app->instance(PreferentialSwapService::class, $service);

        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/config/subscription/change-billing-plan', [
                'plan_key' => 'pro',
                'proration_date' => 1750000000,
            ])
            ->assertStatus(200);
    }

    // =========================================================================
    // GET config/subscription — current_plan (F-25)
    // =========================================================================

    public function test_subscription_read_describes_the_current_plan_for_a_manually_paid_cluster(): void
    {
        // F-25 — a cluster that pays out of band (transfer/cash) used to read
        // current_plan:null and the screen invited it to subscribe by card again.
        $this->grantActive($this->base, TenantEntitlement::SOURCE_MANUAL);

        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->getJson('/api/config/subscription')
            ->assertStatus(200)
            ->assertJsonPath('data.current_plan.key', 'esencial')
            ->assertJsonPath('data.current_plan.name', 'Esencial')
            ->assertJsonPath('data.current_plan.source', TenantEntitlement::SOURCE_MANUAL)
            ->assertJsonPath('data.current_plan.interval', 'month')
            ->assertJsonPath('data.current_plan.total', 95000)
            ->assertJsonPath('data.current_plan.currency', 'MXN')
            ->assertJsonPath('data.is_multi_item', false);
    }

    public function test_subscription_read_describes_a_courtesy_grant_at_zero(): void
    {
        $courtesy = $this->grantActive($this->base, TenantEntitlement::SOURCE_SUPER_ADMIN_FREE);
        $courtesy->forceFill(['expires_at' => null, 'interval' => null])->save();

        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->getJson('/api/config/subscription')
            ->assertStatus(200)
            ->assertJsonPath('data.current_plan.key', 'esencial')
            ->assertJsonPath('data.current_plan.source', TenantEntitlement::SOURCE_SUPER_ADMIN_FREE)
            ->assertJsonPath('data.current_plan.interval', null)
            ->assertJsonPath('data.current_plan.total', 0)
            ->assertJsonPath('data.current_plan.expires_at', null);
    }

    public function test_subscription_read_describes_the_current_plan_for_a_card_cluster(): void
    {
        $this->grantActive($this->base, TenantEntitlement::SOURCE_STRIPE);

        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->getJson('/api/config/subscription')
            ->assertStatus(200)
            ->assertJsonPath('data.current_plan.key', 'esencial')
            ->assertJsonPath('data.current_plan.source', TenantEntitlement::SOURCE_STRIPE)
            ->assertJsonPath('data.current_plan.total', 95000)
            ->assertJsonPath('data.is_multi_item', true);
    }

    public function test_subscription_read_reports_no_current_plan_without_any_active_entitlement(): void
    {
        $lapsed = $this->grantActive($this->base, TenantEntitlement::SOURCE_MANUAL);
        $lapsed->forceFill(['expires_at' => now()->subDay()])->save();

        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->getJson('/api/config/subscription')
            ->assertStatus(200)
            ->assertJsonPath('data.current_plan', null);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function grantActive(BillableModule $module, string $source): TenantEntitlement
    {
        return TenantEntitlement::create([
            'tenant_id' => $this->tenant->id,
            'billable_module_id' => $module->id,
            'billing_package_id' => $this->esencial->id,
            'source' => $source,
            'interval' => 'month',
            'quantity' => 1,
            'expires_at' => now()->addMonth(),
            'stripe_subscription_id' => $source === TenantEntitlement::SOURCE_STRIPE ? 'sub_change_plan' : null,
        ]);
    }

    private function seedActiveCashierSubscription(string $stripeId): Subscription
    {
        return Subscription::create([
            'user_id' => $this->owner->id,
            'type' => 'default',
            'stripe_id' => $stripeId,
            'stripe_status' => 'active',
            'stripe_price' => null,
            'quantity' => null,
        ]);
    }

    /**
     * Seed three PUBLIC plans and two billable modules:
     *   - esencial (current): base + package
     *   - pro (upgrade target): base + package
     *   - lite (partial target): base ONLY (used to prove the "missing module" guard)
     * The package price carries trial_days=7.
     */
    private function seedMatrix(): void
    {
        $this->base = BillableModule::create([
            'key' => 'base',
            'name' => 'Plan Base',
            'description' => 'Módulo base.',
            'sort_order' => 0,
            'is_active' => true,
            'is_base' => true,
        ]);

        $this->addon = BillableModule::create([
            'key' => 'package',
            'name' => 'Paquetería',
            'description' => 'Add-on con precio.',
            'sort_order' => 1,
            'is_active' => true,
            'is_base' => false,
        ]);

        $this->esencial = $this->makePlanRow('esencial', 0);
        $this->pro = $this->makePlanRow('pro', 1);
        $this->lite = $this->makePlanRow('lite', 2);

        $this->makePrice($this->esencial, $this->base, 95000, 'price_es_base');
        $this->makePrice($this->esencial, $this->addon, 12000, 'price_es_pkg', trialDays: 7);

        $this->makePrice($this->pro, $this->base, 150000, 'price_pro_base');
        $this->makePrice($this->pro, $this->addon, 20000, 'price_pro_pkg');

        $this->makePrice($this->lite, $this->base, 50000, 'price_lite_base');
    }

    private function makePlanRow(string $key, int $sortOrder): BillingPackage
    {
        return BillingPackage::create([
            'key' => $key,
            'name' => ucfirst($key),
            'description' => 'Plan '.$key,
            'sort_order' => $sortOrder,
            'is_active' => true,
            'is_public' => true,
            'suggested_unit_min' => 1,
            'suggested_unit_max' => 100,
        ]);
    }

    private function makePrice(BillingPackage $plan, BillableModule $module, int $amount, string $stripePriceId, ?int $trialDays = null): PlanModulePrice
    {
        PlanModule::updateOrCreate(
            ['billing_package_id' => $plan->id, 'billable_module_id' => $module->id],
            ['is_free' => false, 'trial_days' => $trialDays],
        );

        return PlanModulePrice::create([
            'billing_package_id' => $plan->id,
            'billable_module_id' => $module->id,
            'interval' => 'month',
            'unit_amount' => $amount,
            'currency' => 'MXN',
            'is_active' => true,
            'stripe_price_id' => $stripePriceId,
            'stripe_product_id' => 'prod_'.$stripePriceId,
        ]);
    }
}
