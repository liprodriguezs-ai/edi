<?php

namespace Modules\SubscriptionPlan\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\PlanModule;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Stripe\Price;
use Stripe\StripeClient;

/**
 * Syncs the billing v2 plan×module matrix (plan_module_price) into Stripe
 * Products/Prices:
 *   - one Product per billable_module (base / package / …), keyed by a
 *     deterministic recovery via any of its prices' lookup keys;
 *   - one Price per (plan × module × interval) cell, a simple unit_amount price
 *     (NO tiers — the matrix price is fixed), lookup key
 *     billing_{planKey}_{moduleKey}_{interval}, persisted back onto the cell's
 *     stripe_price_id / stripe_product_id.
 *
 * Only ACTIVE plans + ACTIVE, NON-FREE price rows are synced. is_free is a
 * (plan, module) fact read from plan_module: free → skipped silently (price
 * pending); a price row with NO matching plan_module row is ALSO skipped
 * (logged via Log::warning) — never silently synced as if it were paid.
 * Idempotent by deterministic lookup keys; Stripe-price immutability
 * respected via versioned keys on a real amount change.
 *
 * Octane-safe: the StripeClient is resolved per call (never stored on the
 * service), so no tenant/request/client state leaks across pooled workers.
 */
class BillingPackageStripeService
{
    private const CURRENCY = 'mxn';

    /**
     * Resolve a fresh StripeClient per call (Octane-safe). Resolving through the
     * container lets tests bind a Mockery double for the class without the network.
     */
    protected function stripeClient(): StripeClient
    {
        return app(StripeClient::class, ['config' => config('cashier.secret')]);
    }

    /**
     * Deterministic, migrate:fresh-stable lookup key per (plan, module, interval).
     * e.g. "billing_esencial_base_month", "billing_basico_package_year".
     */
    public function stripeLookupKey(string $planKey, string $moduleKey, string $interval): string
    {
        return 'billing_'.$planKey.'_'.$moduleKey.'_'.$interval;
    }

