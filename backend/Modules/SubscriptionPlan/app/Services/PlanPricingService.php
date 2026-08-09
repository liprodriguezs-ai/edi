<?php

namespace Modules\SubscriptionPlan\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Modules\SubscriptionPlan\Exceptions\CheckoutUnavailableException;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModule;
use Modules\SubscriptionPlan\Models\PlanModulePrice;

/**
 * Server-side authority for the billing v2 plan×module pricing matrix.
 *
 * ADDITIVE (S2): this is a NEW engine that reads the plan_module_price matrix
 * seeded in S1. It does NOT replace PricingService (the legacy banded/per_unit
 * quoter for the landing) — both live side by side until a later slice migrates
 * consumers onto the matrix.
 *
 * Pricing model here is intentionally simpler than PricingService: the price of
 * a module inside a plan is a FIXED amount per (plan × module × interval). Units
 * NEVER multiply the price — the suggested unit range is metadata used only to
 * recommend a plan. All amounts are integer minor units (cents).
 *
 * is_active criterion (S2, documented on purpose):
 *  - `billing_package.is_active` is NOT gated by default. In S1 the five
 *    matrix plans are seeded is_active=false so no LEGACY consumer sees them;
 *    this engine is their intended future consumer, so it must read them to
 *    build the new catalog and quotes. The gate that eventually enforces
 *    is_active for AUTHENTICATED callers is deferred to the slice that
 *    activates the plans (S3+). The ONE exception: the public, unauthenticated
 *    quote endpoint calls `quoteByPlan(..., publicOnly: true)`, which filters
 *    `is_public` AND `is_active` so an anonymous caller can never quote a
 *    hidden/negotiated or retired plan by guessing its key (see
 *    BillingPlanQuoteController::quote()).
 *  - `plan_module_price.is_active` IS gated: only published price rows are
 *    quotable. A module with no active price row for (plan, interval) is "not
 *    available in this plan" (422 at the endpoint).
 *  - `planCatalog()` visibility is driven by (a) `is_public` (unless
 *    includeHidden) and (b) the plan having at least one active price row.
 */
class PlanPricingService
{
    /**
     * Cache key for the PUBLIC plan catalog. Deliberately distinct from the
     * legacy PricingService::CATALOG_CACHE_KEY ('billing.catalog') so the two
     * engines never bust or overwrite each other's cached payload.
     */
    public const PLAN_CATALOG_CACHE_KEY = 'billing.plan_catalog';

    private const CATALOG_CACHE_TTL = 300; // seconds

    private const DEFAULT_CURRENCY = 'MXN';

