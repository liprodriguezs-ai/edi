---
name: amenity-reservations-module
description: Patterns and issues found in the AmenityReservations frontend (S12-S16, feat/amenity-reservations) — schedule-x integration reference, RegisterReservationPaymentSheet fragile cast, AmenityForm over max-lines
metadata:
  type: project
---

## Reviewed 2026-07-07 on branch `feat/amenity-reservations` (frontend submodule)

Highest-quality feature module seen in this project so far. 82/82 tests passing, `tsc -b`
clean, ESLint zero errors (only 2 pre-existing/expected warnings: App.tsx max-lines, and
React Compiler's expected `react-hooks/incompatible-library` note on RHF's `watch()`).

## Reference patterns worth citing in future reviews

- **schedule-x calendar integration** (`AmenityReservationCalendarPage.tsx` +
  `reservationCalendarMapping.ts`): `useCalendarApp` config is documented as FROZEN at first
  render (`createCalendar()` called once in a `useEffect([])` internally) — callbacks may only
  reference stable functions (`setState`, `navigate`), never closed-over reactive state; data
  flows in imperatively via `calendarApp.events.set(...)` in a separate effect keyed on the
  data. `ScheduleXCalendar` itself calls `calendarApp.destroy()` in its own cleanup — no manual
  dispose needed by consumers (verified by reading `@schedule-x/react`'s dist source). The
  `temporal-polyfill/global` side-effect import (required because schedule-x's bundle
  references bare `Temporal` at runtime without importing it) is correctly documented inline
  everywhere it's needed.
- **Extract-child pattern for lazy catalogs in Sheets/Dialogs** (§2.12 variant 1) applied
  consistently and correctly across `SubmitReservationPaymentSheet`, `RegisterReservationPaymentSheet`,
  `AddSurchargeDialog`, `ReserveAmenityWizard` — wrapper does `if (!isOpen) return null` before
  any hooks, body component owns `useLazyCatalogs`/`useQuery`.
- Pure, unit-tested business-logic modules kept OUT of components: `reserveWizard.ts`
  (step sequencing, deposit-hint math), `amenityAvailability.ts` (day-map lookup), both with
  dedicated `.test.ts` files — good SRP, this is the pattern to hold up as the bar.
- Reuses existing shared infra rather than duplicating: `useEntityDetail`, `EntityNotFound`,
  `ResponsiveDataTable`, and critically reuses the PAYMENT module's own
  `PaymentActionButtons`/`PaymentActionDialogs`/`usePaymentActions` for the payments-on-a-reservation
  card instead of building parallel payment-action UI.
- `createCrudApi<T, Record<string, unknown>, ...>` (loose `TForm`) for `AmenityResource` /
  `AmenityReservationResource`, with a dedicated `buildXPayload()` free function returning
  `Record<string, unknown>`, is a DELIBERATE, precedented project pattern (also used by
  `chargePolicy/buildPolicyPayload.ts`, `charge/services/ChargeResource.ts`) for payloads that
  diverge from FormValues shape (money-unit conversion, conditional nulling). Do not flag as a
  generic "should be typed" issue — it already has an explicit doc-comment rationale in
  `reserveWizard.ts` (`ReservationCreatePayload` interface documents the real shape, the
  function just can't return that type per `TForm`'s structural-typing constraint).

## Issues found (fix-worthy)

- **`RegisterReservationPaymentSheet.tsx` (~line 129)**: `bank_account_id: bankAccountId as string`
  casts away `string | undefined` because `PaymentResource.register`'s payload type requires
  non-optional `string` (built for the admin manual-selection flow). Runtime safety currently
  depends ENTIRELY on a guard clause in a sibling function (`submit()`), not enforced at the
  `mutationFn` call site — if that guard is ever refactored away, `bank_account_id` would
  serialize as the literal string `"undefined"` in the FormData sent to the backend. Same root
  cause the `@infra-cost-reviewer` flagged from a different angle (redundant
  `AmenityResource.show(..., {include:'bank_account'})` fetch on every "Registrar pago" click
  — should read `reservation.amenity.bank_account` from the parent's already-fetched
  `DETAIL_INCLUDE` instead). Fixing the cost issue (passing `amenity` as a prop instead of
  re-fetching) would also make the type honest (parent can guarantee non-null before rendering
  the sheet).
- **`AmenityForm.tsx` is 512 lines** — over the project's ESLint `max-lines` (500, warn).
  Extract at least the "Costo y depósito" `FieldSet` into its own subcomponent.
- **`ReservationFiltersPanel.tsx` (~line 38)**: `s.name as string | undefined` on a catalog item
  from `useLazyCatalogs` — unnecessary/contradictory cast. `ICatalogItem.name` (see
  `useCatalogs.ts`'s own doc comment) is already non-optional `string` by contract; the `?? s.id`
  fallback is dead code.

## Minor/style notes (not worth blocking)

- `AmenitySlotsEditor`/`AmenityBlackoutsEditor` delete on a single click with no `ConfirmDialog`
  — matches existing project convention for lightweight inline "chip" removal (same as
  `AnnouncementForm`'s attachment removal), so not a new violation, just worth a second look
  since blackout date ranges are more operationally significant than a photo.
- `ReserveAmenityWizard`'s `UnitStep` uses a raw `<input type='radio'>` instead of the shadcn
  `RadioGroup`/`RadioGroupItem` used by the sibling payment sheets in the SAME feature —
  consistency nit only, the native radio is itself accessible.
- `App.tsx` grew 684 → 715 lines adding these routes — pre-existing max-lines debt, not
  introduced by this branch, but worth flagging on the next pass that touches routing.

## Cross-cutting (not this reviewer's domain, but confirmed by reading the code — surface it)

`MyReservationsPage.tsx` has an explicit, self-documented security note: the frontend filters
`unit_id in [...]` client-side, but `AmenityReservationController` does NOT scope reservations
to the caller's own units server-side (no `ScopesToResidentUnits`-equivalent, unlike
Collections/Housing). A resident calling `POST /amenity_reservation/search` directly could
enumerate ALL reservations in the tenant (other residents' units/dates/balances). Confirmed
independently by `@infra-cost-reviewer`'s same-day pass
(`.claude/agent-memory/infra-cost-reviewer/amenity-reservations-frontend.md`) and NOT covered
by `@tenancy-reviewer`'s S9-S11 pass (that pass covered surcharges/notifications/cron, not the
list/search endpoint's per-resident authorization — this is an authorization gap WITHIN the
tenant, not a cross-tenant leak, so it's outside classic tenancy-isolation scope too). Needs a
real backend fix (equivalent of `ScopesToResidentUnits` on the controller/index), not a
frontend one.
