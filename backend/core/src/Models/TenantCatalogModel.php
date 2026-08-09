<?php

declare(strict_types=1);

namespace QCH\Core\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Base class for TENANT-SCOPED catalog models (carry tenant_id, per-tenant caching).
 *
 * Tenant-scoped catalogs are admin-editable label catalogs whose rows differ
 * across tenants. They inherit BelongsToTenant (which injects `WHERE
 * tenant_id = ?` into every query), so the cache key already differentiates
 * per tenant because the WHERE clause is baked into the key by CacheKey.
 *
 * INVALIDATION SCOPING: Without an explicit per-tenant prefix, genealabs bakes
 * the invalidation tag from the model class name only (e.g.
 * "genealabs:laravel-model-caching:modules-housing-models-unit-status").
 * A write by Tenant A would flush that tag and evict Tenant B's cached results
 * too — unnecessary and counter-productive. Setting $cachePrefix to a
 * per-tenant string makes the tag become
 * "genealabs:laravel-model-caching:tenant-{id}:..." so each tenant's
 * invalidation is completely isolated.
 *
 * The $cachePrefix property is read by CachePrefixing::getCachePrefix() and
 * participates in BOTH the cache key and the invalidation tags. This is the
 * canonical hook provided by mike-bronner/laravel-model-caching v13.x.
 *
 * Gated by config('laravel-model-caching.enabled') — defaults to false.
 */
abstract class TenantCatalogModel extends BaseModel
{
    use BelongsToTenant;
    use Cachable;

    /**
     * Per-tenant cache namespace, set at construction time when tenancy is
     * already initialized. This string becomes part of both the cache key and
     * the invalidation tag, scoping flushes so one tenant's write never evicts
     * another tenant's cached rows.
     *
     * Example: "tenant-550e8400-e29b-41d4-a716-446655440000"
     */
    public string $cachePrefix = '';

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        if (function_exists('tenancy') && tenancy()->initialized) {
            $this->cachePrefix = 'tenant-'.tenancy()->tenant->getTenantKey();
        } else {
            // No tenant context → never write a prefix-less (cross-tenant) cache entry.
            // The $isCachable flag is checked by Caching::isCachable() before every
            // cache read/write, so setting it false here makes this model instance
            // completely bypass the cache when tenancy is absent.
            $this->isCachable = false;
        }
    }
}
