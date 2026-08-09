---
name: direct-charge-payment
description: Findings from reviewing "Cobro directo" (DirectChargeSheet) — payment module, POST /charge/direct
metadata:
  type: project
---

## Module reviewed (2026-07-03)
`frontend/src/modules/payment/components/DirectChargeSheet.tsx` + `charge/schema.ts` (`directChargeFormSchema`)
+ `charge/services/ChargeResource.ts` (`directCharge`) + `charge/ChargeListPage.tsx` (button/PermissionGuard/sheet state).
Backend: `Modules/Collections/app/Http/Controllers/ChargeController.php::direct()`.

## CRITICAL: frontend PermissionGuard doesn't match backend's dual-ability check
`ChargeListPage.tsx` gates the "Cobro directo" button with `<PermissionGuard permission="create.charge">` only.
Backend `ChargeController::direct()` requires **both** `create.charge` AND `create.payment`
(`$canCharge && $canPay`, else `AuthorizationException`). A user with only `create.charge` sees the button,
fills the whole sheet, and gets a guaranteed 403 on submit. `PermissionGuard` already supports
`permission={['a','b']} requireAll` — this is a one-line fix that wasn't applied. **Always cross-check
a custom (non-Orion-CRUD) endpoint's actual ability checks in the controller before trusting the
"same key as the entity" assumption in the frontend gate** — Orion CRUD abilities are 1:1 with
`$keyForPermission`, but hand-written custom actions (`§2.10` laravel-backend-architecture) can require a DIFFERENT/composite
set of abilities and the frontend has no compile-time way to know that.

## CRITICAL (systemic, now in 3 files): `paid_at` default uses UTC via `toISOString()`, not local date
`new Date().toISOString().slice(0, 10)` is used to default the payment-date field in:
- `DirectChargeSheet.tsx` (new)
- `RegisterPaymentSheet.tsx:102`
- `SubmitPaymentSheet.tsx:75`

`toISOString()` always converts to UTC. Kivo7 tenants run `America/Mexico_City` (UTC-6, no DST). Any local
time from 18:00 to 23:59 maps to the NEXT day in UTC, so `.slice(0,10)` silently defaults the payment date
to **tomorrow** for the last ~6 hours of every business day. This feeds FIFO ordering/aging and is a real
money/date-integrity bug, not cosmetic. No `todayLocalDateString()` helper exists in `dateHelper.ts` — worth
adding one (e.g. via `now.getFullYear()/getMonth()/getDate()`, no UTC conversion) and swapping it into all
three call sites. Flag this pattern on sight in any new payment/charge form.

## Important: `expected_amount` cast + `coerceAmount` breaks its own stated UX intent
`buildDefaultValues()` sets `expected_amount: undefined as unknown as number` specifically so an untouched
submit shows "El monto es requerido" instead of "El monto debe ser mayor a 0" (per the inline comment).
But `coerceAmount(v)` returns `0` (not `undefined`) for `''`/`null`. The moment a user types then clears the
field, RHF's value becomes `0` — a valid `number` — so Zod's `.positive()` fires with the "must be > 0"
message, not "required", contradicting the author's own comment. Fix `coerceAmount` to return `undefined`
(or `NaN`) for the empty case so the intended message actually shows in both the untouched AND
touched-then-cleared cases.

## Important: file-upload requiredness bypasses the Field/FieldError pattern
The "comprobante obligatorio para métodos distintos a efectivo" rule lives ONLY inside the `submit()`
wrapper as a `toast.error(...)` — never as a Zod `superRefine` or local error state feeding a `<FieldError>`
under `ReceiptField`. `FieldLabel required={!isCash}` visually implies a per-field error should appear
inline like every other required field in the form, but none does; the only feedback is a transient toast
with no persistent, field-associated indication (worse for screen-reader users navigating field by field).
Reason it can't just be a Zod field: the `File` object lives in local `useState`, not in the RHF-managed
schema. Pattern for next time: keep a local `receiptError: boolean` state, set it in `submit`, and render
`<FieldError>` conditionally under the field — same visual contract as RHF-driven errors elsewhere.

## Recurring: RadioGroupItem inside bare `<label>` without id/htmlFor
Same anti-pattern already logged for `ChannelFormModal.tsx`/`ChangeIntervalModal.tsx` (chat-admin-channels,
subscription-multi-item audits) shows up again in `DirectChargeSheet.tsx`'s payment-method radio list.
`<button role="radio">` (Radix) IS technically HTML-labelable so it isn't broken, but the project's own
established fix elsewhere is explicit `id`/`htmlFor` for reliable AT support — keep flagging this on sight,
across three separate modules now, worth eventually turning into an ESLint rule or a shared
`<RadioOption>` molecule instead of hand-rolling the `<label><RadioGroupItem/></label>` wrapper each time.

## Good pattern confirmed: RadioGroup `aria-label` set directly on the group
Unlike `ChangeIntervalModal.tsx` (flagged as missing `aria-labelledby`), `DirectChargeSheet.tsx` sets
`aria-label="Método de pago"` directly on `<RadioGroup>` — a valid, simpler fix for the same recurring gap.
Prefer recommending this (`aria-label` on the Root) over `aria-labelledby` plumbing when the visible
`<FieldLabel>` isn't naturally `htmlFor`-able (i.e., no single control to point at).

## Good pattern confirmed: query invalidation reuses the sibling's exact convention (not dead code)
Invalidating `['statement']`, `['payments']`, `['charges']`, `['delinquency']` after a direct-charge mutation
mirrors `RegisterPaymentSheet.tsx` exactly. Verified via grep that all 4 prefixes have real consumers
elsewhere (`ChargeBatchDetail`, `useBatchUnitCharges`, `useChargeBatchStats`, `TreasurerHome`,
`StatementPage`, `DelinquencyPage`) — this is NOT the "dead invalidateQueries" anti-pattern, it's correct
prefix-based invalidation. Don't flag this shape on sight without grepping for consumers first.

## Minor/suggestions worth repeating if seen again
- `useLazyCatalogs(...) + useEffect(() => enable(), [enable])` is redundant when the enclosing component
  ALREADY only mounts while the sheet/dialog is open (the "extract-child gold standard" from react-frontend-architecture
  §2.9 already gates mounting) — calling plain `useCatalogs(request)` directly avoids an unnecessary extra
  render. Reserve `useLazyCatalogs` for filters/dropdowns that mount ALONGSIDE other always-visible UI.
- `useMemo(() => CATALOG_REQUEST, [])` where `CATALOG_REQUEST` is already a module-level `const` is a no-op
  memoization — harmless but pointless.
- Decorative currency "$" prefix `<span>` before money inputs lacks `aria-hidden="true"` — seen in both
  `DirectChargeSheet.tsx` and `ChargeListPage.tsx`'s discount dialog. Systemic, minor, worth a batch fix.