    /**
     * Plan the sync WITHOUT touching Stripe — used by the --dry-run command.
     *
     * @return array<int, array<string, mixed>>
     */
    public function plan(?string $planKey = null): array
    {
        return $this->syncableRows($planKey)
            ->groupBy(fn (PlanModulePrice $row): string => (string) $row->billable_module?->key)
            ->map(function (Collection $rows, string $moduleKey): array {
                return [
                    'module' => $moduleKey,
                    'action' => 'sync',
                    'lookup_keys' => $rows
                        ->map(fn (PlanModulePrice $row): string => $this->stripeLookupKey(
                            (string) $row->plan?->key,
                            $moduleKey,
                            $row->interval,
                        ))
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Sync every active plan×module cell (optionally scoped to a single plan) to
     * Stripe, one Product per billable module.
     *
     * @return array<int, array<string, mixed>>
     */
    public function syncToStripe(?string $planKey = null): array
    {
        $stripe = $this->stripeClient();

        return $this->syncableRows($planKey)
            ->groupBy(fn (PlanModulePrice $row): string => (string) $row->billable_module?->id)
            ->map(fn (Collection $rows): array => $this->syncModule($stripe, $rows))
            ->values()
            ->all();
    }

    /**
     * Sync all price cells of ONE billable module (they share a single Product).
     *
     * @param  Collection<int, PlanModulePrice>  $rows
     * @return array<string, mixed>
     */
    protected function syncModule(StripeClient $stripe, Collection $rows): array
    {
        /** @var BillableModule $module */
        $module = $rows->first()->billable_module;

        try {
            $existingProductId = $this->resolveProductId($stripe, $module, $rows);
            $productId = $existingProductId ?? $this->createProduct($stripe, $module);

            // F-19: the name/description were only written at CREATE time, so the
            // hosted Checkout page kept showing the pre-collapse copy ("… módulo base
            // obligatorio", no mention of paquetería or amenidades) forever. Push the
            // current copy on every sync so `billing:sync-stripe` is the single way to
            // publish it.
            if ($existingProductId !== null) {
                $this->updateProduct($stripe, $existingProductId, $module);
            }

            $prices = $rows
                ->map(fn (PlanModulePrice $row): array => $this->syncPrice($stripe, $row, $productId))
                ->values()
                ->all();

            return [
                'module' => $module->key,
                'action' => 'synced',
                'product_id' => $productId,
                'prices' => $prices,
            ];
        } catch (\Throwable $e) {
            Log::error('Stripe billing matrix sync failed', [
                'module' => $module->key,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * The plan_module_price rows eligible for a Stripe sync: active plan, active
     * row, and NOT free per its plan_module row. No SQL join — a PHP-side
     * partition against a batch-fetched plan_module lookup, so a price row
     * with no matching plan_module row is excluded AND surfaced (Log::warning)
     * instead of silently treated as free (INNER-shaped) or paid (LEFT-shaped).
     *
     * @return Collection<int, PlanModulePrice>
     */
    protected function syncableRows(?string $planKey): Collection
    {
        $candidates = PlanModulePrice::query()
            ->where('is_active', true)
            ->whereHas('plan', fn ($query) => $query->where('is_active', true)
                ->when($planKey !== null, fn ($inner) => $inner->where('key', $planKey)))
            ->whereHas('billable_module', fn ($query) => $query->where('is_active', true))
            ->with(['plan:id,key,name,sort_order', 'billable_module:id,key,name,description'])
            ->get();

        $flagsByPair = PlanModule::query()
            ->whereIn('billing_package_id', $candidates->pluck('billing_package_id')->unique())
            ->whereIn('billable_module_id', $candidates->pluck('billable_module_id')->unique())
            ->get()
            ->keyBy(fn (PlanModule $flag): string => $flag->billing_package_id.'|'.$flag->billable_module_id);

        return $candidates
            ->filter(function (PlanModulePrice $row) use ($flagsByPair): bool {
                $flags = $flagsByPair->get($row->billing_package_id.'|'.$row->billable_module_id);

                if ($flags === null) {
                    Log::warning('Stripe sync skipped: no plan_module row for price row', [
                        'plan' => $row->plan?->key,
                        'module' => $row->billable_module?->key,
                        'interval' => $row->interval,
                    ]);

                    return false;
                }

                return ! $flags->is_free;
            })
            ->sortBy(fn (PlanModulePrice $row): string => ($row->billable_module?->key ?? '').'|'.($row->plan?->sort_order ?? 0).'|'.$row->interval)
            ->values();
    }

    /**
     * Recover the Stripe Product id from the module's local rows, then (surviving a
     * local migrate:fresh) from Stripe via any of its lookup keys. Null when never
     * synced.
     *
     * @param  Collection<int, PlanModulePrice>  $rows
     */
    protected function resolveProductId(StripeClient $stripe, BillableModule $module, Collection $rows): ?string
    {
        $local = $rows->pluck('stripe_product_id')->filter()->first();

        if ($local) {
            return $local;
        }

        foreach ($rows as $row) {
            $existing = $this->findPriceByLookupKey(
                $stripe,
                $this->stripeLookupKey((string) $row->plan?->key, (string) $module->key, $row->interval),
            );

            if ($existing !== null) {
                return $this->productIdOf($existing);
            }
        }

        return null;
    }

    protected function createProduct(StripeClient $stripe, BillableModule $module): string
    {
        $product = $stripe->products->create([
            'name' => $module->name,
            'description' => $module->description ?? '',
            'metadata' => ['billable_module_id' => $module->id, 'billable_module_key' => $module->key],
        ]);

        return $product->id;
    }

    /**
     * Re-publish the module's current name/description onto its existing Stripe
     * Product. Non-fatal: a failure here must not abort a price sync, since the
     * copy is cosmetic next to the amounts.
     */
    protected function updateProduct(StripeClient $stripe, string $productId, BillableModule $module): void
    {
        try {
            $stripe->products->update($productId, [
                'name' => $module->name,
                'description' => $module->description ?? '',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Stripe product copy update failed', [
                'module' => $module->key,
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Idempotently create-or-reuse the Stripe Price for one matrix cell, persisting
     * the resulting ids back onto the plan_module_price row.
     *
     * @return array<string, mixed>
     */
    protected function syncPrice(StripeClient $stripe, PlanModulePrice $row, string $productId): array
    {
        $lookupKey = $this->stripeLookupKey((string) $row->plan?->key, (string) $row->billable_module?->key, $row->interval);
        $payload = $this->buildPricePayload($row, $productId, $lookupKey);

        $existing = $this->findPriceByLookupKey($stripe, $lookupKey);

        if ($existing !== null) {
            // Stripe prices are immutable: on a real amount change fall back to a
            // versioned lookup key (…_v2, _v3, …) instead of mutating.
            if (! $this->priceMatches($existing, $payload)) {
                [$existing, $lookupKey, $created] = $this->createVersionedPrice($stripe, $lookupKey, $payload);
            } else {
                $created = false;
            }
        } else {
            $existing = $stripe->prices->create($payload);
            $created = true;
        }

        $this->persistPrice($row, $existing, $productId);

        return [
            'plan' => $row->plan?->key,
            'interval' => $row->interval,
            'lookup_key' => $lookupKey,
            'price_id' => $existing->id,
            'product_id' => $productId,
            'created' => $created,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: Price, 1: string, 2: bool} [price, lookup_key, created]
     */
    protected function createVersionedPrice(StripeClient $stripe, string $lookupKey, array $payload): array
    {
        $version = 2;
        $maxVersion = 20;

        while (true) {
            if ($version > $maxVersion) {
                throw new \RuntimeException(
                    "Demasiados precios versionados para {$lookupKey} (>{$maxVersion}) — intervención manual requerida."
                );
            }

            $versionedKey = $lookupKey.'_v'.$version;
            $found = $this->findPriceByLookupKey($stripe, $versionedKey);

            if ($found !== null && $this->priceMatches($found, $payload)) {
                return [$found, $versionedKey, false];
            }

            if ($found === null) {
                $payload['lookup_key'] = $versionedKey;

                return [$stripe->prices->create($payload), $versionedKey, true];
            }

            $version++;
        }
    }

    /**
     * Build the Stripe Price payload for one matrix cell: a simple unit_amount
     * recurring price (no tiers — the plan×module price is fixed).
     *
     * @return array<string, mixed>
     */
    protected function buildPricePayload(PlanModulePrice $row, string $productId, string $lookupKey): array
    {
        return [
            'product' => $productId,
            'currency' => self::CURRENCY,
            'lookup_key' => $lookupKey,
            'unit_amount' => (int) $row->unit_amount,
            'recurring' => [
                'interval' => $row->interval,
                'interval_count' => 1,
            ],
        ];
    }

    protected function findPriceByLookupKey(StripeClient $stripe, string $lookupKey): ?Price
    {
        $result = $stripe->prices->all([
            'lookup_keys' => [$lookupKey],
            'active' => true,
            'limit' => 1,
            'expand' => ['data.product'],
        ]);

        return empty($result->data) ? null : $result->data[0];
    }

    /**
     * Compare an existing Stripe price against the desired unit_amount payload.
     * Returns false only on a real amount difference (avoids spurious versioning).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function priceMatches(Price $existing, array $payload): bool
    {
        if (array_key_exists('unit_amount', $payload)) {
            return (int) $existing->unit_amount === (int) $payload['unit_amount'];
        }

        return true;
    }

    protected function persistPrice(PlanModulePrice $row, Price $stripePrice, string $productId): void
    {
        $row->updateQuietly([
            'stripe_price_id' => $stripePrice->id,
            'stripe_product_id' => $productId,
        ]);
    }

    protected function productIdOf(Price $price): string
    {
        return is_string($price->product) ? $price->product : $price->product->id;
    }
}
