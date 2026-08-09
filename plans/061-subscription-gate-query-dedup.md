# Plan 061: Collapse the per-request tenant-resolution double-query on the subscription gate

> **Executor instructions**: Follow step by step; verify each step. STOP
> conditions halt you. Update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C backend diff --stat 8117e66..HEAD -- app/Services/ActiveTenantResolver.php app/Http/Middleware/EnsureSubscriptionActive.php app/Services/SubscriptionStateService.php`

## Status

- **Priority**: P2
- **Effort**: S (this plan is the safe, no-risk slice; the full cache is a noted follow-up)
- **Risk**: LOW
- **Depends on**: none
- **Category**: performance
- **Planned at**: backend `8117e66`, 2026-07-01

## Why this matters

`EnsureSubscriptionActive` runs on every authenticated request to any non-allowlisted
endpoint (the whole `api` group — Housing, Collections, Chat, Package, Dashboard,
Treasury, …). On that hottest-of-hot paths it recomputes subscription state with ~4–6 DB
round-trips, and the team's own in-code comment acknowledges the recompute is "deliberately
NOT cached yet." Part of that cost is pure waste: `ActiveTenantResolver::resolveForRegularUser`
plucks the user's tenant IDs and then does a SEPARATE `Tenant::find($id)` for the matched
id — two queries where one would do. This plan takes the safe, no-behavior-change win now
(collapse the double-query) and records the larger caching effort as a scoped follow-up
(which carries real invalidation risk the team already flagged).

## Current state

- `backend/app/Services/ActiveTenantResolver.php`, `resolveForRegularUser` (lines 67–90):
  ```php
  $userTenantIds = $user->tenants()->pluck('tenant.id')->map(fn ($id): string => (string) $id)->all();
  if (empty($userTenantIds)) { return null; }

  $requestedTenantId = $request->header('X-Tenant-Id');
  if ($requestedTenantId && in_array((string) $requestedTenantId, $userTenantIds, true)) {
      if ($tenant = Tenant::find($requestedTenantId)) { return $tenant; }   // 2nd query
  }
  $lastId = $user->last_active_tenant_id ? (string) $user->last_active_tenant_id : null;
  if ($lastId && in_array($lastId, $userTenantIds, true)) {
      if ($tenant = Tenant::find($lastId)) { return $tenant; }              // 2nd query
  }
  return Tenant::find($userTenantIds[0]);                                    // 2nd query
  ```
  The `pluck('tenant.id')` (query 1) throws away the full Tenant rows the same relation
  could return, forcing a `Tenant::find` (query 2).
- `EnsureSubscriptionActive.php` (≈98–112): resolves the tenant then calls
  `SubscriptionStateService::forUser()` per request; comment (≈100–111) notes ~3–5 queries,
  caching "deliberately NOT implemented yet."
- `SubscriptionStateService.php` (≈77–90): `forTenant()` runs an `active()->get()` plus a
  second `->exists()` when empty.
- Note the header-validation semantics MUST be preserved: X-Tenant-Id is honored ONLY if
  the user is a member (this is the tenancy safety check — see plan-era security review).

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Syntax | `cd backend && php -l app/Services/ActiveTenantResolver.php` | clean |
| Lint | `cd backend && vendor/bin/pint --dirty` | exit 0 |
| Test | `cd backend && php artisan test --filter=ActiveTenantResolver` and `--filter=SubscriptionActive` and `--filter=Tenant` | all pass |

## Scope

**In scope**:
- `ActiveTenantResolver::resolveForRegularUser` — fetch the user's tenants ONCE as full
  models and select from that in-memory collection, eliminating the second query.

**Out of scope (record as follow-up in the index, do NOT attempt here)**:
- Caching the whole subscription-state recompute — MED-HIGH risk (serving a paid user as
  blocked or a lapsed trial as active if done naively); requires wiring invalidation into
  the write paths the team enumerated (`EntitlementService::grant/recompute`,
  `StripeWebhookListener`, `SubscriptionPaymentService::recordManual`,
  `ExpireManualEntitlementsCommand`). Separate plan.
- `SubscriptionStateService` internals.

## Steps

### Step 1: Fetch tenants once, select in memory

Rewrite `resolveForRegularUser` to load the membership tenants as full models once and
pick from them, preserving the exact priority order (X-Tenant-Id if member → last_active
if member → first):

```php
$tenants = $user->tenants()->get()->keyBy(fn ($t) => (string) $t->getKey());
if ($tenants->isEmpty()) { return null; }

$requestedTenantId = $request->header('X-Tenant-Id');
if ($requestedTenantId && $tenants->has((string) $requestedTenantId)) {
    return $tenants->get((string) $requestedTenantId);
}
$lastId = $user->last_active_tenant_id ? (string) $user->last_active_tenant_id : null;
if ($lastId && $tenants->has($lastId)) {
    return $tenants->get($lastId);
}
return $tenants->first();
```

This keeps the membership check (only member tenants are in `$tenants`) and drops the
second query. Confirm `$user->tenants()` returns `Tenant` models (not the pivot) and that
`getKey()` is the tenant id.

**Verify**: `php -l` clean.

### Step 2: Tests

- Preserve behavior: X-Tenant-Id of a member tenant → that tenant; X-Tenant-Id of a
  non-member → falls through (NOT honored); last_active member → that; else first.
- If the harness can count queries (e.g. `DB::enableQueryLog()` in a test), assert
  resolution issues one membership query, not two. Otherwise assert behavioral parity only.

**Verify**: `php artisan test --filter=ActiveTenantResolver` (and `--filter=SubscriptionActive`) green.

### Step 3: Format

**Verify**: `vendor/bin/pint --dirty` → exit 0.

## Done criteria

- [ ] `resolveForRegularUser` issues a single membership query; priority order + membership
      check preserved
- [ ] `php artisan test --filter=ActiveTenantResolver` / `--filter=SubscriptionActive` green
- [ ] `php -l` clean, `vendor/bin/pint --dirty` exit 0
- [ ] Only `ActiveTenantResolver.php` (+ test) modified
- [ ] `plans/README.md` row updated, with the caching follow-up recorded

## STOP conditions

- The resolver differs from the excerpt (drift).
- `$user->tenants()` is a `belongsToMany` whose `get()` is materially heavier than the
  pluck for users in many tenants (unlikely — users belong to few clusters) — if a user
  can belong to hundreds of tenants, keep the pluck-then-find but `find` only once; report.
- The X-Tenant-Id membership semantics can't be preserved with the in-memory approach —
  STOP; the security check is non-negotiable.

## Maintenance notes

- The big win is the deferred cache; this plan just removes the free waste. Link the cache
  follow-up here.
- Reviewer: the ONLY behavioral invariant that matters is "X-Tenant-Id honored iff member"
  — verify it survives.
