---
name: billing-slice3-manual-ledger
description: Billing Slice 3 (manual transfer/cash ledger) — subscription_payment central table, recordManual cycle math, no-Orion controller gate, idempotency gaps. Review 2026-06-30.
metadata:
  type: project
---

Manual-billing batch reviewed 2026-06-30 (additive over the existing entitlements model; 82 Billing tests green, Pint clean). Builds on [[billing-slice2-patterns]].

**Shape**
- `subscription_payment` = CENTRAL ledger (uses `CentralConnection`, explicit `tenant_id` column, no `BelongsToTenant`/TenantScope) — same family as `tenant_entitlement` / `billing_package`. Money `amount` is `unsignedBigInteger` minor units, cast `integer` (NOT MoneyIntegerCast) — consistent with `BillingPackagePrice.unit_amount`, a documented module-level exception to CLAUDE.md rule 7.
- `SubscriptionPaymentService::recordManual()` writes ledger → `EntitlementService::grant(SOURCE_SUPER_ADMIN_FREE)` → backfills entitlement id → unblocks tenant, all in ONE `DB::transaction` (grant + recompute nest as savepoints — safe).
- Cycle math: `periodStart = expires_at > now ? expires_at : now; newExpiry = periodStart + 1 interval` = exactly `max(expiry, now) + 1*interval`. Stacks on active, anchors at now on expired. Correct.
- `changePaymentMethod()` cancels the owner's Stripe sub OUTSIDE any transaction (cancel/cancelNow), then writes `tenant.payment_method`. Octane-safe: no instance state, `now()` per call, Stripe via per-request Cashier (no StripeClient construction).
- Cron `billing:expire-manual-entitlements` (daily, console.php): blocks transfer/cash tenants whose super_admin_free entitlement expired, then `recompute()`. Counterpart of the Stripe webhook for out-of-band clusters.

**Patterns reconfirmed (compliant)**
- No-Orion billing controllers extend `Illuminate\Routing\Controller` + constructor-closure gate with `tokenCan('update.tenant') || tokenCan('*')` — exact mirror of `SubscriptionPlanSystemController` / TenantSystemController (see [[authz-controller-override-not-policy]]). Routes carry `auth:sanctum,rejectRefreshToken,authActive` (NO initializeTenancy — central data, cross-tenant super-admin). Aliases confirmed in bootstrap/app.php.
- Migrations rule 13 fully honored (table comment in closure + every own column commented, ALTER not edit-create, real down()). `stripe_invoice_id` nullable + `->unique()` → idempotency for Stripe replays (Postgres allows multiple NULLs, so manual rows coexist). No code writes stripe_invoice_id yet — reserved for the future Stripe mirror.

**Findings worth re-checking in future changes**
- MEDIUM: no idempotency on MANUAL record — double-submit of POST subscription-payment creates two ledger rows AND stacks two intervals (unique stripe_invoice_id only guards Stripe). No test covers double-submit.
- LOW/MEDIUM: `grant()` upserts on (tenant_id, billing_package_id) overwriting `source`/`stripe_*`; recording a manual payment for a package the tenant holds via Stripe converts it to super_admin_free and nulls stripe ids, with no payment_method precheck (intended flow switches method first).
- LOW: cron blocks if ANY super_admin_free entitlement is expired even when another package's entitlement is still active → can over-block multi-package clusters. Also loads all manual tenants with `->get()` (no chunk/cursor).
- LOW: `updatePaymentMethod` skips Stripe cancellation when tenant has ≠1 account owner (ambiguous/none) — moving off card silently leaves an existing sub uncancelled.
