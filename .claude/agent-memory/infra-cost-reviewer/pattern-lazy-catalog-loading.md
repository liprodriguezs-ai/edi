---
name: pattern-lazy-catalog-loading
description: The sanctioned wirings that keep POST /selects off list mount — form-open for forms, dropdown-open for filters. Shared hooks useCatalogs(req,{enabled}) + useLazyCatalogs(req).
metadata:
  type: pattern
---

# Lazy-catalog loading — the canonical cost rule

`useCatalogs` (`src/shared/hooks/useCatalogs.ts`) fires `POST /selects` the instant
its calling component MOUNTS. A `useCatalogs` placed at list/page scope, or in a
modal body that the list renders unconditionally (`<Modal isOpen={open}/>` — `isOpen`
only toggles Radix visibility, NOT React mount), leaks form/filter catalogs on every
list view × every tenant. THIS is the canonical leak to hunt.

## Shared hook API (as of 2026-06)
- `useCatalogs(req, { enabled })` — `enabled` defaults `true` (legacy: fetch on mount).
  Pass `{ enabled: isOpen }` to gate without restructuring the component tree.
- `useLazyCatalogs(req)` — does NOT fetch until `enable()` is called (idempotent).
  Returns `{ ...catalogs, enable, isEnabled }`. Use for filters/dropdowns.
- Caching is healthy: `staleTime 30s`, `gcTime 15min`, `refetchOnWindowFocus:false`.
  Reopening a dropdown/form within the window does NOT refetch. Do not regress these.

## Six sanctioned wirings (all verified acceptable)
1. **Mount-on-open child (gold standard, skill §2.9).** Modal wrapper does
   `if (!isOpen) return null` (NO hooks before it) then renders a CHILD that holds
   `useCatalogs`. Child only mounts when open. Used by: provider, chargeConcept,
   expense, communityProject, boardPeriod, RegisterPaymentSheet, and **CatalogCrudModule**
   (the centralized one — covers every simple catalog module: tower, section, unitType…).
2. **Parent mount-gate** `{open && <Modal/>}` — parent only renders the modal when open;
   modal keeps hooks (no internal early-return, Rules-of-Hooks safe). Used by: announcement.
3. **`{ enabled: isOpen }` gate** — modal stays mounted, query gated. Used by: bankAccount.
4. **Native Select `onOpenChange` → enable()** (cleanest filter wiring, no DOM hack).
   `<Select onOpenChange={(o)=>o&&enable()}>`. Used by: unit (UnitListPage).
5. **`display:contents` wrapper + capture/bubble** for the shared `MultiSelectFilter`
   (which has NO open callback): `<div className="contents" onPointerDownCapture={enable}
   onFocusCapture={enable}>`. Both capture and bubble work (focusin/pointerdown bubble
   through display:contents). Used by: payment filters, charge, access ×4.
6. **Data-driven `useEffect(cond → enable())`** — enable only when there is something to
   render that needs the catalog (e.g. PendingInvitationsList enables UNIT_RELATIONSHIP
   only when there ARE pending invitations). Genuinely good; can't be a simple isOpen gate.

## Granularity rule (confirmed enforced)
Heavy/independent filters (UNIT, USER) get their OWN `useLazyCatalogs` so opening one
dropdown does not drag the others' /selects. Small co-opened catalogs may share one hook
(e.g. charge: CHARGE_STATUS + CHARGE_CONCEPT share; UNIT and USER each own).

## Rules-of-Hooks trap
`if (!isOpen) return null` is only valid in a wrapper that calls NO hooks before it.
If a component calls hooks first, do NOT add an early-return — mount-gate from the parent
(wiring #2) or use `{ enabled }` (wiring #3) instead.
