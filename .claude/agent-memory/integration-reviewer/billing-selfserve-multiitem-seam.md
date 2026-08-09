---
name: billing-selfserve-multiitem-seam
description: Billing fase1 self-serve MULTI-ITEM management seam — GET /config/subscription (multi_item block) + POST /config/subscription/{preview,add-addon,remove-addon,change-units,change-interval} ↔ SubscriptionPage/MultiItem manager+4 modals. Static review 2026-06-30, ALIGNED, zero criticals.
metadata:
  type: project
---

# Billing self-serve multi-item management seam (reviewed 2026-06-30, branch feat/billing-fase1)

Static contract review. Verdict: ALIGNED, zero criticals. Owner-only post-purchase
management of the multi-item subscription (base + add-ons on ONE Stripe sub).
BE: ConfigurationController (getSubscription + 5 new methods + presenters),
MultiItemSubscriptionService (previewChange + addAddon/removeAddon/updateUnits/changeInterval),
Configuration/routes/api.php. FE: checkoutSubscription/schema.ts + CheckoutSubscriptionResource.ts,
user/SubscriptionPage.tsx + MultiItemSubscriptionManager/Card + Add/Remove/ChangeUnits/ChangeInterval
modals + ProrationPreviewPanel + subscriptionPresentation + useSubscription.refresh + useSubscriptionChange.

## Contract shapes — EXACT match
- change_type enum: FE `SubscriptionChangeType = add_addon|remove_addon|change_units|change_interval`
  == BE `Rule::in([...])`. ✓
- Preview request `{change_type, package_key?, units?, interval?}`: conditional-required mirrors BE
  required_if exactly. Each modal sends only its change_type's fields (Add→package_key, Remove→package_key,
  Units→units(parseInt), Interval→interval). ✓
- Preview response `{proration_amount:int SIGNED, proration_date:int|null, new_recurring_total:int,
  currency:'MXN', effective_at:ISO8601}` wrapped `{data}`. FE subscriptionChangePreviewSchema.parse(data.data)
  HARD-parses — field-for-field. ✓ (neg proration_amount = credit → panel shows green "+$…".)
- multi_item block `{packages:[{key,name,type,quantity,interval,unit_amount,subtotal}],units,interval,total,
  currency}` + `is_multi_item`. FE multiItemSubscriptionSchema field-for-field. type enum base|addon ==
  BillingPackage::TYPE_BASE/TYPE_ADDON ('base'/'addon'). interval month|year == BillingPackagePrice::INTERVALS. ✓
- Money: ALL minor units on the wire; FE formatPrice ÷100 uniformly; FE NEVER recomputes subtotal/total
  (uses backend values directly, no client-side quantity×unit_amount) → backend is sole money authority. ✓
- GET reads unit_count + unit_limit (present in response); FE getMySubscription maps them (null-safe). ✓

## proration_date pin-on-apply — where it's used (the key asymmetry)
- add-addon: FE sends proration_date, BE validates (`sometimes|nullable|integer`) AND service.addAddon($…,$prorationDate). USED ✓
- change-interval: FE sends proration_date, BE validates AND service.changeInterval($…,$prorationDate). USED ✓
- remove-addon: FE sends ONLY package_key; BE validation has ONLY package_key; service.removeAddon takes no
  prorationDate (Cashier removePrice can't pin). Consistent ✓
- change-units: **DRIFT (LOW).** FE changeUnits(units, preview.proration_date) SENDS proration_date; BE
  changeSubscriptionUnits validation ACCEPTS it (`sometimes|nullable|integer` → NO 422) but the controller
  NEVER passes it to `$service->updateUnits()` (signature has no prorationDate param) → silently dropped.
  Consequence: the previewed proration for change-units is NOT pinned; actual charge = Stripe-computed at
  apply "now" (sub-cent drift since apply follows preview in secs; matches documented "estimate" behavior).
  Not a runtime break. Inconsistent that units accepts-but-ignores while add-addon/interval pin.

## Error shape (422/409)
- BE codes: cannot_remove_base, contact_sales, interval_unavailable, interval_unchanged, owner_unresolved,
  addon_not_contracted (all 422), no_multi_item_subscription (409). Some 422s are message-only (no code:
  resolveAddonForAdd throws, "no billable price…", generic preview fail).
- FE useSubscriptionChange: BOTH runPreview + apply use `formatApiErrorMessage(err).title` → surfaces backend
  `message` (toast on apply, inline panel w/ Reintentar on preview). Does NOT branch on `code` — shows message
  only. Satisfies "at least show message"; can't render a distinct contact-sales CTA vs validation error (same
  MEDIUM as billing-checkout-seam.md; LOW here since message UX is acceptable for self-serve). contact_sales is
  caught at PREVIEW time (guardUnitsWithinBands runs in resolveTargetConfiguration) so user sees it pre-confirm. ✓
- SUBSCRIPTION_INACTIVE interceptor is 403-ONLY (handle403). 422/409 pass straight to rejectWithApiError →
  ApiError with payload (message+code) preserved. Interceptor does NOT swallow these. CONFIRMED ✓

## 403 coupling (informational, unreachable)
- The 5 endpoints do NOT set skipPermissionsModal. A requireAbility('manage.subscription') 403 (message
  "Unauthorized action.", NO code) would pop the global PermissionsModal. NOT reachable via UI: management UI
  (buttons+modals) renders only for ACCOUNT_OWNER (SubscriptionPage isOwner gate) AND manage.subscription is
  seeded to account_owner+administrator → an owner always has the ability. Even GET /config/subscription is
  gated manage.subscription. Defensive only.

## Eventual consistency (point 4) — COHERENT
- Apply endpoints return `data: presentMultiItemSubscription($tenant->fresh())` = PRE-webhook state
  (entitlements reconciled async by customer.subscription.updated via StripeWebhookListener, NOT written
  synchronously). FE apply() destructures ONLY `message` and DISCARDS `data` (parseMutation.data unused) →
  correctly avoids rendering stale state. Then onApplied→useSubscription.refresh = reloadSilently (re-GET,
  no isLoading flash) + refreshSession (menu/systems can change). Communicated via copy: add-addon message +
  RemoveAddon body say "se reflejará al confirmarse el pago". LOW: re-GET right after may still show old state
  until webhook lands (no polling / refetch-until-changed); user reopens page to see it. Known+communicated.

## Point 5 — allowlist CONFIRMED
- EnsureSubscriptionActive::ALLOWLIST has `api/config/subscription` + `api/config/subscription/*` matched via
  `$request->is(...)` → all 5 mgmt endpoints reachable by a BLOCKED owner (can manage/pay). ✓

## LOW extras
- GET /config/subscription read path is NOT zod-validated (fetchConfigSubscription casts data.data to TS
  interface, no runtime parse). Only mutation responses (safeParse) + preview (parse) are validated. A backend
  multi_item drift would silently yield a malformed object on read rather than fail loudly. Low risk (monorepo).
- per-package `interval` field in schema is unused by the card (card uses top-level multiItem.interval). Benign.
