---
name: billing-entitlement-ledger
description: Phase 2 Slice 1 billing — tenant_entitlement CENTRAL ledger + EntitlementService projector isolation. SAFE pattern; recompute writes explicit-$tenant; config purge bypasses scope correctly.
metadata:
  type: pattern
---

# Billing entitlement ledger (Phase 2 / Slice 1) — tenancy isolation

Reviewed 2026-06-30. Files: `Modules/SubscriptionPlan/app/Models/TenantEntitlement.php`,
`.../database/migrations/2026_06_30_000004_create_tenant_entitlement_table.php`,
`.../app/Services/EntitlementService.php`, `BillingPackageStripeService.php`,
`MultiItemSubscriptionService.php`, `Console/Commands/{BackfillEntitlements,SyncBillingStripe}Command.php`.
All ADDITIVE. Slice 2 (wiring into checkout/webhook + syncFromPlan rewrite) deferred.

## Verdict: SAFE for tenant isolation. No cross-tenant leak / wrong-tenant mutation.

## Why it's safe (the key patterns)
- **`tenant_entitlement` is CENTRAL** — migration `BaseModel::columns($table,false)`, explicit
  `tenant_id` uuid + FK (NOT via `BelongsToTenant`), `unique(['tenant_id','billing_package_id'])`.
  Model has NO `BelongsToTenant`/TenantScope → resolved by explicit `tenant_id` exactly like the
  Stripe webhook resolves Tenant by id. Uses `CentralConnection` like its siblings
  (SubscriptionPlan/BillingPackage/BillingPackagePrice/System all use it).
- **`EntitlementService::recompute($tenant)` writes the EXPLICIT $tenant, never ambient scope**:
  - `grantedModuleIds`: `TenantEntitlement::where('tenant_id',$tenant->id)->active()` (central, explicit key).
  - `syncSystemTenant`: `$tenant->systems()` pivot (system_tenant filtered to that tenant) attach/detach;
    preserves `is_addon=true` manual add-ons; detaches only `is_addon=false` orphans. Same final set as
    `SystemTenantService::syncFromPlan` (parity test asserts it).
  - `syncModuleTenant` → `ModuleTenantService::syncBulk($tenant, …)` → `updateOrInsert(['tenant_id'=>…,'module_id'=>…])` (explicit key, opt-out semantics).
  - **`purgeConfigForRevokedModules`**: `Setting::withoutGlobalScope(TenantScope::class)->where('tenant_id',$tenant->id)->where(fn $q => orWhere slug like 'collections.%'/'package.%')->delete()`.
    OR-prefix closure is correctly GROUPED so tenant_id can't be escaped. This is the canonical
    pattern for a service that may run for a non-ambient tenant (webhook/cron/backfill).
- **Stripe services touch only the CENTRAL catalog + Stripe** (billing_package/_price), zero tenant
  data. `BillingPackageStripeService` resolves StripeClient per-call (Octane-safe).
  `MultiItemSubscriptionService` (not wired) puts `tenant_id` in Stripe subscription metadata = the
  same key the SAFE webhook resolves by.
- **Commands**: `billing:backfill-entitlements` is dry-run-only (rejects non-dry-run), reads
  `TenantEntitlement::where('tenant_id',$tenant->id)`, writes nothing. `billing:sync-stripe` is
  central-catalog-only.

## Non-blocking findings (hygiene, NOT isolation breaches)
1. **No cross-tenant guard TEST.** EntitlementServiceTest covers behavior + parity but never creates a
   2nd tenant to assert recompute(A)/purge(A) leaves B's system_tenant/module_tenant/`collections.*`
   settings untouched. Code is correct, but dropping the explicit `->where('tenant_id',…)` later would
   silently wipe the ambient tenant's config with no failing test. Recommend a 2-tenant guard test.
2. **`recompute()` atomicity spans two connection handles.** Outer `DB::transaction()` = `database.default`;
   `ModuleTenantService::syncBulk` opens its OWN `DB::connection('pgsql')->transaction()`. Atomic ONLY
   because in THIS project `database.default === pgsql` (single physical conn → inner = savepoint). If a
   distinct `tenancy.database.central_connection` is ever set, module_tenant writes commit separately
   from system_tenant/Setting → partial-projection on mid-recompute failure. Integrity risk, not a leak.
3. `'pgsql'` hardcode + `CentralConnection` are inherited single-DB-contradicting artifacts (config key
   `tenancy.database.central_connection` is null → resolves to default). Harmless today, consistent with
   existing ModuleTenantService/central models; flag for the team's single-DB doctrine cleanup.

## Cross-ref
- Builds on [[stripe-webhook-subscription]] (same "central Tenant resolved by id, no scope" pattern).
- TargetResolver by_feature ALL→ANY change was bundled in the worktree — Collections business logic,
  isolation-neutral (stays on tenant-scoped billableQuery), out of tenancy scope.
