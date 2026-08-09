<?php

namespace Modules\SubscriptionPlan\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Modules\Module\Models\Module;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModule;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Services\PlanPricingService;

/**
 * Seeds the billing catalog: ONE sellable module (`base`) priced across the
 * published unit bands, plus a price-less consultative plan (`a_medida`).
 *
 * 2026-07 refactor — there is no longer a notion of "sellable modules" facing the
 * user. Paquetería and Amenidades used to be separate billable modules; they are
 * now part of the base module (their platform `module` rows hang off the base
 * pivot), so the price of a cluster depends ONLY on its unit band. The retired
 * rows are cleaned up by the collapse_billable_modules_into_base migration; this
 * seeder simply stops recreating them.
 *
 * Structure:
 *  1. billable_module: `base` (is_base=true) mapped to every platform `module`
 *     row it grants, via the billable_module_system_module pivot.
 *     EntitlementService::recompute() derives the tenant's visible systems
 *     (system_tenant) from that pivot — a system with NO module anchored here
 *     never reaches the menu.
 *  2. Five priced plans (basico..premium) reusing billing_package, each with the
 *     base module priced for both intervals. Annual = round(monthly * 12 * 0.95)
 *     (5% annual discount).
 *  3. `a_medida`: a public, active plan with NO plan_module_price row. That
 *     absence is what marks it as contact-sales (PlanPricingService), so clusters
 *     above the highest band are routed to consultative sales instead of being
 *     clamped into premium.
 *
 * Idempotent: upsert by natural key everywhere; sync() for the module pivot.
 *
 * Amounts are integer minor units (cents). Each plan's (plan, module)
 * offering fact (is_free/trial_days) lives on its own plan_module row —
 * trial_days is left null so pricing falls back to config('billing.trial_days').
 */
class PlanModuleMatrixSeeder extends Seeder
{
    /** Months billed in a year — the multiplier behind the annual price. */
    private const ANNUAL_MONTH_COUNT = 12;

    /** Annual price factor: pay 95% of the year (5% annual discount). */
    private const ANNUAL_PRICE_FACTOR = 0.95;

    /** The contact-sales plan key: public, active, and deliberately price-less. */
    private const CONTACT_SALES_KEY = 'a_medida';

    /**
     * The five priced plans, keyed by billing_package.key.
     * [name, suggested_unit_min, suggested_unit_max, sort_order].
     *
     * @var array<string, array{name: string, min: int, max: int, sort: int}>
     */
    private const PLANS = [
        'basico' => ['name' => 'Básico', 'min' => 1, 'max' => 25, 'sort' => 10],
        'inicial' => ['name' => 'Inicial', 'min' => 26, 'max' => 50, 'sort' => 11],
        'esencial' => ['name' => 'Esencial', 'min' => 51, 'max' => 100, 'sort' => 12],
        'avanzado' => ['name' => 'Avanzado', 'min' => 101, 'max' => 200, 'sort' => 13],
        'premium' => ['name' => 'Premium', 'min' => 201, 'max' => 300, 'sort' => 14],
    ];

    /**
     * Monthly base-module price per plan (minor units).
     *
     * @var array<string, int>
     */
    private const BASE_MONTH_PRICE = [
        'basico' => 45000,
        'inicial' => 65000,
        'esencial' => 95000,
        'avanzado' => 155000,
        'premium' => 210000,
    ];

    /**
     * The platform `module` rows the base billable module grants. Everything the
     * product ships is here — including the former Paquetería add-on cluster and
     * (once it exists) Amenidades.
     *
     * @var array<int, string>
     */
    private const BASE_SYSTEM_MODULES = [
        // 'Cargos' (not the stale 'Cobros') is the real Cobranza module row.
        'Cargos', 'Avisos', 'Control de Accesos',
        'Egresos', 'Proveedores', 'Cuentas Bancarias',
        // Former `package` add-on: the feature plus its reports and catalogs.
        'Paquetería', 'Paquetería (Reportes)', 'Transportistas', 'Tipos de Paquete', 'Estados de Paquete',
        // Former `amenities` add-on. It was missing because the retired `amenities`
        // billable module carried an EMPTY pivot, so the plan sold Amenidades (see
        // BASE_FEATURES + the Stripe product description) without ever granting it —
        // and, more to the point, without ever switching it OFF on revocation.
        'Amenidades', 'Reservaciones',
    ];

    /**
     * User-facing feature bullets for the base module (Spanish), shown under the
     * plan in pricing UIs. Ordered list — order matters for display.
     *
     * @var array<int, string>
     */
    private const BASE_FEATURES = [
        'Cobranza completa: cuotas, recargos y estado de cuenta por casa',
        'Registro de pagos con folio y aplicación automática de saldo a favor',
        'Avisos y comunicados con notificación a residentes',
        'Control de accesos: visitas con código QR y bitácora',
        'Directorio de viviendas, residentes y vehículos',
        'Paquetería: recepción en portería y entrega verificada con código QR',
        'Amenidades: reserva de áreas comunes con calendario y reglas de uso',
        'Reportes de cobranza y morosidad para la mesa directiva',
    ];

