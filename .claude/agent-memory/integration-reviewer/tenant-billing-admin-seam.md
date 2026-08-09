---
name: tenant-billing-admin-seam
description: Phase-2 Slice-5 super-admin tenant billing seam — GET /tenant/{id}/billing + TenantBillingModal consuming 8 endpoints. TWO CRITICAL breaks found (registerPayment field name, proration_date type). Reviewed 2026-06-30.
metadata:
  type: project
---

# Super-admin tenant-billing seam (Phase 2 / Slice 5, reviewed 2026-06-30)

Backend new: `TenantBillingController@show` (GET tenant/{id}/billing) + route. Frontend new:
`modules/tenant/billing/{schema.ts,billingErrors.ts}`, `services/TenantBillingResource.ts` (8 endpoints),
`hooks/useTenantBilling.ts`, `components/TenantBillingModal.tsx` + `components/billing/*`. The frontend
consumes 8 endpoints but the slice only ADDED endpoint #1 — the other 7 are prior-slice backend routes
(SubscriptionPaymentController, TenantEntitlementController, PreferentialSwapController) consumed by a
React client for the FIRST time, so their contract is in scope.

## Endpoint map (all 8 paths + methods ALIGNED)
| FE resource method | Route | Controller |
|---|---|---|
| getBilling GET /tenant/{id}/billing | ✓ NEW | TenantBillingController@show |
| getLedger GET /tenant/{id}/subscription-payment | ✓ | SubscriptionPaymentController@index |
| changePaymentMethod PATCH /tenant/{id}/payment-method | ✓ | @updatePaymentMethod |
| registerPayment POST /tenant/{id}/subscription-payment | ✓ path | @store |
| grantFree POST /tenant/{id}/entitlement/grant-free | ✓ | TenantEntitlementController@grantFree |
| revoke POST /tenant/{id}/entitlement/revoke | ✓ | @revoke |
| previewPreferentialSwap POST /tenant/{id}/preferential-swap/preview | ✓ | PreferentialSwapController@preview |
| applyPreferentialSwap POST /tenant/{id}/preferential-swap | ✓ | @swap |

## TWO CRITICAL contract violations
1. **registerPayment field-name + type mismatch.** FE `ManualPaymentSection` (ManualPaymentSection.tsx:61)
   + `IRegisterPaymentRequest` send `package_key:string`. Backend `SubscriptionPaymentStoreRequest:29`
   requires `billing_package_id` (uuid, exists:billing_package,id) — `package_key` is ignored → every
   "Registrar pago" → 422 `{errors:{billing_package_id:[required]}}`. COMPOUNDED: GET /billing
   `available_packages` exposes only `key` (NOT `id`) — FE has no UUID to send. The OTHER 3 package-keyed
   endpoints (grantFree/revoke/swap) correctly use `package_key`; store is the outlier. FIX (pick the
   consistent one): backend store accepts `package_key` and resolves the package (mirror grantFree's
   `activePackageOrFail`). Do NOT instead add `id` to the read shape — that fights the rest of the seam.
2. **proration_date type mismatch (card-swap preview/apply roundtrip).** Backend `previewCard`
   (PreferentialSwapService.php:55-69) returns `proration_date:int|null` (Stripe Unix timestamp). FE
   `preferentialSwapPreviewSchema.proration_date: z.string().optional()` (schema.ts:96) → Zod `.parse()`
   THROWS on EVERY card preview (int or null both fail string|undefined) → PreferentialSwapDialog shows
   previewError, Apply stays disabled (preview null). Card swap is DEAD; manual swap unaffected (no
   proration_date). Also apply req `IPreferentialSwapRequest.proration_date?:string` vs backend
   `['nullable','integer']`. FIX FE: preview field → `z.number().nullable().optional()`; request field →
   `number`; display `displayFormatDate(proration_date)` must convert Unix-seconds (×1000 / new Date(s*1000))
   — currently feeds a raw timestamp to a date-string formatter.

## ALIGNED (reinforce)
- GET /billing summary shape matches `billingSummarySchema` field-for-field: payment_method (Tenant default
  'card', backfilled — never null, enum safe), status (z.string, no enum lock — safe), entitlements,
  available_packages (key/name/type/is_public/preferential/prices.month|year.unit_amount), ledger,
  ledger_total. Money = integer minor units on wire; FE `formatPrice` ÷100 (ledger, total, swap lines,
  amount_due, new_price). FE never computes amounts.
