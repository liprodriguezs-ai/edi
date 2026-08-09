---
name: billing-v2-admin-seam
description: Billing v2 admin seam review: BillingPackage CRUD, PlanPriceMatrix, change-billing-plan (owner self-serve), landing plan keys (2026-07-01)
metadata:
  type: project
---

# Billing v2 admin seam (2026-07-01)

## Scope
- Seam 1: `BillingPackageController` (Orion `/billing_package`) ↔ `billingPackage/schema.ts` + `BillingPackageResource.ts`
- Seam 2: `PlanModulePriceController` (`GET/PUT /billing/plan/{id}/price-matrix`) ↔ `PlanPriceMatrix.tsx` + `PlanPriceMatrixResource`
- Seam 3: `ConfigurationController.changeBillingPlan/previewBillingPlanChange` ↔ `CheckoutSubscriptionResource.changeBillingPlan/previewBillingPlanChange` + `ChangeBillingPlanModal`
- Seam 4: Landing `?plan=` keys ↔ `BILLING_PLAN_KEYS` ↔ `PlanModuleMatrixSeeder`

## Findings

### WARNING 1 — suggested_unit_min/max min:0 vs min:1
- `frontend/src/modules/billingPackage/schema.ts:19,23`: `.min(0)`
- `backend/Modules/SubscriptionPlan/app/Http/Requests/BillingPackageCreateRequest.php:24`: `'min:1'`
- Effect: user enters 0, FE form passes, backend returns 422 with no inline field error.

### WARNING 2 — IPlanPriceCell.trial_days non-nullable but DB is nullable
- `frontend/src/modules/billingPackage/schema.ts:48`: `trial_days: number` (no `| null`)
- `backend/Modules/SubscriptionPlan/database/migrations/2026_07_01_000003_create_plan_module_price_table.php:25`: `nullable()`
- Effect: `String(null)` = `"null"` shown in price matrix input. Saves as 0 via `parseInt("null") || 0`. Visual bug.
- Same issue in `IPlanPrice.trial_days: number` (used for list includes).

### WARNING 3 — changeBillingPlan response data shape mismatch
- Backend `ConfigurationController.php:1549-1555` returns `{ "data": { "billing_package_id": "...", "subscription": {...} } }`
- Frontend `CheckoutSubscriptionResource.ts:207` passes data to `parseMutation()` which tries `multiItemSubscriptionSchema.safeParse(payload.data)`
- `multiItemSubscriptionSchema` expects `{ plan, modules, interval, total, currency }`, not `{ billing_package_id, subscription }`
- `safeParse` always fails → `data: null` in `IMultiItemMutationResult`
- No crash (safeParse + caller only uses `message`), but inconsistent with add-addon/remove-addon/change-interval which DO return multiItemSubscription in `data`

## Aligned
- All 5 plan keys (basico/inicial/esencial/avanzado/premium) consistent across landing → BILLING_PLAN_KEYS → PlanModuleMatrixSeeder
- GET /billing/plan/{id}/price-matrix response shape: exact field match
- PUT /billing/plan/{id}/price-matrix body: exact match `{ cells: [{billable_module_id, interval, unit_amount, is_free?, trial_days?, currency?}] }`
- stripe_price_id never in PUT body (correctly read-only)
- Money conversion: backend stores minor units; FE divides by 100 for display, multiplies by 100 for save (Math.round)
- Preview endpoints: `{ plan_key }` request and `preferentialSwapPreviewSchema` response aligned
- changeBillingPlan request: `{ plan_key, proration_date? }` aligned
- Route paths all aligned; no prefix-match ambiguity
- BillingPackage CRUD fields all match (key, name, description, is_public, is_active, sort_order, suggested_unit_min, suggested_unit_max)

## Recurring pattern note
`trial_days: number` (non-nullable) in TS when DB column is `nullable()` is a recurring nullability gap.
All `IPlanPrice` and `IPlanPriceCell` should have `trial_days: number | null`.
