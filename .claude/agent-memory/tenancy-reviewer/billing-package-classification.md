---
name: billing-package-classification
description: Phase-1 billing catalog (billing_package, billing_package_price, billing_package_module) is CENTRAL platform-wide; public catalog/quote routes are intentionally untenanted
metadata:
  type: pattern
---

# Billing Phase 1 — Catalog + Quoter classification

Lives in `Modules/SubscriptionPlan`. Reviewed 2026-06-30.

## CENTRAL (confirmed correct — do NOT re-flag as tenant-scoped)
- `billing_package`, `billing_package_price` → migrations use `BaseModel::columns($table, false)` (no `tenant_id`); models use `CentralConnection` (consistent with sibling `SubscriptionPlan`, `Country`, `Municipality`, `System`, `User`, `Role`). NO `BelongsToTenant`.
- `billing_package_module` → plain pivot (UUID PK with `uuid_generate_v4()` DB default per laravel-backend-architecture §2.9), FKs to `billing_package` + `module`, no `tenant_id`. Both parents central → no cross-connection.
- **Why central:** the pricing catalog is identical for every tenant (platform-wide), shown on the public landing pricing page. Per-tenant copies would be wrong.

## Public routes are intentionally UNTENANTED (confirmed correct)
- `GET /api/billing/catalog`, `POST /api/billing/quote` in `Modules/SubscriptionPlan/routes/api.php`.
- Carry ONLY `api` + `throttle:60,1`. NO `auth:sanctum`, NO `initializeTenancy`.
- Verified: module `RouteServiceProvider::mapApiRoutes` adds only `api`+`prefix(api)`; `bootstrap/app.php` registers `initializeTenancy` as an ALIAS only (not appended to the `api` group). So nothing initializes tenancy on these routes. No `tenant_id` filtering anywhere → no leak. Mirrors public onboarding/stripe-webhook style in `routes/api.php`.
- Controllers extend `Illuminate\Routing\Controller` (NOT the QCH Orion base) on purpose — public, non-Orion, no permission/token machinery. Correct here.

## CORS gap (cross-origin, feature works locally only)
- Landing is a SEPARATE origin (e.g. kivo7.com) hitting backend api host. `config/cors.php` `paths` covers `api/*`, but `allowed_origins` in non-local env = fixed `CORS_ALLOWED_ORIGINS` list (default: localhost:5173 + a DO app) that does NOT include the landing domain. Local env returns `['*']` so dev works and tests (server-side) pass.
- **Fix on deploy:** add the landing origin to `CORS_ALLOWED_ORIGINS`, else the browser blocks the quoter in staging/prod.

## Money cast note
- `BillingPackagePrice.unit_amount` cast as `integer` (NOT `MoneyIntegerCast`). Deviates from CLAUDE.md rule 7 but matches sibling `SubscriptionPlan.price` (integer) and is REQUIRED for PricingService arithmetic + the raw-int JSON contract. Accept; owned by laravel-architecture-reviewer, not tenancy.
