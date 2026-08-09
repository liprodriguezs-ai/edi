---
name: direct-charge-feature
description: Cost review of "Cobro directo" (Modules/Collections direct charge) — frontend catalog loading is clean gold-standard; backend has a redundant/unused relation-load waste in ChargeController::direct()
metadata:
  type: project
---

Reviewed 2026-07-03. Feature: `POST /charge/direct` (counter/window direct charge +
immediate pinned payment, one atomic action). Files: `DirectChargeSheet.tsx`,
`ChargeListPage.tsx`, `ChargeResource.ts`, `DirectChargeService.php`, `ChargeController.php`.

**Confirmed CLEAN (do not re-flag) — frontend catalog loading:**
- `DirectChargeSheet.tsx` follows the exact §2.9/§2.12 gold-standard shape: outer
  component does `if (!isOpen) return null` before mounting `<Sheet>`, body (+
  `useLazyCatalogs` for PAYMENT_METHOD/BANK_ACCOUNT/CHARGE_CONCEPT) lives in a child
  (`DirectChargeSheetBody`) that only mounts while open. `ChargeListPage` renders the
  sheet unconditionally (`isOpen` prop) but that's fine — the early-return lives inside
  the sheet itself, so `POST /selects` never fires on list mount.
- `UnitCombobox.tsx` (unit picker in the sheet) is a proper debounced typeahead
  (`POST /unit/search`, `enabled: debounced.length>=1`, `limit:8`) — not a catalog load.
  Good reusable pattern for "big lookup, few results" fields — cite as an example when
  reviewing other pickers that reach for a full catalog instead of a typeahead.

**New backend anti-pattern found (WARNING) — redundant + unused eager-load in a custom
Orion action response:**
`ChargeController::direct()` (`Modules/Collections/app/Http/Controllers/ChargeController.php:330-337`)
does `$result['payment']->load(['applications','applications.charge','payment_status',
'payment_method','bank_account','media'])` AFTER `PaymentService::register()`
(`PaymentService.php:102`) already did `$payment->load('applications','payment_status',
'payment_method')` earlier in the SAME request. `Model::load()` (unlike `loadMissing()`)
always re-queries even if already hydrated → 3 of those 6 relation calls are pure
duplicate queries. Plus `$result['charge']->load(['concept','charge_status'])` adds 2 more.
Total 8 relation queries to build the 201 response. Checked every consumer
(`grep DirectChargeResult` across frontend): `DirectChargeSheet.tsx` only reads
`result.payment.receipt_number` (a plain column) — **none** of the 8 relation-loaded
fields are ever read by the UI (the list refresh path does its own independent
`onSearch`, ignoring the mutation's return value entirely). Fix: drop the redundant
`->load()` calls in the controller entirely (payment already carries what register()
loaded; charge needs none). If a future consumer needs more fields, use
`loadMissing()` not `load()` to avoid re-querying what the service already fetched.
**General pattern to watch for elsewhere:** any custom Orion action that (a) calls a
Service method which already does `->load(...)` on the return value, then (b) the
controller does ITS OWN `->load([...])` on top before shaping the JSON response — check
for overlap (duplicate queries) and check whether the frontend consumer actually reads
those fields before deciding they're needed at all.

**Not worth flagging (checked, bounded, pre-existing):** `Setting::getValue()` in
`ResolvesPaymentMethodRules::paymentMethodIsEnabled()` queries DB with no cache —
this is shared infra reused from the existing `payment/register` endpoint, not new
code from this feature; out of scope for a single-feature review.
