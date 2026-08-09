<?php

declare(strict_types=1);

namespace QCH\Core\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;

/**
 * Base class for CENTRAL catalog models (no tenant_id, global-once caching).
 *
 * Central catalogs are FSM states or behaviour selectors whose rows are
 * seeded once by CatalogSeeder and are read-only for tenant roles. They
 * carry no tenant_id column, so a single shared cache entry is correct and
 * desirable: all tenants see the same rows.
 *
 * The Cachable trait bakes the SQL WHERE clause (which, for central models,
 * contains no tenant filter) into the cache key, so no cross-tenant leak is
 * possible. Invalidation tags are model-class-scoped — also correct, because
 * flushes here should indeed apply globally (the row set is truly shared).
 *
 * Gated by config('laravel-model-caching.enabled') — defaults to false.
 */
abstract class CentralCatalogModel extends BaseModel
{
    use Cachable;
}
