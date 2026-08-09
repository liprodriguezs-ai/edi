<?php

namespace QCH\Core\Services;

/**
 * Shared arithmetic helpers for tenant-scoped report services.
 *
 * Usage: add `use BuildsTenantReports;` to any report service alongside the
 * existing `use CachesTenantReports;`.
 *
 * Only logic that is genuinely duplicated across multiple services lives here.
 * Service-specific helpers (e.g. monthly bucket building, minor-unit column
 * sums) stay in the service that owns them.
 */
trait BuildsTenantReports
{
    /**
     * Safe integer percentage: round($part / $total * 100) or 0 when total is 0.
     *
     * Used wherever a "percentage of total" must be expressed as a whole integer
     * without risking division-by-zero. Returns 0 for any non-positive total so
     * callers never need to guard the division themselves.
     *
     * @param  int  $part  The numerator (e.g. paid count, raised amount).
     * @param  int  $total  The denominator (e.g. issued count, goal amount).
     * @return int Percentage in [0, N] — may exceed 100 if part > total.
     */
    protected function safePercent(int $part, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return (int) round($part / $total * 100);
    }
}
