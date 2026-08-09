---
name: billing-checkout-slice3-seam
description: Phase-2 Slice-3 React UI (checkoutSubscription PlanBuilder/useBillingQuoter/BillingResource) consuming catalog/quote/checkout. Confirms Slice-2 MEDIUMs resolved; new fields max_units/price_pending/contact_sales aligned; no_billable_price readiness gap. Reviewed 2026-06-30.
metadata:
  type: project
---

# Billing checkout Slice 3 — React UI seam (reviewed 2026-06-30)

Frontend `frontend/src/modules/checkoutSubscription/`: `schema.ts` (Zod), `services/BillingResource.ts`,
`hooks/useBillingQuoter.ts`, `billingBands.ts`, `components/PlanBuilder.tsx`, `SelectPlanPage.tsx`,
plus `shared/api/apiClient.ts` (skipPermissionsModal). Backend untouched (Slice 1/2 controllers).

## Contract VERIFIED ALIGNED field-for-field
- catalog `{currency, max_units:int|null, packages:[{key,name,type:base|addon,pricing_type,price_pending:bool,included_modules:[name],prices:{month?,year?:{unit_amount,tier_mode,tiers}}}]}` — Zod `billingCatalogSchema` matches `PricingService::catalog()` exactly. `type` enum only base|addon (BillingPackage::TYPES) — safe.
- quote `{interval,currency,units,contact_sales:bool,lines?:[{key,name,qty,unit_amount,subtotal,price_pending}],total?}` — matches `PricingService::quote()`. contact_sales=true → no lines/total (Zod optional). 
- checkout success `{url}`; FE reads data.url then `window.location.href=url` → Stripe. success_url/cancel_url=`/select-plan?success=true|canceled=true` → SelectPlanPage OwnerPlanView reads both. Aligned.
- Money: backend cents (integer minor units) → FE `formatPrice` divides /100. FE NEVER computes amounts (backend authority). 
- base magic string: seeder base key == literal 'base'; FE `ensureBasePackage` sends base.key; BillingCheckoutRequest.mustIncludeBase() hardcodes 'base'. Aligned today; latent coupling if base key ever renamed (silent "El paquete base es obligatorio").
- bands: `deriveBands` parses base tiers (`up_to`,`label`,`proposed`); seeder BASE_BANDS_MONTH provides all 5 bounded tiers w/ labels + proposed flag on 1-50. max_units=bandCeiling=300.

## Slice-2 MEDIUMs NOW RESOLVED (the two I flagged) — confirm fixed
1. Error-code discriminator: backend now emits machine-readable `code` on ALL non-validation responses:
   owner_unresolved(422), not_account_owner(403), already_subscribed(409), contact_sales(422),
   no_billable_price(422). FE `handleCheckoutError` branches on `err.payload?.code`. ApiError exposes `payload.code` (ApiErrorPayload). No Spanish-string matching.
2. 403 global-modal coupling: `BillingResource.checkout` passes `{skipPermissionsModal:true}`; apiClient `handle403` early-returns on that flag BUT still `rejectWithApiError` → inline handler fires. So not_account_owner shows inline billing message, NOT the generic PermissionsModal. Pattern: declare-merged `AxiosRequestConfig.skipPermissionsModal` in apiClient.ts.

## Defensive strengths (reinforce)
- BillingResource uses Zod `.parse()` (not safeParse) on catalog/quote → contract drift degrades to clean error state (catalogError/quoteError), never a crash/undefined render.
- checkout always sends clamped units ≤ maxUnits; over-band path uses forcedContact → contact_sales UI + mailto, never calls checkout. So checkout's contact_sales handler is defensive/unreachable from this UI (harmless).

## OUTSTANDING (verdict PASS on contract; readiness caveats)
- MEDIUM (end-to-end readiness, NOT contract drift): seeder leaves `stripe_price_id` NULL (placeholder prices). `MultiItemSubscriptionService::resolveStripePriceIds` skips unsynced prices → [] → throws noBillablePrice → 422 `no_billable_price`. So in current data state EVERY "Contratar" click fails (toast) and there is NO Stripe redirect until the Slice-1 Stripe-sync command populates stripe_price_id. FE handles gracefully but the primary CTA is non-functional until sync runs. VERIFY sync before shipping.
- LOW (UX): `no_billable_price` + generic 422 fallback surface the raw Spanish backend message via toast ("...precio facturable en Stripe") — technical for an owner. Consider a friendly mapped message like the other codes ("La facturación aún no está configurada, contacta a soporte").
- LOW: already_subscribed(409) handled inline w/ message but offers no portal/change-plan CTA (rare race: active sub ⇒ is_blocked=false ⇒ SelectPlanPage redirects to /dashboard before reaching checkout).