    public function run(): void
    {
        $baseModule = $this->seedBillableModule(
            'base',
            'Plan Base',
            'Todo incluido: cobranza, avisos, control de accesos, tesorería, proveedores, paquetería y amenidades.',
            0,
            true,
            self::BASE_FEATURES,
        );

        $this->syncSystemModules($baseModule, self::BASE_SYSTEM_MODULES);

        foreach (self::PLANS as $key => $plan) {
            $package = $this->seedPlan($key, $plan);

            $baseMonth = self::BASE_MONTH_PRICE[$key];
            $this->upsertPlanModule($package, $baseModule);
            $this->upsertPrice($package, $baseModule, 'month', $baseMonth);
            $this->upsertPrice($package, $baseModule, 'year', $this->toAnnual($baseMonth));
        }

        $this->seedContactSalesPlan();

        // The public catalog is cached (PlanPricingService::CATALOG_CACHE_TTL);
        // without this, re-seeding (e.g. to add/edit features) would not be
        // visible via GET /api/billing/plan-catalog until the TTL expired.
        Cache::forget(PlanPricingService::PLAN_CATALOG_CACHE_KEY);
    }

    /**
     * @param  array<int, string>  $features
     */
    private function seedBillableModule(string $key, string $name, string $description, int $sortOrder, bool $isBase, array $features = []): BillableModule
    {
        return BillableModule::updateOrCreate(
            ['key' => $key],
            [
                'name' => $name,
                'description' => $description,
                'features' => $features,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'is_base' => $isBase,
            ]
        );
    }

    /**
     * @param  array{name: string, min: int, max: int, sort: int}  $plan
     */
    private function seedPlan(string $key, array $plan): BillingPackage
    {
        return BillingPackage::updateOrCreate(
            ['key' => $key],
            [
                'name' => $plan['name'],
                'description' => "Plan {$plan['name']} ({$plan['min']} a {$plan['max']} casas).",
                'sort_order' => $plan['sort'],
                'is_active' => true,
                'is_public' => true,
                'suggested_unit_min' => $plan['min'],
                'suggested_unit_max' => $plan['max'],
            ]
        );
    }

    /**
     * The consultative plan for clusters above the highest published band. It is
     * PUBLIC (it must render as a card in the catalog) and ACTIVE, but carries no
     * plan_module_price row — that is precisely what makes it contact-sales, and
     * why quoting or checking out against it is a 422.
     *
     * suggested_unit_max is null on purpose: no upper bound.
     */
    private function seedContactSalesPlan(): BillingPackage
    {
        return BillingPackage::updateOrCreate(
            ['key' => self::CONTACT_SALES_KEY],
            [
                'name' => 'A medida',
                'description' => 'Más de 300 casas: armamos un plan a la medida de tu fraccionamiento.',
                'sort_order' => 15,
                'is_active' => true,
                'is_public' => true,
                'suggested_unit_min' => 301,
                'suggested_unit_max' => null,
            ]
        );
    }

    /**
     * Upsert the (plan, module) offering fact — is_free/trial_days — that used
     * to be duplicated per interval on plan_module_price. Called once per
     * plan, before its price rows are upserted.
     */
    private function upsertPlanModule(BillingPackage $package, BillableModule $module, bool $isFree = false, ?int $trialDays = null): void
    {
        PlanModule::updateOrCreate(
            [
                'billing_package_id' => $package->id,
                'billable_module_id' => $module->id,
            ],
            [
                'is_free' => $isFree,
                'trial_days' => $trialDays,
            ]
        );
    }

    private function upsertPrice(BillingPackage $package, BillableModule $module, string $interval, int $unitAmount): void
    {
        // stripe_price_id / stripe_product_id are DELIBERATELY absent from this
        // update array. They are nullable columns that default to null on CREATE
        // (a fresh price row is unsynced until billing:sync-stripe runs) and are
        // OWNED by that command on every subsequent write — this seeder must never
        // clear them. Re-seeding used to null both on every run (start.sh runs
        // db:seed on deploy), wiping the Stripe ids of an already-synced catalog
        // and turning every checkout into a silent no_billable_price 422.
        PlanModulePrice::updateOrCreate(
            [
                'billing_package_id' => $package->id,
                'billable_module_id' => $module->id,
                'interval' => $interval,
            ],
            [
                'unit_amount' => $unitAmount,
                'currency' => 'MXN',
                'is_active' => true,
            ]
        );
    }

    /**
     * Annual amount from a monthly one: 12 months with the 5% annual discount.
     */
    private function toAnnual(int $monthlyMinorUnits): int
    {
        return (int) round($monthlyMinorUnits * self::ANNUAL_MONTH_COUNT * self::ANNUAL_PRICE_FACTOR);
    }

    /**
     * @param  array<int, string>  $moduleNames
     */
    private function syncSystemModules(BillableModule $billableModule, array $moduleNames): void
    {
        if ($moduleNames === []) {
            $billableModule->systemModules()->sync([]);

            return;
        }

        $moduleIds = Module::query()
            ->whereIn('name', $moduleNames)
            ->pluck('id')
            ->all();

        $missing = count($moduleNames) - count($moduleIds);

        if ($missing > 0) {
            $this->command?->warn("PlanModuleMatrixSeeder: {$missing} módulo(s) del sistema no encontrado(s) para '{$billableModule->key}' (esperados: ".implode(', ', $moduleNames).').');
        }

        $billableModule->systemModules()->sync($moduleIds);
    }
}
