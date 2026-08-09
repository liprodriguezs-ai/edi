---
name: billing-slice4a-subscription-gate
description: Slice 4a subscription enforcement gate + shared ActiveTenantResolver — X-Tenant-Id enforcement bypass (A1) verified CLOSED; isolation SAFE
metadata:
  type: pattern
---

# Billing Slice 4a — Subscription gate + ActiveTenantResolver (A1 fix)

Reviewed 2026-06-30, branch feat/billing-fase1. Files: `app/Services/ActiveTenantResolver.php` (new),
`app/Http/Middleware/InitializeTenancyFromUser.php` (refactor), `app/Services/SubscriptionStateService.php`,
`app/Http/Middleware/EnsureSubscriptionActive.php` (new), `bootstrap/app.php`, `AuthController.php`.
Verdict: A1 bypass CLOSED, isolation SAFE, zero CRITICAL.

## The bypass that was fixed (A1) — CONFIRMED closed
- Root cause: the OLD active-tenant resolution lived in two places that DIVERGED —
  `InitializeTenancyFromUser` honored `X-Tenant-Id`, but `SubscriptionStateService`
  IGNORED it (used last_active/first). A user in cluster A(active)+B(blocked) could
  point enforcement at A (last_active) while the controller served B (header) → 200 on
  blocked B's data.
- Fix: extracted `ActiveTenantResolver::resolve($request,$user)` — a SINGLE pure resolver
  both `InitializeTenancyFromUser` AND `EnsureSubscriptionActive` call with the same
  (request,user). Same inputs → same tenant → zero divergence. On `/charge_batch` with
  X-Tenant-Id:B both pick B; gate evaluates B(blocked) → 403 before controller runs.

## Confirmed-correct isolation patterns (do NOT re-flag)
- `ActiveTenantResolver` (pure, NO side effects): regular users → header ONLY if member
  (`in_array` against `user->tenants()->pluck('tenant.id')`), else last_active (if still
  member), else first tenant. Platform roles (super_admin/lawyer) → header targets any
  tenant, no membership requirement; no header → null (unscoped). Membership validation
  makes cross-tenant header spoofing by a regular user impossible.
- `EnsureSubscriptionActive` is appended to the `api` GROUP (`bootstrap/app.php`), so it
  runs BEFORE route-level `initializeTenancy`. It self-resolves the sanctum user via
  `$request->user() ?? $request->user('sanctum')` and resolves the tenant via the shared
  resolver — it NEVER reads `tenancy()->tenant`, so it does not depend on tenancy being
  initialized. Correct: gate can be a superset boundary, fail-closed.
- `TenantEntitlement` is CENTRAL (`CentralConnection`, `BaseModel::columns($table,false)`,
  NO `BelongsToTenant`/`TenantScope`). `SubscriptionStateService` queries it with EXPLICIT
  `->where('tenant_id',$tenant->id)->active()`. `scopeActive` wraps its OR in a closure
  (`where(fn=> null OR >now)`) so the tenant filter can't escape. Runs with tenancy NOT
  initialized (gate is pre-init) → explicit tenant_id is the sole filter, no ambient leak.
- Persistence of `last_active_tenant_id` was moved OUT of the resolver into
  `InitializeTenancyFromUser::persistActiveTenantSelection` — fires only for regular users,
  only when header === resolved tenant id (explicit pick), via `updateQuietly` (no events/
  audit). Enforcement gate never mutates state (resolver is pure). Platform roles never
  persist and never get the ensureMainCompany guarantee (must not mutate targeted cluster).

## Edge cases verified (no 500, no leak)
- No-membership regular user → resolver null → gate `forUser(user,null)` → forTenant null
  branch → is_blocked=false (passes). Pre-existing behavior (SubscriptionStateService null
  branch); InitializeTenancy also gets null so no tenancy init. Not a regression.
- Nonexistent / foreign X-Tenant-Id (regular) → header branch skipped (not in memberships
  or Tenant::find null) → falls back to last_active/first. No 500, no leak, no persist.
- user null → gate returns $next (public / 401 downstream). Refresh token on protected
  route → gate may 403 before rejectRefreshToken 401s; refresh endpoints are allowlisted
  (`api/auth/*`) so real refresh flow is unaffected. No 500.

## Non-isolation observations (NOT leaks — other owners / UX)
- SUGGESTION: `AuthController` /me + login resolve the REPORTED tenant via last_active/
  first, NOT via `ActiveTenantResolver` (ignores X-Tenant-Id). So the state the frontend
  SubscriptionGuard reads can skew from the tenant the API enforces after a cluster switch.
  Not a leak (server-side gate is authoritative + 403s blocked B; /me is allowlisted &
  only shows the user's OWN tenants). Consider routing /me through the shared resolver for
  reported-vs-enforced consistency.
- infra-cost (owned by infra-cost-reviewer, gate has explicit TODO): state recomputed
  (~3-5 queries) per non-allowlisted request; no cache by design (can't guarantee airtight
  invalidation → risk of a PAID user stuck blocked). persistActiveTenantSelection also does
  a redundant updateQuietly write on every header-bearing request even when unchanged
  (pre-existing).
