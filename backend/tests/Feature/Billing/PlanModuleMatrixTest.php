<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Database\Seeders\ModuleTableSeeder;
use Database\Seeders\ResetPermissionTableSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\SubscriptionPlan\Database\Seeders\PlanModuleMatrixSeeder;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModule;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Services\MultiItemSubscriptionService;
use Tests\TestCase;

/**
 * Verifies the billing catalog seeder after the 2026-07 collapse: ONE sellable
 * module (`base`) anchoring every platform module, the five priced plans, the
 * price-less consultative plan, and the plan_module_price unique constraint.
 *
 * Amounts are integer minor units (cents).
 */
final class PlanModuleMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The pivot maps the base billable module to real platform `module` rows
        // by name (Cobros/Avisos/Control de Accesos/Egresos/Proveedores/Cuentas
        // Bancarias + el organización de Paquetería), so seed them.
        $this->seed(ModuleTableSeeder::class);
        $this->seed(PlanModuleMatrixSeeder::class);
    }

    public function test_base_billable_module_is_flagged_as_base(): void
    {
        $base = BillableModule::where('key', BillableModule::KEY_BASE)->first();

        $this->assertNotNull($base);
        $this->assertTrue($base->is_base);

        // Exactly one billable module is the base.
        $this->assertSame(1, BillableModule::where('is_base', true)->count());
    }


    public function test_base_is_the_only_sellable_module(): void
    {
        // Paquetería y Amenidades dejaron de venderse por separado.
        $this->assertSame(['base'], BillableModule::pluck('key')->all());
    }


    public function test_reset_permission_seeder_skips_matrix_when_absent(): void
    {
        // Los tests transaction-less siembran ResetPermissionTableSeeder solo
        // para permisos y luego crean sus propios fixtures de billing: el
        // self-heal NO debe crear filas de billing cuando la matriz no existe.
        DB::table('plan_module_price')->delete();
        DB::table('billable_module_system_module')->delete();
        DB::table('billable_module')->delete();

        $this->seed(ResetPermissionTableSeeder::class);

        $this->assertSame(0, DB::table('billable_module')->count());
    }

    public function test_matrix_has_base_month_price_of_45000_for_basico(): void
    {
        $plan = BillingPackage::where('key', 'basico')->first();
        $baseModule = BillableModule::where('key', BillableModule::KEY_BASE)->first();

        $this->assertNotNull($plan);
        $this->assertSame(1, $plan->suggested_unit_min);
        $this->assertSame(25, $plan->suggested_unit_max);

        $price = PlanModulePrice::query()
            ->where('billing_package_id', $plan->id)
            ->where('billable_module_id', $baseModule->id)
            ->where('interval', 'month')
            ->first();

        $this->assertNotNull($price);
        $this->assertSame(45000, $price->unit_amount);
    }

    public function test_seeder_writes_one_plan_module_row_per_plan_for_the_base_module(): void
    {
        $baseModule = BillableModule::where('key', BillableModule::KEY_BASE)->first();
        $keys = ['basico', 'inicial', 'esencial', 'avanzado', 'premium'];

        $plans = BillingPackage::whereIn('key', $keys)->get();
        $this->assertCount(5, $plans);

        foreach ($plans as $plan) {
            $planModule = PlanModule::query()
                ->where('billing_package_id', $plan->id)
                ->where('billable_module_id', $baseModule->id)
                ->first();

            $this->assertNotNull($planModule, "missing plan_module row for plan '{$plan->key}'");
            $this->assertFalse($planModule->is_free);
            $this->assertNull($planModule->trial_days);
        }
    }

    public function test_plan_module_price_table_no_longer_carries_flag_columns(): void
    {
        $columns = Schema::getColumnListing('plan_module_price');

        $this->assertNotContains('is_free', $columns);
        $this->assertNotContains('trial_days', $columns);
    }

    // (Legacy PricingService "no leak into public catalog" assertion removed with
    // the legacy banded catalog in billing v2 S5.)

    public function test_all_five_plans_are_seeded_active(): void
    {
        $keys = ['basico', 'inicial', 'esencial', 'avanzado', 'premium'];

        $plans = BillingPackage::whereIn('key', $keys)->get();

        $this->assertCount(5, $plans);
        $this->assertTrue($plans->every(fn (BillingPackage $p): bool => $p->is_active === true));
    }

    public function test_contact_sales_plan_is_public_active_and_price_less(): void
    {
        $plan = BillingPackage::where('key', 'a_medida')->first();

        $this->assertNotNull($plan);
        $this->assertTrue($plan->is_active);
        $this->assertTrue($plan->is_public);
        $this->assertSame(301, $plan->suggested_unit_min);
        // No upper bound: that is what makes it the "more than 300 casas" tier.
        $this->assertNull($plan->suggested_unit_max);
        $this->assertSame(0, PlanModulePrice::where('billing_package_id', $plan->id)->count());
    }


    public function test_collapse_migration_is_safe_when_the_retired_modules_are_absent(): void
    {
        $migration = require database_path('../Modules/SubscriptionPlan/database/migrations/2026_07_19_000000_collapse_billable_modules_into_base.php');
        $migration->up();

        $this->assertSame(1, BillableModule::count());
    }

    public function test_plan_module_price_unique_constraint_blocks_duplicates(): void
    {
        $plan = BillingPackage::where('key', 'basico')->first();
        $baseModule = BillableModule::where('key', BillableModule::KEY_BASE)->first();

        $this->expectException(QueryException::class);

        // A second (plan, module, interval) row must violate the unique index.
        PlanModulePrice::create([
            'billing_package_id' => $plan->id,
            'billable_module_id' => $baseModule->id,
            'interval' => 'month',
            'unit_amount' => 99999,
            'currency' => 'MXN',
            'is_active' => true,
        ]);
    }

    public function test_plan_module_unique_constraint_blocks_duplicates(): void
    {
        $plan = BillingPackage::where('key', 'basico')->first();
        $baseModule = BillableModule::where('key', BillableModule::KEY_BASE)->first();

        $this->expectException(QueryException::class);

        // The seeder already created the (plan, module) row: a second one must
        // violate the unique index — the flags fact exists at most once per pair.
        PlanModule::create([
            'billing_package_id' => $plan->id,
            'billable_module_id' => $baseModule->id,
            'is_free' => false,
            'trial_days' => null,
        ]);
    }

    public function test_reseeding_preserves_stripe_ids_synced_by_a_prior_sync(): void
    {
        // D3 — the old upsertPrice() nulled stripe_price_id/stripe_product_id on
        // EVERY re-seed, and start.sh runs db:seed on every deploy. A synced
        // catalog re-seeded after deploy silently lost its Stripe ids, turning
        // every checkout into a 422 no_billable_price.
        $plan = BillingPackage::where('key', 'basico')->first();
        $baseModule = BillableModule::where('key', BillableModule::KEY_BASE)->first();

        $price = PlanModulePrice::query()
            ->where('billing_package_id', $plan->id)
            ->where('billable_module_id', $baseModule->id)
            ->where('interval', 'month')
            ->first();

        $price->update([
            'stripe_price_id' => 'price_basico_base_month',
            'stripe_product_id' => 'prod_base',
        ]);

        $this->seed(PlanModuleMatrixSeeder::class);

        $price->refresh();

        $this->assertSame('price_basico_base_month', $price->stripe_price_id);
        $this->assertSame('prod_base', $price->stripe_product_id);
        // The seeder still owns unit_amount on every re-seed.
        $this->assertSame(45000, $price->unit_amount);

        // Checkout resolution reads exactly this id — proves the price is still
        // billable after the re-seed, not merely that the column is untouched.
        $priceIds = app(MultiItemSubscriptionService::class)
            ->resolveStripePriceIds('basico', ['base'], 'month');

        $this->assertSame(['price_basico_base_month'], $priceIds);
    }

    public function test_reseeding_leaves_stripe_ids_null_on_a_never_synced_price(): void
    {
        // A fresh (never-synced) price row must still default to null — the fix
        // only stops CLEARING an already-set id, it must not start faking one.
        $plan = BillingPackage::where('key', 'basico')->first();
        $baseModule = BillableModule::where('key', BillableModule::KEY_BASE)->first();

        $this->seed(PlanModuleMatrixSeeder::class);

        $price = PlanModulePrice::query()
            ->where('billing_package_id', $plan->id)
            ->where('billable_module_id', $baseModule->id)
            ->where('interval', 'month')
            ->first();

        $this->assertNull($price->stripe_price_id);
        $this->assertNull($price->stripe_product_id);
    }
}