    /**
     * Recompute a quote for a plan from the stored matrix. Authoritative — no
     * client-supplied amounts are trusted; units are irrelevant to the price.
     *
     * The base module (billable_module.is_base) is MANDATORY: a quote missing it
     * is rejected. Each requested module must have an ACTIVE price row for the
     * plan/interval, otherwise it is "not available in this plan". A free row
     * (is_free) contributes 0 to the total.
     *
     * @param  array<int, string>  $moduleKeys  Empty means "everything the plan
     *                                          prices" — the caller no longer has
     *                                          to enumerate modules (the user-facing
     *                                          notion of sellable modules is gone).
     * @param  bool  $publicOnly  When true, only resolve plans that are BOTH
     *                            is_public=true and is_active=true — pass this
     *                            from unauthenticated/public callers ONLY.
     *                            Authenticated callers (checkout, preferential
     *                            swap) must keep the default (false) so they can
     *                            still quote hidden/inactive plans.
     * @return array{
     *     plan: array{key: string, name: string},
     *     interval: string,
     *     currency: string,
     *     lines: array<int, array{module_key: string, name: string, unit_amount: int, is_free: bool}>,
     *     total: int
     * }
     *
     * @throws InvalidArgumentException
     * @throws CheckoutUnavailableException When the plan is contact-sales (no price).
     */
    public function quoteByPlan(string $planKey, array $moduleKeys, string $interval, bool $publicOnly = false): array
    {
        if (! in_array($interval, PlanModulePrice::INTERVALS, true)) {
            throw new InvalidArgumentException("Intervalo inválido: {$interval}.");
        }

        // The plan is resolved purely by key (public or private): is_active is
        // NOT gated here UNLESS $publicOnly (see class docblock).
        $planQuery = BillingPackage::query()->where('key', $planKey);

        if ($publicOnly) {
            $planQuery->where('is_public', true)->where('is_active', true);
        }

        $plan = $planQuery->first();

        if ($plan === null) {
            // Same message as an unknown key — never reveal that a hidden or
            // retired plan exists to an unauthenticated caller.
            throw new InvalidArgumentException("Plan inválido: {$planKey}.");
        }

        // A price-less plan is the consultative tier: it cannot be self-quoted or
        // self-purchased, and says so with a machine-readable discriminator.
        if ($this->isContactSales($plan)) {
            throw CheckoutUnavailableException::contactSales(
                'Este plan se cotiza a la medida. Contáctanos para armar tu propuesta.'
            );
        }

        $moduleKeys = array_values(array_unique($moduleKeys));

        if ($moduleKeys === []) {
            $moduleKeys = $this->pricedModuleKeys($plan, $interval);
        }

        if ($moduleKeys === []) {
            throw new InvalidArgumentException('El plan seleccionado no tiene un precio configurado.');
        }

        $baseModule = BillableModule::query()->where('is_base', true)->first();

        if ($baseModule === null) {
            throw new InvalidArgumentException('No hay un plan base configurado.');
        }

        if (! in_array($baseModule->key, $moduleKeys, true)) {
            throw new InvalidArgumentException('La cotización no corresponde al plan seleccionado.');
        }

        $modules = BillableModule::query()
            ->whereIn('key', $moduleKeys)
            ->get()
            ->keyBy('key');

        $flags = $this->planModuleFlags($plan);

        $lines = [];
        $total = 0;
        $currency = self::DEFAULT_CURRENCY;

        foreach ($moduleKeys as $key) {
            $module = $modules->get($key);

            if ($module === null) {
                throw new InvalidArgumentException("Módulo inválido: {$key}.");
            }

            $price = $this->activePrice($plan, $module, $interval);

            if ($price === null) {
                throw new InvalidArgumentException("El módulo '{$key}' no está disponible en el plan '{$planKey}'.");
            }

            $isFree = (bool) ($flags->get($module->id)?->is_free ?? false);
            $amount = $isFree ? 0 : $price->unit_amount;

            // Currency is taken from the mandatory base line so the quote is
            // deterministic regardless of the order modules were requested in.
            if ($module->is_base) {
                $currency = $price->currency ?? self::DEFAULT_CURRENCY;
            }

            $lines[] = [
                'module_key' => $module->key,
                'name' => $module->name,
                'unit_amount' => $amount,
                'is_free' => $isFree,
            ];

            $total += $amount;
        }

        return [
            'plan' => ['key' => $plan->key, 'name' => $plan->name],
            'interval' => $interval,
            'currency' => $currency,
            'lines' => $lines,
            'total' => $total,
        ];
    }

    /**
     * Price ONE billable module inside a plan for an interval, in minor units.
     *
     * Returns 0 for a free row (is_free). Throws when the module has no active
     * price row for the plan/interval (a "not available in this plan" state),
     * mirroring quoteByPlan().
     *
     * @throws InvalidArgumentException
     */
    public function priceForModule(BillingPackage $plan, BillableModule $module, string $interval): int
    {
        if (! in_array($interval, PlanModulePrice::INTERVALS, true)) {
            throw new InvalidArgumentException("Intervalo inválido: {$interval}.");
        }

        $price = $this->activePrice($plan, $module, $interval);

        if ($price === null) {
            throw new InvalidArgumentException("El módulo '{$module->key}' no está disponible en el plan '{$plan->key}'.");
        }

        $isFree = (bool) (PlanModule::query()
            ->where('billing_package_id', $plan->id)
            ->where('billable_module_id', $module->id)
            ->first()?->is_free ?? false);

        return $isFree ? 0 : (int) $price->unit_amount;
    }

