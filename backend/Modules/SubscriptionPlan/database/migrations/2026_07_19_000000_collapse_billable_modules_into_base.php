<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\SubscriptionPlan\Services\PlanPricingService;

/**
 * Billing refactor (2026-07): collapse the sellable catalog to a SINGLE billable
 * module (`base`). Paquetería and Amenidades stop being sold separately — their
 * platform coverage moves INTO the base module so every cluster keeps (and now
 * gains) access to those systems.
 *
 * Data-only migration — no schema change, therefore no new columns to comment.
 *
 * Steps (all idempotent and safe when the rows are already gone):
 *  1. Re-point every `billable_module_system_module` row of `package`/`amenities`
 *     at `base`, skipping module ids the base already covers (the pivot carries a
 *     unique(billable_module_id, module_id)).
 *  2. Delete their `plan_module_price` rows — they are no longer quotable.
 *  3. Soft-delete the `package` / `amenities` billable_module rows. Soft delete
 *     (not a hard delete) because tenant_entitlement rows may still reference
 *     them historically; the FK stays intact and EntitlementService simply stops
 *     resolving them as sellable.
 */
return new class extends Migration
{
    /** The billable modules that stop existing as a sellable unit. */
    private const RETIRED_KEYS = ['package', 'amenities'];

    public function up(): void
    {
        $baseId = DB::table('billable_module')
            ->whereNull('deleted_at')
            ->where('key', 'base')
            ->value('id');

        $retiredIds = DB::table('billable_module')
            ->whereIn('key', self::RETIRED_KEYS)
            ->pluck('id')
            ->all();

        if ($retiredIds === []) {
            return;
        }

        if ($baseId !== null) {
            $this->mergeSystemModulesIntoBase($baseId, $retiredIds);
        }

        DB::table('billable_module_system_module')
            ->whereIn('billable_module_id', $retiredIds)
            ->delete();

        DB::table('plan_module_price')
            ->whereIn('billable_module_id', $retiredIds)
            ->delete();

        DB::table('billable_module')
            ->whereIn('id', $retiredIds)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        // The public plan catalog is cached; without this the retired modules
        // would keep showing up until the TTL expired.
        Cache::forget(PlanPricingService::PLAN_CATALOG_CACHE_KEY);
    }

    /**
     * Attach to `base` every platform module the retired billable modules covered
     * and the base does not already cover.
     *
     * @param  array<int, string>  $retiredIds
     */
    private function mergeSystemModulesIntoBase(string $baseId, array $retiredIds): void
    {
        $alreadyCovered = DB::table('billable_module_system_module')
            ->where('billable_module_id', $baseId)
            ->pluck('module_id')
            ->all();

        $moduleIds = DB::table('billable_module_system_module')
            ->whereIn('billable_module_id', $retiredIds)
            ->pluck('module_id')
            ->unique()
            ->diff($alreadyCovered)
            ->values();

        if ($moduleIds->isEmpty()) {
            return;
        }

        $now = now();

        DB::table('billable_module_system_module')->insert(
            $moduleIds->map(fn (string $moduleId): array => [
                'id' => (string) Str::uuid(),
                'billable_module_id' => $baseId,
                'module_id' => $moduleId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }

    /**
     * Irreversible by design: the retired price rows and pivot assignments are
     * dropped, and the seeder is the source of truth for rebuilding the catalog.
     */
    public function down(): void
    {
        // no-op
    }
};