- changePaymentMethod {payment_method:card|transfer|cash, cancel_mode?:at_period_end|now} === backend
  Tenant::PAYMENT_METHODS + SubscriptionPaymentService::CANCEL_MODES. grantFree/revoke {package_key} ===
  backend. swap preview/apply {from_package_key,to_package_key} === backend.
- All mutations pass `skipPermissionsModal:true` → business 403/422 render inline via resolveBillingError,
  not the global PermissionsModal (same pattern as billing-checkout Slice 2/3).
- BillingResource uses Zod `.parse()` on reads → contract drift degrades to clean error state.

## WARNING / drift
- entitlements: backend `$entitlement->billing_package?->key|name|type` (nullable via ?->) vs FE
  `package_key/package_name/type: z.string()` (non-nullable). If a billing_package is ever missing/deleted,
  the WHOLE summary parse throws → panel error. Either drop `?->` (guarantee non-null) or make FE nullable.
- getLedger (GET /subscription-payment) is wired in the resource but UNUSED — panel reads summary.ledger +
  invalidates after registerPayment. Note the two ledger sources differ: /subscription-payment returns FULL
  models (+billing_package,+recorded_by), GET /billing returns the narrow 6-field rows; FE non-strict Zod
  tolerates both. Dead path today.

## UPDATE 2026-06-30 — manual-payment REDESIGN (both criticals RESOLVED, contract re-verified)
Redesign: ONE manual payment now covers ALL paid modules (multi-package), source 'manual' (not courtesy),
per-module price derived from plan. BOTH prior criticals fixed:
1. **registerPayment field-name (CRITICAL #1) RESOLVED.** Request reshaped to `{method, paid_at, notes?,
   lines:[{package_key, amount(cents), interval?}]}`. Backend `SubscriptionPaymentStoreRequest` now validates
   `lines.*.package_key` (`exists:billing_package,key`) + `lines.*.amount` (int min:1 max:1e9). Controller
   resolves each key (inactive → explicit 422). FE sends `package_key` everywhere now — consistent with
   grant/revoke/swap. The package-key↔uuid mismatch is gone.
2. **proration_date type (CRITICAL #2) RESOLVED** (prior commit, confirmed live). schema `proration_date:
   z.number().int().nullable().optional()`; request `?:number`; PreferentialSwapDialog displays via
   `new Date(proration_date*1000)` (Unix-seconds→ms) gated by `typeof === 'number'`. Card swap works.

New contract surface (all ALIGNED field-for-field; CI-locked by TenantBillingReadEndpointTest assertExactJson):
- GET /billing `entitlements[]` +`units`(int, default 1) +`amount`(?int, plan-derived; courtesy=0,
  misconfig=null). FE billingEntitlementSchema both nullable.
- GET /billing NEW `manual_payment:{lines:[{package_key,name,units,interval,amount}], total}` (cents). One
  line per PAID contracted package. `amount`==PricingService::priceForPackage (reuses buildLine → equals that
  package's line in a public quote; also prices is_public=false preferential pkgs, unlike quote()).
- Money: SubscriptionPayment.amount cast 'integer' (plain int on wire, NOT Money obj) → ledger/total safe.
  FE pesos*100 on send (Math.round), /100 on display. amounts in cents end-to-end. ✓
- source 'manual' → FE SOURCE_LABELS['manual']='Pagado (manual)'. TenantEntitlement::SOURCE_MANUAL='manual'. ✓
- Defensive gates aligned: FE renders ManualPaymentSection only when payment_method!=='card'; hides form when
  manual_payment.lines empty. Backend recordManual mirrors (card→422, all-courtesy→422).

WARNING (carry forward): `manualPayment()` excludes ONLY super_admin_free + stripe — so `trial` and
`legacy_plan` entitlements LEAK into manual_payment.lines as chargeable rows (FE renders them blindly). Verify
backend intent: if trials should not appear in the "cover everything" charge, filter to manual/paid-renewable
only. Seam itself is consistent (FE trusts the line set); this is a backend domain-intent question.
LOW: form amount_pesos `.positive()` (not min .01) → 0.004 pesos → Math.round*100=0 → backend min:1 422 with
generic message. Consider `.min(0.01)` for a field-level message.
