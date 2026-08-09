<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Database\Seeders\ModuleTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Modules\SubscriptionPlan\Database\Seeders\PlanModuleMatrixSeeder;
use Modules\SubscriptionPlan\Exceptions\CheckoutUnavailableException;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModule;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Services\PlanPricingService;
use Tests\TestCase;

/**
 * Verifies PlanPricingService and its public endpoints against the seeded
 * catalog: the fixed price per (plan × module × interval), the mandatory base
 * module, the default "quote the whole plan" behaviour when no module array is
 * sent, and the contact-sales tier (price-less plan → 422 contact_sales).
 *
 * Reference amount (integer minor units / cents): base module month on plan
 * `basico` = 45000. There is a single sellable module since the 2026-07 collapse.
 */
final class PlanPricingTest extends TestCase
{
    use RefreshDatabase;

    private const BASICO_BASE_MONTH = 45000;

    private const PREMIUM_BASE_MONTH = 210000;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(PlanPricingService::PLAN_CATALOG_CACHE_KEY);

        // The pivot maps the base billable module to real platform `module` rows
        // by name (Cobros/Avisos/Control de Accesos/Paquetería…), so seed them.
        $this->seed(ModuleTableSeeder::class);
        $this->seed(PlanModuleMatrixSeeder::class);
    }

    private function service(): PlanPricingService
    {
        return app(PlanPricingService::class);
    }

    // =========================================================================
    // quoteByPlan
    // =========================================================================

    public function test_quote_base_only_equals_45000_for_basico(): void
    {
        $quote = $this->service()->quoteByPlan('basico', ['base'], 'month');

        $this->assertSame(self::BASICO_BASE_MONTH, $quote['total']);
        $this->assertSame('basico', $quote['plan']['key']);
        $this->assertSame('month', $quote['interval']);
        $this->assertSame('MXN', $quote['currency']);
        $this->assertCount(1, $quote['lines']);
        $this->assertSame('base', $quote['lines'][0]['module_key']);
        $this->assertSame(self::BASICO_BASE_MONTH, $quote['lines'][0]['unit_amount']);
        $this->assertFalse($quote['lines'][0]['is_free']);
    }

    public function test_quote_without_modules_defaults_to_the_whole_plan(): void
    {
        // Modules are no longer a user-facing choice: an empty selection means
        // "everything this plan prices".
        $quote = $this->service()->quoteByPlan('basico', [], 'month');

        $this->assertSame(self::BASICO_BASE_MONTH, $quote['total']);
        $this->assertSame(['base'], array_column($quote['lines'], 'module_key'));
    }

    public function test_quote_requires_base_module_when_modules_are_explicit(): void
    {
        // A priced-but-not-base module alone is still rejected (back-compat path).
        $ghost = BillableModule::create([
            'key' => 'ghost',
            'name' => 'Módulo Fantasma',
            'description' => 'Precio propio, no es base.',
            'sort_order' => 99,
            'is_active' => true,
            'is_base' => false,
        ]);

        $basico = BillingPackage::where('key', 'basico')->firstOrFail();

        PlanModule::updateOrCreate(
            ['billing_package_id' => $basico->id, 'billable_module_id' => $ghost->id],
            ['is_free' => false, 'trial_days' => null],
        );

        PlanModulePrice::create([
            'billing_package_id' => $basico->id,
            'billable_module_id' => $ghost->id,
            'interval' => 'month',
            'unit_amount' => 1000,
            'currency' => 'MXN',
            'is_active' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);
        // F-19: the user-facing copy no longer mentions "módulo base" — the product
        // sells a PLAN. The rule (a quote must carry the base line) is unchanged.
        $this->expectExceptionMessage('no corresponde al plan');

        $this->service()->quoteByPlan('basico', ['ghost'], 'month');
    }

    public function test_quote_rejects_module_not_offered_by_plan(): void
    {
        // A billable module with NO price row for any plan.
        BillableModule::create([
            'key' => 'ghost',
            'name' => 'Módulo Fantasma',
            'description' => 'Sin precio en ningún plan.',
            'sort_order' => 99,
            'is_active' => true,
            'is_base' => false,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("no está disponible en el plan 'basico'");

        $this->service()->quoteByPlan('basico', ['base', 'ghost'], 'month');
    }

    public function test_quote_rejects_unknown_plan(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->quoteByPlan('does-not-exist', ['base'], 'month');
    }

    public function test_quote_against_contact_sales_plan_throws_contact_sales(): void
    {
        try {
            $this->service()->quoteByPlan('a_medida', [], 'month');
            $this->fail('A price-less plan must not be quotable.');
        } catch (CheckoutUnavailableException $e) {
            $this->assertSame(CheckoutUnavailableException::REASON_CONTACT_SALES, $e->reason());
        }
    }

    // =========================================================================
    // planForUnits
    // =========================================================================

    public function test_plan_for_units_resolves_the_matching_band(): void
    {
        $this->assertSame('basico', $this->service()->planForUnits(10)->key);
        $this->assertSame('premium', $this->service()->planForUnits(300)->key);
    }

    public function test_plan_for_units_above_the_highest_band_returns_the_contact_sales_plan(): void
    {
        // It used to clamp to premium; a organization past the price list now belongs
        // to consultative sales.
        $this->assertSame('a_medida', $this->service()->planForUnits(301)->key);
        $this->assertSame('a_medida', $this->service()->planForUnits(5000)->key);
    }

    public function test_max_billable_units_is_the_highest_priced_band(): void
    {
        $this->assertSame(300, $this->service()->maxBillableUnits());
    }

    // =========================================================================
    // priceForModule
    // =========================================================================

    public function test_price_for_module_returns_fixed_amount(): void
    {
        $plan = BillingPackage::where('key', 'basico')->firstOrFail();
        $baseModule = BillableModule::where('key', BillableModule::KEY_BASE)->firstOrFail();

        $this->assertSame(
            self::BASICO_BASE_MONTH,
            $this->service()->priceForModule($plan, $baseModule, 'month'),
        );
    }

    public function test_price_for_module_returns_zero_for_free_row(): void
    {
        $plan = BillingPackage::where('key', 'basico')->firstOrFail();
        $baseModule = BillableModule::where('key', BillableModule::KEY_BASE)->firstOrFail();

        PlanModule::updateOrCreate(
            ['billing_package_id' => $plan->id, 'billable_module_id' => $baseModule->id],
            ['is_free' => true],
        );

        $this->assertSame(0, $this->service()->priceForModule($plan, $baseModule, 'month'));
    }

    // =========================================================================
    // planCatalog
    // =========================================================================

    public function test_plan_catalog_lists_the_five_priced_plans_plus_the_contact_sales_tier(): void
    {
        $catalog = $this->service()->planCatalog();

        $this->assertSame('MXN', $catalog['currency']);
        $this->assertSame(300, $catalog['max_billable_units']);
        $this->assertSame(config('billing.contact_email'), $catalog['contact']['email']);
        $this->assertArrayHasKey('whatsapp', $catalog['contact']);

        $keys = array_column($catalog['plans'], 'key');
        $this->assertEqualsCanonicalizing(
            ['basico', 'inicial', 'esencial', 'avanzado', 'premium', 'a_medida'],
            $keys,
        );

        $basico = collect($catalog['plans'])->firstWhere('key', 'basico');
        $this->assertSame(1, $basico['suggested_unit_min']);
        $this->assertSame(25, $basico['suggested_unit_max']);
        $this->assertFalse($basico['is_contact_sales']);

        // The PLAN-level price is what the frontends render.
        $this->assertSame(self::BASICO_BASE_MONTH, ((array) $basico['prices'])['month']['unit_amount']);
        $this->assertSame('MXN', ((array) $basico['prices'])['month']['currency']);

        $baseModule = collect($basico['modules'])->firstWhere('module_key', 'base');
        $this->assertNotNull($baseModule);
        $this->assertTrue($baseModule['is_base']);
        $this->assertSame(self::BASICO_BASE_MONTH, $baseModule['prices']['month']['unit_amount']);
        $this->assertNotEmpty($baseModule['description']);
        $this->assertNotEmpty($baseModule['features']);

        // Paquetería y amenidades ahora viven dentro del plan base.
        $this->assertSame(['base'], array_column($basico['modules'], 'module_key'));
    }

    public function test_contact_sales_plan_is_exposed_with_no_modules_and_an_empty_price_object(): void
    {
        $catalog = $this->service()->planCatalog();

        $aMedida = collect($catalog['plans'])->firstWhere('key', 'a_medida');

        $this->assertNotNull($aMedida);
        $this->assertTrue($aMedida['is_contact_sales']);
        $this->assertSame(301, $aMedida['suggested_unit_min']);
        $this->assertNull($aMedida['suggested_unit_max']);
        $this->assertSame([], $aMedida['modules']);
        // MUST be an object, never [] — the frontends parse it with an object schema.
        $this->assertIsObject($aMedida['prices']);
        $this->assertSame('{}', json_encode($aMedida['prices']));
    }

    public function test_plan_catalog_excludes_a_retired_plan_even_when_it_still_has_active_prices(): void
    {
        // F-24 — is_active=false is the ONLY way to retire a plan. A retired plan
        // that still carries active price rows used to stay in the catalog with a
        // full price while plan-quote rejected it with 422 (dead, but visible).
        BillingPackage::where('key', 'esencial')->firstOrFail()->update(['is_active' => false]);

        Cache::forget(PlanPricingService::PLAN_CATALOG_CACHE_KEY);

        $keys = array_column($this->service()->planCatalog()['plans'], 'key');

        $this->assertNotContains('esencial', $keys);

        // The super-admin variant must not offer it either — every write path
        // (preferential swap, provisioning) requires an ACTIVE plan.
        $hiddenKeys = array_column($this->service()->planCatalog(includeHidden: true)['plans'], 'key');

        $this->assertNotContains('esencial', $hiddenKeys);
    }

    public function test_plan_catalog_exposes_is_public_on_every_plan(): void
    {
        // The preferential-swap dropdown needs this to stop guessing which plans
        // are hidden variants (PreferentialSwapController requires is_public=false).
        $hidden = BillingPackage::create([
            'key' => 'preferencial_x',
            'name' => 'Preferencial X',
            'description' => 'Variante oculta.',
            'sort_order' => 90,
            'is_active' => true,
            'is_public' => false,
            'suggested_unit_min' => 1,
            'suggested_unit_max' => 25,
        ]);

        $baseModule = BillableModule::where('key', BillableModule::KEY_BASE)->firstOrFail();

        PlanModule::updateOrCreate(
            ['billing_package_id' => $hidden->id, 'billable_module_id' => $baseModule->id],
            ['is_free' => false, 'trial_days' => null],
        );

        PlanModulePrice::create([
            'billing_package_id' => $hidden->id,
            'billable_module_id' => $baseModule->id,
            'interval' => 'month',
            'unit_amount' => 30000,
            'currency' => 'MXN',
            'is_active' => true,
        ]);

        Cache::forget(PlanPricingService::PLAN_CATALOG_CACHE_KEY);

        $public = collect($this->service()->planCatalog()['plans']);

        $this->assertTrue($public->every(fn (array $plan): bool => array_key_exists('is_public', $plan)));
        $this->assertTrue($public->firstWhere('key', 'basico')['is_public']);
        $this->assertNull($public->firstWhere('key', 'preferencial_x'));

        $withHidden = collect($this->service()->planCatalog(includeHidden: true)['plans']);

        $this->assertFalse($withHidden->firstWhere('key', 'preferencial_x')['is_public']);
        $this->assertTrue($withHidden->firstWhere('key', 'basico')['is_public']);
    }

    public function test_plan_catalog_exposes_is_purchasable_false_when_no_price_is_stripe_synced(): void
    {
        // D4 — the seeded matrix carries price rows but none synced to Stripe yet
        // (stripe_price_id null until billing:sync-stripe runs).
        $catalog = $this->service()->planCatalog();
        $basico = collect($catalog['plans'])->firstWhere('key', 'basico');

        $this->assertArrayHasKey('is_purchasable', $basico);
        $this->assertFalse($basico['is_purchasable']);
    }

    public function test_plan_catalog_exposes_is_purchasable_true_once_a_price_is_stripe_synced(): void
    {
        $plan = BillingPackage::where('key', 'basico')->firstOrFail();
        $baseModule = BillableModule::where('key', BillableModule::KEY_BASE)->firstOrFail();

        PlanModulePrice::query()
            ->where('billing_package_id', $plan->id)
            ->where('billable_module_id', $baseModule->id)
            ->where('interval', 'month')
            ->update(['stripe_price_id' => 'price_basico_base_month']);

        Cache::forget(PlanPricingService::PLAN_CATALOG_CACHE_KEY);

        $catalog = $this->service()->planCatalog();
        $basico = collect($catalog['plans'])->firstWhere('key', 'basico');

        $this->assertTrue($basico['is_purchasable']);
    }

    public function test_contact_sales_plan_is_never_purchasable(): void
    {
        // is_purchasable is distinct from is_contact_sales: a_medida is
        // deliberately price-less, not a Stripe-sync gap.
        $catalog = $this->service()->planCatalog();
        $aMedida = collect($catalog['plans'])->firstWhere('key', 'a_medida');

        $this->assertFalse($aMedida['is_purchasable']);
    }

    public function test_premium_plan_price_matches_its_seeded_band(): void
    {
        $catalog = $this->service()->planCatalog();
        $premium = collect($catalog['plans'])->firstWhere('key', 'premium');

        $this->assertSame(self::PREMIUM_BASE_MONTH, ((array) $premium['prices'])['month']['unit_amount']);
    }

    public function test_plan_catalog_exposes_empty_array_for_module_with_null_features(): void
    {
        $plan = BillingPackage::where('key', 'basico')->firstOrFail();

        $ghost = BillableModule::create([
            'key' => 'ghost',
            'name' => 'Módulo Fantasma',
            'description' => 'Sin features definidas.',
            'features' => null,
            'sort_order' => 99,
            'is_active' => true,
            'is_base' => false,
        ]);

        PlanModule::updateOrCreate(
            ['billing_package_id' => $plan->id, 'billable_module_id' => $ghost->id],
            ['is_free' => false, 'trial_days' => null],
        );

        PlanModulePrice::create([
            'billing_package_id' => $plan->id,
            'billable_module_id' => $ghost->id,
            'interval' => 'month',
            'unit_amount' => 1000,
            'currency' => 'MXN',
            'is_active' => true,
        ]);

        Cache::forget(PlanPricingService::PLAN_CATALOG_CACHE_KEY);

        $catalog = $this->service()->planCatalog();

        $basico = collect($catalog['plans'])->firstWhere('key', 'basico');
        $ghostModule = collect($basico['modules'])->firstWhere('module_key', 'ghost');

        $this->assertNotNull($ghostModule);
        $this->assertSame([], $ghostModule['features']);
    }

    // =========================================================================
    // Endpoint: POST /api/billing/plan-quote
    // =========================================================================

    public function test_plan_quote_endpoint_responds_expected_shape(): void
    {
        $response = $this->postJson('/api/billing/plan-quote', [
            'plan_key' => 'basico',
            'modules' => ['base'],
            'interval' => 'month',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'plan' => ['key', 'name'],
                'interval',
                'currency',
                'lines' => [['module_key', 'name', 'unit_amount', 'is_free']],
                'total',
            ])
            ->assertJsonPath('plan.key', 'basico')
            ->assertJsonPath('interval', 'month')
            ->assertJsonPath('currency', 'MXN')
            ->assertJsonPath('total', self::BASICO_BASE_MONTH);
    }

    public function test_plan_quote_endpoint_works_without_the_modules_array(): void
    {
        $response = $this->postJson('/api/billing/plan-quote', [
            'plan_key' => 'basico',
            'interval' => 'month',
        ]);

        $response->assertOk()
            ->assertJsonPath('total', self::BASICO_BASE_MONTH)
            ->assertJsonPath('lines.0.module_key', 'base');
    }

    public function test_plan_quote_endpoint_returns_422_contact_sales_for_the_consultative_plan(): void
    {
        $response = $this->postJson('/api/billing/plan-quote', [
            'plan_key' => 'a_medida',
            'interval' => 'month',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'contact_sales');
    }

    public function test_plan_catalog_endpoint_responds_ok(): void
    {
        $response = $this->getJson('/api/billing/plan-catalog');

        $response->assertOk()
            ->assertJsonStructure([
                'currency',
                'max_billable_units',
                'contact' => ['email', 'whatsapp'],
                'plans' => [['key', 'name', 'suggested_unit_min', 'suggested_unit_max', 'is_contact_sales', 'modules', 'prices']],
            ]);

        // The contact-sales plan serializes `prices` as {} — an empty JSON ARRAY
        // here would break the frontends' object schema.
        $this->assertStringContainsString('"prices":{}', $response->getContent());
    }
}
