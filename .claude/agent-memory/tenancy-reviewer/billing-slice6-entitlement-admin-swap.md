---
name: billing-slice6-entitlement-admin-swap
description: Billing Slice 6 — super-admin grant-free/revoke + preferential-swap (preview/apply) + base_preferential seed; tenant isolation verified SAFE
metadata:
  type: pattern
---

# Billing Slice 6 — Entitlement admin + preferential swap

Reviewed 2026-06-30. Verdict: PASS (isolation SAFE, no CRITICAL/WARNING). Files:
`TenantEntitlementController.php` (grantFree, revoke), `PreferentialSwapController.php`
(preview, swap), `PreferentialSwapService.php`, `BillingPackageSeeder.php` (+base_preferential),
`routes/api.php`. Tests: `EntitlementAdminFlowTest`, `PreferentialSwapTest`.

## Confirmed-correct isolation patterns (do NOT re-flag)
- **All 4 endpoints operate on EXPLICIT $tenant from URL** `tenant/{tenantId}/...`:
  `Tenant::findOrFail($tenantId)`; every entitlement read/write is
  `where('tenant_id',$tenant->id)`; grant/recompute take explicit $tenant. Routes
  carry `auth:sanctum + rejectRefreshToken + authActive` and intentionally OMIT
  `initializeTenancy` (super-admin cross-tenant, same as SubscriptionPaymentController).
- **Super-admin gate VERIFIED genuinely exclusive.** Controller ctor middleware:
  `tokenCan('update.tenant')||tokenCan('*')`. RolePermissionTableSeeder line 58 puts
  `update.tenant` in `$permissionsOnlySuperAdmin`; account.owner (line 185) and
  company.admin (line 212) both `whereNotIn($permissionsOnlySuperAdmin)`. So NO regular
  tenant role can hit these → no cross-tenant privilege escalation via a forged {tenantId}.
- **CARD-path owner resolution is tenant-FK-scoped:** `$tenant->owners()` =
  belongsToMany(User,'user_tenant','tenant_id','user_id')->whereHas roles ACCOUNT_OWNER;
  require exactly 1 owner else null. UserTenantScope inert (tenancy uninitialized) so
  pivot tenant_id is the only scope → resolves THIS tenant's owner subscription, no leak.
  Mirrors SubscriptionPaymentController::accountOwnerForTenant.
- **base_preferential is CENTRAL + hidden, NOT tenant-scoped.** BillingPackage/Price are
  central (CentralConnection, no BelongsToTenant); is_public=false is a platform
  VISIBILITY flag (not tenant dimension). Seeded once in central BillingPackageSeeder
  (idempotent updateOrCreate), NOT per-tenant. resolveContext rejects a public target
  (`is_public !== false`) so only the hidden variant is swappable.
- **applyManual** wraps grant(recompute:false)+expire-old+recompute in one DB::transaction,
  all on explicit $tenant; grant() upsert conflict target (tenant_id,billing_package_id)
  is per-tenant/race-safe. **applyCard** repoints $from (this tenant's row) + recompute;
  webhook double-projection is idempotent (same tenant).

## Non-isolation notes (other reviewers / future)
- applyCard does raw `$from->update(['billing_package_id'=>$toPackage->id])` — if the
  tenant ALREADY has an active entitlement on the target package this hits the
  unique(tenant_id,billing_package_id) → 500, not a leak. applyManual avoids it via
  upsert + conditional expire. Correctness edge → laravel-architecture. (Hard to hit:
  resolveContext requires an active from-entitlement, and the first swap repoints it.)
- centralConnection()='pgsql' raw queries inside DB::transaction(default conn): pre-existing
  atomicity-gap pattern (see slice2 memory), works in pgsql prod. Not tenancy.
- No two-tenant isolation regression test (correct by construction but unguarded);
  non-super-admin 403 IS tested. Suggestion only.
