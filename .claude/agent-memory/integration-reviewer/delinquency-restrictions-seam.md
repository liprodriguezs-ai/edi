---
name: delinquency-restrictions-seam
description: delinquency-restrictions change — 3 seams (config-settings, /my-restrictions, /package/restricted-units) verified aligned; the one real gap is business-rule 403s opening the global PermissionsModal.
metadata:
  type: project
---

# delinquency-restrictions seam (reviewed 2026-07-14)

## Contract (all three verified field-for-field, NO drift)

1. `GET/PUT /config/delinquency-settings` — `ConfigurationController::getDelinquencySettings/updateDelinquencySettings`
   ↔ `DelinquencyConfigSection.tsx`. Follows the **PackageConfigSection mold**, NOT the dotted-key
   getCollectionSettings (documented no-op bug). Flat wire keys (no `delinquency.` prefix), 6 slugs:
   `restrictions_enabled, min_overdue_amount, block_package_intake, block_amenities, block_visitor_access, notice_text`.
   **Deliberate asymmetry**: GET returns `min_overdue_amount` as a TenantMoney object `{amount,currency,formatted}`,
   PUT accepts an **int in minor units**. FE bridges with `moneyToMinorUnits()` on load and
   `pesosToMinorUnits()` (Math.round → true int) on save. Envelope `{data}` on both.
2. `GET /my-restrictions` — custom route, **flat top-level payload, NO `{data}` envelope**:
   `{ restricted: bool, notice: string, units: [{unit_id, restricted, features:{amenities,visitor_access}, overdue_balance?}] }`.
   PER-UNIT by design (never an ANY-aggregate — a resident can hold one current + one overdue unit).
   `overdue_balance` present ONLY on restricted units, TenantMoney-wrapped at the controller boundary.
   No ability gate (payload only ever describes the caller's own units via `ScopesToResidentUnits`).
3. `GET /package/restricted-units` → `{unit_ids: string[]}`, gated `create.package` on both sides.

## Recurring pattern to check on EVERY future business-rule 403

`apiClient` (`src/shared/api/apiClient.ts`) opens the **global PermissionsModal** for ANY 403 unless the
request opts out with `skipPermissionsModal: true`, or the payload `code` is special-cased in `handle403()`
(only `SUBSCRIPTION_INACTIVE` is, today). A 403 that is a BUSINESS RULE (not a missing permission) will
therefore surface as "no tienes permisos" + the mutation's own toast = two error surfaces, wrong framing.

Found here: `DELINQUENT_RESTRICTED` (403 from `DelinquencyRestrictedException`) is not opted out on any of
POST `/amenity-reservation`, `/visitor`, `/visitor/{id}/approve|reject`, `/package`, `/package/intake-batch`.
Worst case is `approve`/`reject` — those have NO client-side pre-gate, so the 403 is the primary path.
Preferred fix is code-based in `handle403()` (one place, future-proof), not per-call `skipPermissionsModal`.
Precedent for the per-call form: `TenantBillingResource.ts`, `checkoutSubscription/BillingResource.ts`.

## Audience split (honored end-to-end)

`DelinquencyRestrictedException::forResident()` carries `overdue_balance` + `notice_text`;
`::forGuard()` carries NEITHER (privacy — the porter must not learn a unit's debt). The FE respects it:
package intake renders a fixed string matching `GUARD_MESSAGE`, never reads `overdue_balance`; the
resident paths (`ReserveAmenityWizard`, `VisitorFormModal`) read it with optional chaining + fallback copy.

`POST /access/entry-request` (gate flow) is deliberately NOT delinquency-guarded — `AccessService::requestEntry()`
has no `assertNotRestricted()`. The guard hooks are `generateQr()` (hook 2) and `VisitorController::approve/reject`
(hook 3). Do not "fix" entry-request into calling the resident-flavored guard: that would leak the debt to the porter.

## Known fail-open, by design

`useMyRestrictions().unitState(unknownId)` returns `undefined` → consumers treat it as unrestricted
(`restriction?.features.x === true`). Same for a not-yet-loaded `restrictedUnitIds` Set. The backend 403 is
the authority; the client gate is UX only. Also note `/my-restrictions` covers only the CALLER's units, so an
admin/staff selecting someone else's unit never sees the client-side banner — expected, not drift.
