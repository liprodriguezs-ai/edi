---
name: genealabs-model-caching-isolation
description: How mike-bronner/laravel-model-caching ($cachePrefix) achieves per-tenant cache key + invalidation isolation; the fail-open gap when tenancy is absent; verified vendor internals and the CentralCatalogModel/TenantCatalogModel design.
metadata:
  type: pattern
---

# genealabs/mike-bronner model-caching — tenant-isolation mechanics

Verified against `vendor/mike-bronner/laravel-model-caching` v13.x. Design lives in
`core/src/Models/CentralCatalogModel.php` (extends BaseModel + Cachable) and
`core/src/Models/TenantCatalogModel.php` (extends BaseModel + BelongsToTenant + Cachable,
constructor sets `$cachePrefix = 'tenant-'.tenant->getTenantKey()` ONLY when `tenancy()->initialized`).

## Why it isolates (both read key AND invalidation tag carry the prefix)
- `Traits/CachePrefixing::getCachePrefix()` (READ key, used by `CacheKey::make()` line 51) appends
  `$this->model->cachePrefix . ":"` when the property exists.
- `Traits/Caching::getCachePrefix()` (WRITE/flush, used by `CacheTags` via `makeCacheTags`) ALSO reads
  `$this->model->cachePrefix`. So `flushCache()` tags become `genealabs:...:tenant-{A}:...`.
- The read key additionally bakes in `getWhereClauses()` + `getBindingsSlug()` → the `WHERE tenant_id = ?`
  injected by `TenantScope` is part of the key. So even WITHOUT the prefix, two tenants get different
  read keys. The `$cachePrefix` exists primarily to isolate the INVALIDATION TAG (without it, tenant A's
  write flushes the shared model-class tag and evicts tenant B's cache — correctness, not a leak).
- Flush path binding: `(new UnitStatus)->flushCache()` → `makeCacheTags()` binds `$model = getModel() ?? $this`
  (the instance whose constructor set cachePrefix) → tags scoped to that tenant. Correct only because the
  instance is constructed inside the writing tenant's context.

## The fail-open gap (the real risk to scrutinize)
When a TenantCatalogModel is instantiated WITHOUT tenancy initialized, `$cachePrefix` stays `''` AND
`TenantScope` adds no `WHERE tenant_id`. An unscoped `UnitStatus::all()` (super-admin / console / queue
before re-init) then caches ALL tenants' rows under a prefix-less key `genealabs:...::unit-status...`.
A later in-tenant read uses a DIFFERENT key (has the WHERE + prefix), so it will NOT read that poisoned
entry — but the prefix-less entry is global and a second unscoped reader gets stale cross-tenant data.
This is a correctness/staleness hazard for unscoped readers, NOT a tenant→tenant leak under normal
request flow (auth → InitializeTenancyFromUser runs before any catalog read). Recommendation:
hard-disable caching (`disableModelCaching()` / return early) when `! tenancy()->initialized` on tenant
catalog models, so unscoped reads never populate a global key. Failing open is acceptable for v1 ONLY
because catalog reads are always behind `initializeTenancy` middleware and the table is tiny.

## Gating / requirements
- `config('laravel-model-caching.enabled')` default MUST be false; store MUST be a TaggableStore (Redis/
  Memcached) — file/array/database make the trait a silent no-op (false greens in tests).
- `use-database-keying = false` is CORRECT for this single-DB project: all tenants share one DB name, so
  DB-keying adds a constant useless segment and would NOT provide isolation anyway (isolation comes from
  WHERE-clause + $cachePrefix, not DB name). Keep it false.

## Central catalogs (24) — global-once is safe
All carry NO BelongsToTenant and NO tenant_id (verified: charge_status, payment_status, unit_relationship,
board_role, etc. all clean). One shared cache entry is correct; class-scoped invalidation is correct
because the row set is genuinely shared. No central catalog secretly filters by tenant.

## Tenant catalogs (14) get tenant_id from an ALTER migration, not the create
`database/migrations/2026_06_11_120000_make_editable_catalogs_tenant_scoped.php` adds `tenant_id` NOT NULL
+ FK + index + `unique(tenant_id,name)` to the 14 editable catalogs and backfills per-tenant clones. The
ORIGINAL create migrations (e.g. `unit_status`) still say `columns($table, false)` and carry a stale
"global, not tenant-scoped" table comment — STALE DOC, harmless: the ALTER makes them tenant-scoped. Do
not be alarmed by the create-migration comment.
