<?php

namespace Modules\Collections\Observers;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Invalidates the cached account-statement totals for a unit whenever any Charge or
 * CreditBalance row that feeds those totals changes.
 *
 * The cached value (PaymentController::statement) aggregates expected/paid/outstanding +
 * credit_balance per unit. Every write that can move those numbers persists through Eloquent
 * save/create/delete, so invalidating at the model boundary here covers 100% of them (payment
 * application, charge generation, cancel/discount, the daily surcharge cron, community-project
 * charges, credit moves) without scattering Cache::forget across services. The only bulk write
 * in the module (ChargeStatusService::recomputeUnitAggregates) touches unit.charge_status_id,
 * which is not part of the totals, so it is intentionally out of scope.
 *
 * Implements ShouldHandleEventsAfterCommit so the forget runs AFTER the enclosing DB
 * transaction commits; otherwise a concurrent read could repopulate the cache from pre-commit
 * state.
 *
 * The key embeds tenant_id LITERALLY (read off the row, never tenancy()->tenant) so it is
 * Octane-safe, works in the tenant-less cron context, and can never leak across tenants
 * regardless of the cache store (the Stancl CacheTenancyBootstrapper is off for file/database
 * stores, so a bare key would be global).
 */
class StatementTotalsCacheObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * Cache key for a unit's statement totals. Shared with PaymentController::statement so the
     * read and the invalidation always agree.
     */
    public static function keyFor(string $tenantId, string $unitId): string
    {
        return "statement_totals:{$tenantId}:{$unitId}";
    }

    public function saved(Model $model): void
    {
        $this->forget($model);
    }

    public function deleted(Model $model): void
    {
        $this->forget($model);
    }

    public function restored(Model $model): void
    {
        $this->forget($model);
    }

    public function forceDeleted(Model $model): void
    {
        $this->forget($model);
    }

    private function forget(Model $model): void
    {
        $tenantId = $model->getAttribute('tenant_id');
        $unitId = $model->getAttribute('unit_id');

        if ($tenantId === null || $unitId === null) {
            return;
        }

        Cache::forget(self::keyFor((string) $tenantId, (string) $unitId));
    }
}
