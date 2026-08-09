---
name: billing-manual-payments-slice
description: Billing manual-payments batch (subscription_payment ledger + payment_method + expire-manual cron + super-admin endpoints) — tenant isolation verified SAFE
metadata:
  type: pattern
---

# Billing — Manual payments (transfer/cash) slice

Reviewed 2026-06-30. Verdict: PASS (isolation SAFE, no CRITICAL). Files:
`SubscriptionPayment.php` (+ migration), `SubscriptionPaymentService.php` (recordManual,
changePaymentMethod), `SubscriptionPaymentController.php` + `SubscriptionPaymentStoreRequest.php`,
`ExpireManualEntitlementsCommand.php` (cron `billing:expire-manual-entitlements`),
`Tenant.php` (payment_method + migration), `PricingService.php` (is_public filter),
`add_is_public_to_billing_package_tables` migration, `routes/console.php`, `routes/api.php`.

## Confirmed-correct isolation patterns (do NOT re-flag)
- **subscription_payment is CENTRAL**, identical pattern to tenant_entitlement:
  migration `BaseModel::columns($table,false)` (no auto tenant_id), explicit
  `uuid('tenant_id')` + FK to tenant cascadeOnDelete; model `use CentralConnection`,
  NO BelongsToTenant. Keyed by explicit tenant_id. `stripe_invoice_id` global-unique
  (nullable → many NULLs ok for manual). `index(['tenant_id','paid_at'])`.
- **recordManual() never relies on TenantScope** (central models have none). Period
  anchor reads `$existing = TenantEntitlement::where('tenant_id',$tenant->id)->where('billing_package_id',$package->id)`
  → cycle calc cannot leak another tenant's expiry. Ledger insert sets explicit tenant_id.
  grant() upsert conflict target (tenant_id, billing_package_id) → per-tenant, race-safe.
  All in ONE DB::transaction.
- **Cron filters EXPLICITLY and cannot block card clients:** tenant filter
  `whereIn('payment_method',[transfer,cash])` (card tenants 100% excluded);
  entitlement filter `source = super_admin_free` + `whereNotNull expires_at` +
  `expires_at < now`. Never touches stripe-sourced entitlements; recompute()'s
  scopeActive keeps any still-active stripe entitlement's modules ON. recompute targets
  EXPLICIT $tenant (no tenancy init needed). now() read per invocation (Octane-safe).
- **Super-admin endpoints intentionally UNtenanted:** routes `tenant/{tenantId}/...`
  carry auth:sanctum+rejectRefreshToken+authActive, NO initializeTenancy (correct —
  central ops on the URL {tenantId}). Gate in controller ctor: `tokenCan('update.tenant')||'*'`
  (super-admin exclusive per seeded matrix, mirrors TenantSystemController authz-02).
  Every action `Tenant::findOrFail($tenantId)` from URL; index ledger `where('tenant_id',$tenant->id)`.
  `accountOwnerForTenant` uses `$tenant->owners()` (user_tenant pivot constrained to
  tenant_id=$tenant->id; UserTenantScope inert when tenancy uninitialized) → resolves
  THIS tenant's single owner, no cross-tenant subscription resolution.
- **is_public is a PLATFORM visibility flag, NOT tenant scope.** Added to central
  billing_package / billing_package_price. Public catalog cache = single global key
  (catalog is platform-wide); super-admin `catalog($includeHidden=true)` path is NEVER
  cached → can't pollute the public entry. BillingCatalogController calls only
  `catalog()` (public). quote() filters is_public so preferential packages aren't
  self-servable. No tenant dimension here.

## Non-isolation notes (other reviewers)
- `changePaymentMethod`: if a tenant has 0 or 2+ account owners, `accountOwnerForTenant`
  returns null → switching off card does NOT cancel the Stripe subscription (possible
  orphan/double-billing). Financial-correctness edge, NOT isolation — laravel-architecture.
- Mixed-state edge: a transfer/cash tenant that also carries an active stripe entitlement
  + an expired super_admin_free one is flipped to BLOQUEADO by the cron while recompute
  keeps the stripe modules on (status vs access mismatch). Not a card-client block, not a leak.
