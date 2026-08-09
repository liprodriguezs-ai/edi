---
name: billing-slice2-webhook-checkout
description: Phase-2 Slice 2 billing — multi-item checkout endpoint + Stripe webhook entitlement projection; tenant isolation verified SAFE; patterns not to re-flag
metadata:
  type: pattern
---

# Billing Phase 2 / Slice 2 — Checkout endpoint + webhook entitlements

Reviewed 2026-06-30. Files: `app/Listeners/StripeWebhookListener.php`,
`Modules/SubscriptionPlan/.../BillingCheckoutController.php` + `BillingCheckoutRequest.php`,
`EntitlementService`, `MultiItemSubscriptionService`, `BackfillEntitlementsCommand`,
`routes/api.php`. Verdict: PASS (isolation SAFE, no CRITICAL).

## Confirmed-correct isolation patterns (do NOT re-flag)
- **Webhook never initializes tenancy.** `StripeWebhookListener` resolves tenant
  STRICTLY by id: `resolveTenantFromMetadata` → `Tenant::find(metadata.tenant_id)`.
  `Tenant` is central (no `BelongsToTenant`). No `tenancy()->initialize()` anywhere
  in the listener. Correct by design — documented in the class docblock.
- **Customer fallback is guarded** (`resolveTenantFromCustomer`): `stripe_id` → central
  `User` (UserTenantScope is INERT when tenancy uninitialized — it early-returns, see
  `app/Models/Scopes/UserTenantScope.php` line 20) → MUST hold `account.owner` role →
  MUST own exactly ONE tenant (count !== 1 → null). No cross-tenant guessing.
  checkout.session.completed uses metadata-only (no customer fallback) = safest.
- **EntitlementService operates on EXPLICIT $tenant**, never ambient TenantScope:
  every query `->where('tenant_id', $tenant->id)`; `purgeConfigForRevokedModules` uses
  `Setting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)`.
  `$tenant->systems()` / `moduleTenantService->syncBulk` are tenant-FK-scoped pivots.
- **TenantEntitlement is CENTRAL** (`CentralConnection`, `BaseModel::columns($table,false)`,
  no `BelongsToTenant`), explicit `tenant_id` UUID FK + `unique(['tenant_id','billing_package_id'])`.
  `grant()` upsert conflict target = that unique → atomic, race-safe, can't cross tenants.
- **Backfill iterates tenants, grants per explicit $tenant**; existence check by
  (tenant_id, billing_package_id) → idempotent, never clobbers a stripe entitlement,
  never mixes tenants.
- **Checkout endpoint** (`/api/billing/checkout`, middleware auth:sanctum + rejectRefreshToken
  + authActive + initializeTenancy): tenant from authed user (validated header), owner
  scoped via `$tenant->users()->role(account.owner)`, owner==auth check (403), metadata
  tenant_id = the initialized tenant. A user cannot checkout for another tenant.
- **Idempotency cache** `Cache::add('stripe:evt:'.$id)` runs UNINITIALIZED → central
  (non-tenant-prefixed) cache namespace → global dedup is correct. WARNING for future:
  if anyone ever wraps the listener in tenancy init, CacheTenancyBootstrapper would
  prefix the key per-tenant and break dedup (same event processed once per tenant).

## Scope note (the task's "syncFromPlan adapter rewrite")
- `SystemTenantService::syncFromPlan` was NOT rewritten in this slice (file unchanged
  vs HEAD). The listener still calls the LEGACY syncFromPlan for plan-based checkouts;
  it operates on `$tenant->systems()` (tenant-scoped) → isolation preserved. The
  "adapter to EntitlementService" unification is deferred to B3 per code comments.

## Non-isolation observations (other reviewers / future)
- `EntitlementService::centralConnection()` returns `config('tenancy.database.central_connection','pgsql')`;
  tenancy.php has NO database section → always `'pgsql'`. `config/database.php` default
  is `env('DB_CONNECTION','sqlite')`. Raw `DB::connection('pgsql')->table(...)` calls sit
  OUTSIDE the outer `DB::transaction()` (default conn) if the default conn name ever != 'pgsql'
  → atomicity gap. Pre-existing pattern copied from ModuleTenantService; works in pgsql prod.
  Owned by laravel-architecture / database reviewers, NOT tenancy.
- Idempotency marker is set BEFORE handler runs; entitlement grants run OUTSIDE the
  status-flip transaction (best-effort). If a grant throws after the status flip, tenant
  is left ACTIVO with under-projected access until the next subscription.*/invoice event
  self-heals (reconcileSubscriptionEntitlements). Correctness edge, not isolation.
- No two-tenant regression test (isolation correct by construction but unguarded).
</content>
</invoke>
