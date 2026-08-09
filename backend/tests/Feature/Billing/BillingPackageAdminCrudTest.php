<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModule;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * Super-admin billing v2 plan admin (S4): the billing_package CRUD + the
 * plan_module_price matrix editor (local upsert, never touching Stripe ids).
 *
 * Central-connection writes accumulate the fixed-key catalog tables across tests,
 * so — like every other central-touching billing test — this runs
 * transaction-less ($connectionsToTransact = []) and resets the billing tables in
 * tearDown via cleanBillingTables().
 *
 * Gates are token-ability based (TraitController::authorize / controller gate):
 * a '*' token is super-admin; a scoped token without billing_package abilities
 * is a stand-in for any tenant role.
 */
final class BillingPackageAdminCrudTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

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
    // billing_package CRUD
    // =========================================================================

    public function test_index_lists_v2_plans(): void
    {
        // Billing v2 S5: the legacy module-as-package rows and the LEGACY_KEYS
        // exclusion filter were removed — billing_package now holds ONLY v2 plans.
        $this->makePlan('esencial');

        $keys = collect($this->asSuperAdmin()->getJson('/api/billing_package')->assertOk()->json('data'))
            ->pluck('key')
            ->all();

        $this->assertContains('esencial', $keys);
    }

    public function test_store_creates_a_v2_plan_that_is_immediately_visible(): void
    {
        $payload = [
            'key' => 'corporativo',
            'name' => 'Corporativo',
            'description' => 'Plan para grandes condominios.',
            'is_public' => true,
            'is_active' => true,
            'sort_order' => 20,
            'suggested_unit_min' => 301,
            'suggested_unit_max' => 500,
        ];

        $this->asSuperAdmin()
            ->postJson('/api/billing_package', $payload)
            ->assertCreated()
            ->assertJsonPath('data.key', 'corporativo')
            ->assertJsonPath('data.suggested_unit_min', 301);

        $this->assertDatabaseHas('billing_package', ['key' => 'corporativo', 'name' => 'Corporativo']);

        // A freshly created plan (no price cells yet) must still show in its own CRUD.
        $keys = collect($this->asSuperAdmin()->getJson('/api/billing_package')->json('data'))->pluck('key')->all();
        $this->assertContains('corporativo', $keys);
    }

    public function test_store_requires_a_key(): void
    {
        $this->asSuperAdmin()
            ->postJson('/api/billing_package', ['name' => 'Sin key'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('key');
    }

    public function test_store_rejects_min_greater_than_max(): void
    {
        $this->asSuperAdmin()
            ->postJson('/api/billing_package', [
                'key' => 'rango_malo',
                'name' => 'Rango inválido',
                'suggested_unit_min' => 100,
                'suggested_unit_max' => 50,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('suggested_unit_max');
    }

    public function test_update_edits_a_plan(): void
    {
        $plan = $this->makePlan('esencial');

        $this->asSuperAdmin()
            ->putJson("/api/billing_package/{$plan->id}", ['name' => 'Esencial Plus', 'is_public' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Esencial Plus');

        $this->assertDatabaseHas('billing_package', [
            'id' => $plan->id,
            'name' => 'Esencial Plus',
            'is_public' => false,
        ]);
    }

    public function test_destroy_soft_deletes_a_plan(): void
    {
        $plan = $this->makePlan('esencial');

        $this->asSuperAdmin()
            ->deleteJson("/api/billing_package/{$plan->id}")
            ->assertOk();

        $this->assertSoftDeleted('billing_package', ['id' => $plan->id]);
        $this->assertNull(BillingPackage::find($plan->id));
    }

    public function test_index_is_forbidden_for_a_non_super_admin(): void
    {
        $this->makePlan('esencial');

        $this->asTenantUser(['read.unit'])
            ->getJson('/api/billing_package')
            ->assertStatus(403);
    }

    // =========================================================================
    // plan_module_price matrix editor
    // =========================================================================

    public function test_price_matrix_returns_cells_and_all_active_modules(): void
    {
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', isBase: true);
        $this->makeBillableModule('package', 'Paquetería');

        $this->makePlanModulePrice($plan, $base, 'month', 95000, ['stripe_price_id' => 'price_synced_base']);

        $data = $this->asSuperAdmin()
            ->getJson("/api/billing/plan/{$plan->id}/price-matrix")
            ->assertOk()
            ->json('data');

        $this->assertSame($plan->key, $data['plan']['key']);
        $this->assertSame(['month', 'year'], $data['intervals']);
        $this->assertSame((int) config('billing.trial_days'), $data['default_trial_days']);

        $byModule = collect($data['modules'])->keyBy('key');
        $this->assertTrue($byModule->has('base'));
        $this->assertTrue($byModule->has('package'));

        // v2: is_free/trial_days live on the module row (no plan_module row was
        // ever created for 'base' here, so both read as their drift defaults).
        $this->assertFalse($byModule['base']['is_free']);
        $this->assertNull($byModule['base']['trial_days']);

        // Base has a month cell (with read-only stripe id) and no year cell.
        $this->assertSame(95000, $byModule['base']['cells']['month']['unit_amount']);
        $this->assertSame('price_synced_base', $byModule['base']['cells']['month']['stripe_price_id']);
        $this->assertNull($byModule['base']['cells']['year']);

        // The active module without any cell is still listed so the UI can add one.
        $this->assertNull($byModule['package']['cells']['month']);
        $this->assertNull($byModule['package']['cells']['year']);
    }

    public function test_price_matrix_bulk_upsert_creates_and_updates_without_touching_stripe(): void
    {
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', isBase: true);
        $package = $this->makeBillableModule('package', 'Paquetería');

        // Existing base month cell already synced to Stripe.
        $existing = $this->makePlanModulePrice($plan, $base, 'month', 95000, [
            'stripe_price_id' => 'price_existing',
            'stripe_product_id' => 'prod_existing',
        ]);

        $this->asSuperAdmin()
            ->putJson("/api/billing/plan/{$plan->id}/price-matrix", [
                'modules' => [
                    [
                        'billable_module_id' => $base->id,
                        'is_free' => false,
                        'cells' => [
                            'month' => ['unit_amount' => 99999],
                            'year' => ['unit_amount' => 1139886],
                        ],
                    ],
                    [
                        'billable_module_id' => $package->id,
                        'is_free' => false,
                        'trial_days' => 14,
                        'cells' => [
                            'month' => ['unit_amount' => 5000],
                            'year' => ['unit_amount' => 57000],
                        ],
                    ],
                ],
            ])
            ->assertOk();

        // Updated existing cell: new amount, Stripe ids PRESERVED (not touched here).
        $existing->refresh();
        $this->assertSame(99999, $existing->unit_amount);
        $this->assertSame('price_existing', $existing->stripe_price_id);
        $this->assertSame('prod_existing', $existing->stripe_product_id);

        // Created new cell: no Stripe ids, defaults applied.
        $created = PlanModulePrice::query()
            ->where('billing_package_id', $plan->id)
            ->where('billable_module_id', $package->id)
            ->where('interval', 'month')
            ->firstOrFail();

        $this->assertSame(5000, $created->unit_amount);
        $this->assertNull($created->stripe_price_id);
        $this->assertTrue($created->is_active);

        // is_free/trial_days are now a (plan, module) fact on plan_module, not
        // columns on the price row.
        $packageFlags = PlanModule::query()
            ->where('billing_package_id', $plan->id)
            ->where('billable_module_id', $package->id)
            ->firstOrFail();

        $this->assertFalse((bool) $packageFlags->is_free);
        $this->assertSame(14, $packageFlags->trial_days);
    }

    /**
     * v2: is_free/trial_days are a (plan, module) fact submitted ONCE per
     * module entry — the mismatched-across-intervals state the v1 contract
     * had to validate against is now structurally unrepresentable.
     */
    public function test_price_matrix_round_trips_module_level_is_free_and_trial_days(): void
    {
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', isBase: true);

        $this->asSuperAdmin()
            ->putJson("/api/billing/plan/{$plan->id}/price-matrix", [
                'modules' => [
                    [
                        'billable_module_id' => $base->id,
                        'is_free' => true,
                        'trial_days' => 30,
                        'cells' => [
                            'month' => ['unit_amount' => 0],
                            'year' => ['unit_amount' => 0],
                        ],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.modules.0.is_free', true)
            ->assertJsonPath('data.modules.0.trial_days', 30);

        $planModule = PlanModule::query()
            ->where('billing_package_id', $plan->id)
            ->where('billable_module_id', $base->id)
            ->firstOrFail();

        $this->assertTrue((bool) $planModule->is_free);
        $this->assertSame(30, $planModule->trial_days);

        // Both interval cells exist and carry no flag columns of their own.
        $rows = PlanModulePrice::query()
            ->where('billing_package_id', $plan->id)
            ->where('billable_module_id', $base->id)
            ->get()
            ->keyBy('interval');

        $this->assertSame(0, $rows['month']->unit_amount);
        $this->assertSame(0, $rows['year']->unit_amount);
    }

    /**
     * A stale v1 SPA bundle sending per-cell flags must fail LOUD (422), not
     * have its flags silently dropped and module-level is_free default to
     * false.
     */
    public function test_price_matrix_rejects_legacy_per_cell_flag_shape(): void
    {
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', isBase: true);

        $this->asSuperAdmin()
            ->putJson("/api/billing/plan/{$plan->id}/price-matrix", [
                'modules' => [
                    [
                        'billable_module_id' => $base->id,
                        'is_free' => false,
                        'cells' => [
                            'month' => ['unit_amount' => 95000, 'is_free' => true],
                            'year' => ['unit_amount' => 1083750],
                        ],
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('modules.0.cells.month.is_free');

        $this->assertSame(0, PlanModulePrice::query()->where('billing_package_id', $plan->id)->count());
    }

    public function test_price_matrix_is_forbidden_for_a_non_super_admin(): void
    {
        $plan = $this->makePlan('esencial');

        $this->asTenantUser(['read.unit'])
            ->getJson("/api/billing/plan/{$plan->id}/price-matrix")
            ->assertStatus(403);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeUser(): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Admin '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);
    }

    /**
     * @param  array<int, string>  $abilities
     */
    private function request(array $abilities): self
    {
        $token = $this->makeUser()->createToken('t', $abilities)->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function asSuperAdmin(): self
    {
        return $this->request(['*']);
    }

    /**
     * @param  array<int, string>  $abilities
     */
    private function asTenantUser(array $abilities): self
    {
        return $this->request($abilities);
    }
}
