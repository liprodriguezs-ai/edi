<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModule;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;
use Tests\Feature\E2E\ClusterScenario;
use Tests\TestCase;

/**
 * plan-downgrade-guard: closes the exit door left open by unit-limit-enforcement.
 * A cluster admin can no longer self-serve downgrade to a billing plan whose unit
 * band is smaller than the tenant's current active unit count; the super-admin
 * preferential swap deliberately bypasses this guard (REQ-03).
 *
 * ONE tenant (from ClusterScenario::buildScenario(), 6 active units) is reused
 * across every scenario, including the REQ-03 bypass proof — that is the point:
 * the same over-band tenant is blocked via self-serve and unblocked via the
 * preferential swap.
 *
 * Plan bands (all offer the tenant's single contracted 'base' module, MANUAL
 * billing reality — no Cashier subscription seeded):
 *   - current (max 200, sort 0): the tenant's plan at the start of every test.
 *     Also the ONLY band that contains 6 units among the PUBLIC plans sorted
 *     first, so it is the "smallest fitting plan" surfaced by the guard.
 *   - small   (max 5,   sort 1): under the tenant's 6 active units — blocks.
 *   - exact   (max 6,   sort 2): exactly the tenant's 6 active units — fits (REQ-05).
 *   - big     (max 300, sort 3): comfortably above — upgrade target (REQ-04).
 *   - pref    (max 5, is_public=false, sort 4): hidden preferential plan with the
 *     SAME cap as `small` — proves the super-admin bypass on the identical band
 *     that blocks the cluster admin (REQ-03).
 */