    /**
     * The active PLANS of the billing v2 matrix: billing_package rows that carry
     * plan_module_price rows (this is what discriminates a plan — basico..premium —
     * from the legacy module-as-package rows base/package/amenities which only carry
     * billing_package_price). Ordered by sort_order.
     *
     * @return Collection<int, BillingPackage>
     */
    public function activePlans(): Collection
    {
        return BillingPackage::query()
            ->where('is_active', true)
            ->whereHas('plan_prices')
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * The consultative (contact-sales) plan: an ACTIVE billing_package that is
     * deliberately price-less — it carries no active plan_module_price row, so it
     * can never be quoted or purchased self-service. Null when none is configured.
     */
    public function contactSalesPlan(): ?BillingPackage
    {
        return BillingPackage::query()
            ->where('is_active', true)
            ->whereDoesntHave('plan_prices', fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->first();
    }

    /**
     * True when the plan has no active price row — the marker of the consultative
     * tier. Deliberately derived from data (absence of a price) rather than a flag
     * column, so a plan becomes sellable the moment it gets a price.
     */
    public function isContactSales(BillingPackage $plan): bool
    {
        return ! PlanModulePrice::query()
            ->where('billing_package_id', $plan->id)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * The highest unit count still covered by a PRICED plan band. Null when no
     * priced plan publishes an upper bound. Frontends use it to decide when to
     * swap the quoter for the "Contáctanos" card.
     */
    public function maxBillableUnits(): ?int
    {
        $max = $this->activePlans()
            ->pluck('suggested_unit_max')
            ->filter(fn (?int $value): bool => $value !== null)
            ->max();

        return $max === null ? null : (int) $max;
    }

    /**
     * Resolve the plan whose suggested unit band contains $units. Above the largest
     * priced band → the consultative plan (contact sales) when one exists, else the
     * largest priced plan. Below the smallest / when $units is null → the smallest
     * plan. Throws when no plan is configured.
     *
     * @throws InvalidArgumentException
     */
    public function planForUnits(?int $units): BillingPackage
    {
        $plans = $this->activePlans();

        if ($plans->isEmpty()) {
            throw new InvalidArgumentException('No hay un plan de facturación configurado.');
        }

        if ($units !== null) {
            $match = $plans->first(fn (BillingPackage $plan): bool => $plan->suggested_unit_min !== null
                && $plan->suggested_unit_max !== null
                && $units >= $plan->suggested_unit_min
                && $units <= $plan->suggested_unit_max);

            if ($match !== null) {
                return $match;
            }

            // Above the largest published band: the organization is out of the
            // self-serve price list, so it belongs to consultative sales. Only
            // when no such plan exists do we fall back to clamping.
            $largest = $plans->sortByDesc('suggested_unit_max')->first();

            if ($largest->suggested_unit_max !== null && $units > $largest->suggested_unit_max) {
                return $this->contactSalesPlan() ?? $largest;
            }
        }

        return $plans->first();
    }

    /**
     * Resolve an ACTIVE plan by key (a billing_package carrying plan_module_price
     * rows). Null when unknown/inactive/not a plan.
     *
     * @param  bool  $includeContactSales  Also resolve the price-less consultative
     *                                     plan. Callers that go on to CHARGE must
     *                                     keep the default (false); callers that
     *                                     merely need to reference the plan (free
     *                                     courtesy grants) pass true.
     */
    public function planByKey(string $key, bool $includeContactSales = false): ?BillingPackage
    {
        return BillingPackage::query()
            ->where('key', $key)
            ->where('is_active', true)
            ->when(! $includeContactSales, fn ($query) => $query->whereHas('plan_prices'))
            ->first();
    }

    /**
     * The required base billable module (is_base=true). Throws when none.
     *
     * @throws InvalidArgumentException
     */
    public function baseModule(): BillableModule
    {
        $module = BillableModule::query()
            ->where('is_base', true)
            ->where('is_active', true)
            ->first();

        if ($module === null) {
            throw new InvalidArgumentException('No hay un plan base configurado.');
        }

        return $module;
    }

    /**
     * Resolve an ACTIVE billable module by key. Null when unknown/inactive.
     */
    public function moduleByKey(string $key): ?BillableModule
    {
        return BillableModule::query()
            ->where('key', $key)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Catalog of plans with their module price matrix per interval.
     *
     * By DEFAULT only PUBLIC plans (is_public=true) are exposed and the result
     * is cached under a dedicated key. Super-admin callers pass
     * $includeHidden=true to also see private plans; that variant is NEVER
     * cached, so it can never leak into the public cache entry.
     *
     * Only ACTIVE plans are listed (is_active=false is how a plan is retired, and
     * every write path rejects it), whether or not they carry prices: a price-less
     * ACTIVE plan is the consultative tier and must render as a "Contáctanos" card.
     *
     * @return array{currency: string, max_billable_units: int|null, contact: array{email: string|null, whatsapp: string|null}, plans: array<int, array<string, mixed>>}
     */
    public function planCatalog(bool $includeHidden = false): array
    {
        if ($includeHidden) {
            return $this->buildPlanCatalog(true);
        }

        return Cache::remember(
            self::PLAN_CATALOG_CACHE_KEY,
            self::CATALOG_CACHE_TTL,
            fn (): array => $this->buildPlanCatalog(false),
        );
    }

    /**
     * @return array{currency: string, max_billable_units: int|null, contact: array{email: string|null, whatsapp: string|null}, plans: array<int, array<string, mixed>>}
     */
    private function buildPlanCatalog(bool $includeHidden): array
    {
        $plans = BillingPackage::query()
            ->when(! $includeHidden, fn ($query) => $query->where('is_public', true))
            ->orderBy('sort_order')
            ->get();

        $pricesByPlan = PlanModulePrice::query()
            ->where('is_active', true)
            ->whereIn('billing_package_id', $plans->pluck('id'))
            ->with('billable_module')
            ->get()
            ->groupBy('billing_package_id');

        $flagsByPair = PlanModule::query()
            ->whereIn('billing_package_id', $plans->pluck('id'))
            ->get()
            ->keyBy(fn (PlanModule $flag): string => $flag->billing_package_id.'|'.$flag->billable_module_id);

        $result = [];

        foreach ($plans as $plan) {
            // A RETIRED plan (is_active=false) is never listed, priced or not.
            // Lowering is_active is the only way to withdraw a plan, and every write
            // path (quote, checkout, preferential swap, provisioning) already
            // rejects an inactive plan — listing it would publish a dead tier that
            // 422s on the next click, cached for the whole catalog TTL.
            if (! $plan->is_active) {
                continue;
            }

            $planPrices = $pricesByPlan->get($plan->id);
            $isContactSales = $planPrices === null || $planPrices->isEmpty();

            $modules = $isContactSales ? [] : $this->mapPlanModules($planPrices, $flagsByPair);

            $result[] = [
                'key' => $plan->key,
                'name' => $plan->name,
                'description' => $plan->description,
                'suggested_unit_min' => $plan->suggested_unit_min,
                'suggested_unit_max' => $plan->suggested_unit_max,
                // Exposed so the super-admin preferential-swap picker can list ONLY
                // hidden variants (PreferentialSwapController requires is_public=false)
                // instead of guessing from the key.
                'is_public' => (bool) $plan->is_public,
                'is_contact_sales' => $isContactSales,
                // Distinct from is_contact_sales: a plan WITH price rows but none of
                // them Stripe-synced is an operational fault (D6), not a deliberate
                // "cotiza a la medida" tier. The frontend disables checkout with a
                // "temporalmente no disponible" state instead of letting the owner
                // hit the 422 dead end.
                'is_purchasable' => ! $isContactSales && $planPrices->contains(
                    fn (PlanModulePrice $price): bool => $price->stripe_price_id !== null
                ),
                'modules' => $modules,
                // ALWAYS an object, never [] — the frontends parse this with an
                // object schema and a JSON array breaks them.
                'prices' => (object) $this->sumPlanPrices($modules),
            ];
        }

        return [
            'currency' => self::DEFAULT_CURRENCY,
            'max_billable_units' => $this->maxBillableUnits(),
            'contact' => [
                'email' => config('billing.contact_email'),
                'whatsapp' => config('billing.contact_whatsapp'),
            ],
            'plans' => $result,
        ];
    }

    /**
     * The price of the WHOLE plan per interval: the sum of its module lines. This
     * is what the frontends render — they no longer add up module lines themselves.
     *
     * @param  array<int, array<string, mixed>>  $modules
     * @return array<string, array{unit_amount: int, currency: string}>
     */
    private function sumPlanPrices(array $modules): array
    {
        $totals = [];

        foreach ($modules as $module) {
            foreach ((array) $module['prices'] as $interval => $price) {
                $totals[$interval] ??= ['unit_amount' => 0, 'currency' => $price['currency']];
                $totals[$interval]['unit_amount'] += (int) $price['unit_amount'];
            }
        }

        return $totals;
    }

    /**
     * @param  Collection<int, PlanModulePrice>  $planPrices
     * @param  Collection<string, PlanModule>  $flagsByPair  Keyed "{plan_id}|{module_id}"
     * @return array<int, array<string, mixed>>
     */
    private function mapPlanModules($planPrices, Collection $flagsByPair): array
    {
        $modules = [];

        foreach ($planPrices as $price) {
            $module = $price->billable_module;

            if ($module === null) {
                continue;
            }

            $modules[$module->key] ??= [
                'module_key' => $module->key,
                'name' => $module->name,
                'description' => $module->description,
                'features' => array_values($module->features ?? []),
                'is_base' => (bool) $module->is_base,
                'prices' => [],
            ];

            $isFree = (bool) ($flagsByPair->get($price->billing_package_id.'|'.$price->billable_module_id)?->is_free ?? false);

            $modules[$module->key]['prices'][$price->interval] = [
                'unit_amount' => $isFree ? 0 : (int) $price->unit_amount,
                'is_free' => $isFree,
                'currency' => $price->currency ?? self::DEFAULT_CURRENCY,
            ];
        }

        return array_values($modules);
    }

    /**
     * The plan's (plan, module) offering flags, keyed by billable_module_id.
     * ONE query per quote/catalog build. Missing row (drift — an active price
     * row with no matching plan_module row) reads is_free as false: a free
     * grant must be an established fact, never an inferred one.
     *
     * @return Collection<string, PlanModule>
     */
    private function planModuleFlags(BillingPackage $plan): Collection
    {
        return PlanModule::query()
            ->where('billing_package_id', $plan->id)
            ->get()
            ->keyBy('billable_module_id');
    }

    /**
     * Every module key the plan actually prices for the interval, in module
     * display order — the default module set when the caller sends none.
     *
     * @return array<int, string>
     */
    private function pricedModuleKeys(BillingPackage $plan, string $interval): array
    {
        return PlanModulePrice::query()
            ->where('plan_module_price.billing_package_id', $plan->id)
            ->where('plan_module_price.interval', $interval)
            ->where('plan_module_price.is_active', true)
            ->join('billable_module', 'billable_module.id', '=', 'plan_module_price.billable_module_id')
            ->whereNull('billable_module.deleted_at')
            ->where('billable_module.is_active', true)
            ->orderByDesc('billable_module.is_base')
            ->orderBy('billable_module.sort_order')
            ->pluck('billable_module.key')
            ->unique()
            ->values()
            ->all();
    }

    private function activePrice(BillingPackage $plan, BillableModule $module, string $interval): ?PlanModulePrice
    {
        return PlanModulePrice::query()
            ->where('billing_package_id', $plan->id)
            ->where('billable_module_id', $module->id)
            ->where('interval', $interval)
            ->where('is_active', true)
            ->first();
    }
}
