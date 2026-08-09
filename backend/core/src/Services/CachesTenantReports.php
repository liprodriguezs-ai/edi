<?php

namespace QCH\Core\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Shared helper for tenant-scoped dashboard report caching.
 *
 * Usage: add `use CachesTenantReports;` to any report service, then call
 * `$this->cachedReport($endpoint, $params, fn () => <computation>)` inside
 * each public method instead of returning the raw computation.
 *
 * Cache key format:
 *   dashboard_report:{tenant_id}:{endpoint}:{params_hash}
 *
 * The tenant_id is taken from the current tenancy context (same scope the
 * underlying queries already run under), so a cache hit for tenant A can
 * NEVER be served to tenant B.
 *
 * Behaviour flags are read from config('dashboard.reports.*') — never from
 * env() directly. See config/dashboard.php.
 */
trait CachesTenantReports
{
    /**
     * Execute $computation and cache its result under a tenant-scoped key.
     *
     * When cache is disabled (dashboard.reports.cache_enabled = false) the
     * computation runs every call and nothing is stored.
     *
     * @param  string  $endpoint  Short stable name, e.g. "income_expense_series".
     * @param  array<string, mixed>  $params  Vary-by parameters (months, timezone, …).
     * @param  callable(): mixed  $computation  The expensive aggregation closure.
     */
    protected function cachedReport(string $endpoint, array $params, callable $computation): mixed
    {
        if (! config('dashboard.reports.cache_enabled', true)) {
            return $computation();
        }

        $tenantId = $this->currentTenantId();
        $ttl = (int) config('dashboard.reports.cache_ttl', 300);

        $key = $this->buildCacheKey($tenantId, $endpoint, $params);

        return Cache::remember($key, $ttl, $computation);
    }

    /**
     * Build the tenant-scoped cache key.
     *
     * Format: dashboard_report:{tenant_id}:{endpoint}:{sorted_params_json}
     * Params are sorted to ensure key stability regardless of call-site ordering.
     *
     * @param  array<string, mixed>  $params
     */
    private function buildCacheKey(string $tenantId, string $endpoint, array $params): string
    {
        ksort($params);
        $paramSegment = http_build_query($params);

        return "dashboard_report:{$tenantId}:{$endpoint}:{$paramSegment}";
    }

    /**
     * Resolve the current tenant id from the active tenancy context.
     *
     * In tests (CACHE_STORE=array) this is already set by WithTenantSetup via
     * tenant()->run(). In production it is set by InitializeTenancyFromUser.
     *
     * Falls back to 'no_tenant' (which effectively partitions unscoped calls
     * into their own bucket — callers should ensure tenancy is initialized
     * before hitting these endpoints).
     */
    private function currentTenantId(): string
    {
        return (string) (tenancy()->initialized ? tenancy()->tenant->getTenantKey() : 'no_tenant');
    }
}