final class PlanDowngradeGuardTest extends TestCase
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

    private BillableModule $base;

    private BillingPackage $current;

    private BillingPackage $small;

    private BillingPackage $exact;

    private BillingPackage $big;

    private BillingPackage $pref;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scn = $this->buildScenario();
        $this->tenant = $this->scn['tenant'];

        // ClusterScenario's tenant defaults to payment_method=card (Tenant migration
        // default). No Cashier subscription is seeded in this test, and
        // PreferentialSwapController::resolveContext REQUIRES one for card-paying
        // tenants — force transfer (MANUAL) so both actors exercise the same,
        // Stripe-free billing reality.
        $this->tenant->update(['payment_method' => Tenant::PAYMENT_METHOD_TRANSFER]);

        $this->ownerEmail = 'owner.'.$this->scn['emails']['laura'];
        $this->owner = $this->makeAppUser($this->tenant, 'Owner Encinos', $this->ownerEmail, Role::SLUG_ACCOUNT_OWNER);

        $this->seedPlanMatrix();

        TenantEntitlement::create([
            'tenant_id' => $this->tenant->id,
            'billable_module_id' => $this->base->id,
            'billing_package_id' => $this->current->id,
            'source' => TenantEntitlement::SOURCE_MANUAL,
            'interval' => 'month',
            'quantity' => 1,
            'expires_at' => now()->addMonth(),
        ]);

        $this->tenant->update(['billing_package_id' => $this->current->id]);
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

        parent::tearDown();
    }

    // =========================================================================
    // REQ-01 — cluster-admin apply is blocked by band mismatch
    // =========================================================================

    public function test_apply_over_band_target_is_rejected_and_plan_is_unchanged(): void
    {
        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/config/subscription/change-billing-plan', ['plan_key' => 'small'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'UNIT_LIMIT_EXCEEDED')
            ->assertJsonPath('plan_name', 'Small')
            ->assertJsonPath('unit_limit', 5)
            ->assertJsonPath('active_units', 6)
            ->assertJsonPath('suggested_plan_name', 'Current')
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, '6 unidades activas')
                && str_contains($message, 'Small')
                && str_contains($message, 'Current'));

        $this->assertSame($this->current->id, $this->tenant->fresh()->billing_package_id, 'The plan must remain unchanged on rejection');
    }

    // =========================================================================
    // REQ-04 — upgrades and same-band changes are never blocked
    // =========================================================================

    public function test_apply_an_upgrade_succeeds(): void
    {
        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/config/subscription/change-billing-plan', ['plan_key' => 'big'])
            ->assertStatus(200)
            ->assertJsonPath('data.billing_package_id', $this->big->id);
    }

    public function test_apply_a_same_band_reselect_succeeds(): void
    {
        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/config/subscription/change-billing-plan', ['plan_key' => 'current'])
            ->assertStatus(200)
            ->assertJsonPath('data.billing_package_id', $this->current->id);
    }

    // =========================================================================
    // REQ-05 — exactly-at-the-limit fits
    // =========================================================================

    public function test_apply_an_exactly_at_the_limit_target_succeeds(): void
    {
        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/config/subscription/change-billing-plan', ['plan_key' => 'exact'])
            ->assertStatus(200)
            ->assertJsonPath('data.billing_package_id', $this->exact->id);
    }

    // =========================================================================
    // REQ-02 / REQ-07 — preview reports the block before apply
    // =========================================================================

    public function test_preview_over_band_target_reports_the_unit_limit_warning(): void
    {
        $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/config/subscription/change-billing-plan/preview', ['plan_key' => 'small'])
            ->assertStatus(200)
            ->assertJsonPath('data.manual', true)
            ->assertJsonPath('data.unit_limit_warning.plan_name', 'Small')
            ->assertJsonPath('data.unit_limit_warning.unit_limit', 5)
            ->assertJsonPath('data.unit_limit_warning.active_units', 6)
            ->assertJsonPath('data.unit_limit_warning.suggested_plan_name', 'Current')
            ->assertJsonPath('data.unit_limit_warning.message', fn (string $message): bool => str_contains($message, '6 unidades activas'));
    }

    public function test_preview_a_fitting_target_omits_the_unit_limit_warning(): void
    {
        $response = $this->as($this->login($this->ownerEmail), $this->tenant)
            ->postJson('/api/config/subscription/change-billing-plan/preview', ['plan_key' => 'exact'])
            ->assertStatus(200);

        $response->assertJsonMissingPath('data.unit_limit_warning');
    }

    // =========================================================================
    // REQ-03 — super admin bypasses via preferential swap (deliberate)
    // =========================================================================

    public function test_super_admin_preferential_swap_to_the_same_capped_band_succeeds_on_the_over_band_tenant(): void
    {
        $super = User::create([
            'name' => 'Super QA',
            'email' => 'super.'.$this->scn['emails']['laura'],
            'password' => bcrypt('secret1234'),
            'auth_active' => true,
        ]);
        $token = $super->createToken('super', ['*'])->plainTextToken;

        // Preview bypasses the guard too — same over-band tenant, same cap as
        // the self-serve `small` target that was rejected above.
        $this->as($token, $this->tenant)
            ->postJson("/api/tenant/{$this->tenant->id}/preferential-swap/preview", ['to_plan_key' => 'pref'])
            ->assertStatus(200)
            ->assertJsonPath('data.manual', true);

        $this->as($token, $this->tenant)
            ->postJson("/api/tenant/{$this->tenant->id}/preferential-swap", ['to_plan_key' => 'pref'])
            ->assertStatus(200)
            ->assertJsonPath('data.billing_package_id', $this->pref->id);

        $this->assertSame(
            $this->pref->id,
            $this->tenant->fresh()->billing_package_id,
            'Super admin must be able to swap the tenant into a band under its 6 active units'
        );
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function seedPlanMatrix(): void
    {
        $this->base = BillableModule::create([
            'key' => 'base',
            'name' => 'Plan Base',
            'description' => 'Módulo base.',
            'sort_order' => 0,
            'is_active' => true,
            'is_base' => true,
        ]);

        $this->current = $this->makePlanRow('current', 200, 0);
        $this->small = $this->makePlanRow('small', 5, 1);
        $this->exact = $this->makePlanRow('exact', 6, 2);
        $this->big = $this->makePlanRow('big', 300, 3);
        $this->pref = $this->makePlanRow('pref', 5, 4, isPublic: false);

        foreach ([$this->current, $this->small, $this->exact, $this->big, $this->pref] as $plan) {
            $this->makePrice($plan, 95000);
        }
    }

    private function makePlanRow(string $key, ?int $max, int $sortOrder, bool $isPublic = true): BillingPackage
    {
        return BillingPackage::create([
            'key' => $key,
            'name' => ucfirst($key),
            'description' => 'Plan '.$key,
            'sort_order' => $sortOrder,
            'is_active' => true,
            'is_public' => $isPublic,
            'suggested_unit_min' => $max !== null ? 1 : null,
            'suggested_unit_max' => $max,
        ]);
    }

    private function makePrice(BillingPackage $plan, int $amount): PlanModulePrice
    {
        PlanModule::updateOrCreate(
            ['billing_package_id' => $plan->id, 'billable_module_id' => $this->base->id],
            ['is_free' => false, 'trial_days' => null],
        );

        return PlanModulePrice::create([
            'billing_package_id' => $plan->id,
            'billable_module_id' => $this->base->id,
            'interval' => 'month',
            'unit_amount' => $amount,
            'currency' => 'MXN',
            'is_active' => true,
        ]);
    }
}
