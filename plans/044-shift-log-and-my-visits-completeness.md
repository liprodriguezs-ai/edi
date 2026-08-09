# Plan 044: Shift-log views (§14.4.7), "Mis visitas" tabs (§14.3.3), over-stay alert (§14.5 r5)

> **Executor instructions**: Follow step by step, verify each step, honor STOP
> conditions, update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C frontend diff --stat 0887d5a..HEAD -- src/modules/access`

## Status

- **Priority**: P2
- **Effort**: M
- **Depends on**: plans/040 (soft — relation keys), plans/043 (none, independent)
- **Category**: spec gap (frontend completeness)
- **Planned at**: frontend submodule `0887d5a`, 2026-06-21

## Why this matters

Three documented capabilities of the gate/resident views are missing:

- **§14.4.7 Bitácora del turno**: spec requires Día/Semana/Mes tabs, an aggregate
  header ("Total ingresos: N", "Aún dentro: N"), and **Exportar bitácora**.
  `AccessLogListPage.tsx` is a flat paginated table with free-text search only.
- **§14.3.3 Mis visitas**: spec requires a **Próximas / Histórico** split so a
  resident can tell a still-valid upcoming QR from used/expired/cancelled ones.
  `VisitorListPage.tsx` is one flat table. It also omits the visitor's **vehicle/plate**
  in `MyAccessLogPage` history (spec shows "Honda Civic XXX-123").
- **§14.5 r5 over-stay**: the backend cron alerts visitors inside >8h, but the gate's
  live "dentro ahora" cards (`AccessNowPage.tsx`) give no visual warning, so a guard
  scanning the screen can't spot a likely forgotten check-out.

These are additive over existing data: `AccessLog` already carries `entered_at`,
`exited_at`, `denied_at`, `plate`; `AccessLogResource.now()` gives the live inside-set;
Orion `filterableBy` on `access_log` already allows `entered_at`-style filtering.

## Current state

`frontend/src/modules/access/AccessLogListPage.tsx` — `useBaseList` + `SearchList` +
`ResponsiveDataTable`; columns Visitante/Unidad/Tipo/Entrada/Salida/Vigilante/Estado
(+Corrección). No tabs, no totals, no export. `AccessLogResource.now()` returns the
currently-inside list (already used by `AccessNowPage`).

Backend `AccessLogController` (`backend/Modules/Access/app/Http/Controllers/AccessLogController.php`):
- `filterableBy()` line 26: `['unit_id','visitor_id','access_entry_type_id','guard_user_id','exited_at','denied_at']`.
- `sortableBy()` line 31: `['entered_at','exited_at','created_at']`.
- There is currently **no date-range filter** on `entered_at` and **no export endpoint**.
  `report.access_log` permission exists (seeder) and the spec O01 report is "Bitácora de
  accesos (PDF/Excel)".

`frontend/src/modules/access/AccessNowPage.tsx:101-103` shows "Entró {fecha}" per card,
no duration / over-stay badge.

`frontend/src/modules/access/MyAccessLogPage.tsx` — columns Visitante/Unidad/Tipo/
Entró/Salió/Estado; no plate column.

`VISITOR_STATUS` (`schema.ts:10-18`): PENDING, USED, CANCELLED, EXPIRED,
PENDING_APPROVAL, APPROVED, REJECTED.

> Conventions: `displayFormatDate` for dates; shadcn `Tabs` atom
> (`@/components/ui/tabs`); date math must NOT use `new Date()` for *display* —
> computing an elapsed-hours number for a badge is logic, acceptable, but format any
> displayed timestamp with `displayFormatDate`.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Typecheck | `cd frontend && pnpm build` | exit 0 |
| Lint | `cd frontend && pnpm lint` | no new errors |
| Tests | `cd frontend && pnpm test src/modules/access` | pass |
| Backend lint (if export endpoint added) | `cd backend && vendor/bin/pint Modules/Access` | exit 0 |

## Scope

**In scope (frontend)**:
- `frontend/src/modules/access/AccessLogListPage.tsx`
- `frontend/src/modules/access/MyAccessLogPage.tsx`
- `frontend/src/modules/access/AccessNowPage.tsx`
- `frontend/src/modules/access/VisitorListPage.tsx`
- `frontend/src/modules/access/services/AccessResource.ts` (if a date filter / export call is added)

**In scope (backend, only if export is implemented in this plan)**:
- `backend/Modules/Access/app/Http/Controllers/AccessLogController.php` (add `entered_at` to `filterableBy()`; optional export action)
- `backend/Modules/Access/routes/api.php` (only if an export route is added)

**Out of scope**:
- A full PDF/Excel report engine — if export is non-trivial, ship a CSV client-side
  export of the loaded rows and defer server-side PDF to the Reports module (O01).
- Camera scan / two-step confirm (separate).

## Steps

### Step 1: Add Día/Semana/Mes tabs + totals to the shift log

In `AccessLogListPage.tsx`, add a shadcn `<Tabs>` (Día/Semana/Mes) above the table.
Each tab sets an `entered_at` date-range filter passed through `useBaseList`'s search
params (operator-based filter — match how other modules pass range filters; if none
exists, use two filters `entered_at >= start` and `entered_at <= end`). Add `entered_at`
to the backend `filterableBy()` so the filter is accepted (Orion 422s otherwise).
Render a header line "Total ingresos: {pagination.total}" and "Aún dentro: {N}" where
N comes from `AccessLogResource.now().length` (reuse a `useQuery(['access','now'])`).

**Verify**: `cd frontend && pnpm build` → exit 0; `cd backend && grep -n "entered_at" Modules/Access/app/Http/Controllers/AccessLogController.php` shows it in `filterableBy()`.

### Step 2: Add "Exportar bitácora" (CSV MVP)

Add an "Exportar" button that downloads the currently-filtered rows as CSV
(columns: visitante, unidad, tipo, entrada, salida, vigilante, estado). Build the CSV
from the loaded `entities` client-side and trigger an `<a download>` with a
`text/csv` Blob. (Server-side PDF/Excel for O01 is deferred to the Reports module.)

**Verify**: `pnpm build` → exit 0; button present and typed (no `any`).

### Step 3: Add Próximas / Histórico tabs to Visitantes (§14.3.3)

In `VisitorListPage.tsx`, add a `<Tabs>` (Próximas / Histórico). "Próximas" filters
`visitor_status_id IN (PENDING, APPROVED, PENDING_APPROVAL)`; "Histórico" filters
`IN (USED, EXPIRED, CANCELLED, REJECTED)`. Apply via the `useBaseList` search params
(`visitor_status_id` is already in the backend `filterableBy()` of `VisitorController`).
If the filter mechanism only supports a single value, issue the tab as a status filter
list or fall back to client-side filtering of `entities` for the MVP (note which).

**Verify**: `pnpm build` → exit 0; switching tabs changes the rows.

### Step 4: Add plate column to MyAccessLogPage history

Add a "Vehículo" column/card-field reading `log.plate ?? '—'` to `MyAccessLogPage.tsx`
(both table and card renderers), matching the §14.3.3 vehicle display.

**Verify**: `pnpm build` → exit 0.

### Step 5: Over-stay badge on "dentro ahora" cards

In `AccessNowPage.tsx`, compute hours-inside from `log.entered_at` to now; if `>= 8`,
render an amber badge "Lleva {N}h dentro" on the card. Use a date util for the diff
(e.g. `date-fns` if already a dependency; otherwise a small inline ms calculation —
this is logic, not display formatting, so it does not violate the `displayFormatDate`
rule, but still format the entry timestamp itself with `displayFormatDate`).

**Verify**: `pnpm build` → exit 0.

## Test plan

- Extend access tests: a visitor list filtered to "Histórico" excludes PENDING rows;
  the over-stay helper returns a badge for an `entered_at` 9h ago and none for 1h ago
  (extract the hours-inside computation into a pure helper to test it).
- Verification: `cd frontend && pnpm test src/modules/access` → pass.

## Done criteria

- [ ] `cd frontend && pnpm build` exits 0
- [ ] Bitácora has Día/Semana/Mes tabs, totals header, and an Exportar (CSV) button
- [ ] Visitantes has Próximas/Histórico tabs
- [ ] MyAccessLogPage shows the plate/vehículo column
- [ ] AccessNowPage shows an over-stay badge for visits inside ≥ 8h
- [ ] `entered_at` is in `AccessLogController::filterableBy()` (if Step 1 used a server filter)
- [ ] `cd backend && vendor/bin/pint Modules/Access` exits 0 (if backend touched)
- [ ] `cd frontend && pnpm test src/modules/access` passes
- [ ] `plans/README.md` row updated

## STOP conditions

- `useBaseList` / the search-params shape does not support date-range or status-IN
  filters and the existing modules show no precedent → use client-side filtering for the
  MVP and note it; do NOT invent a new filter operator contract here.
- Implementing export pulls in a new dependency → ship the CSV MVP only and defer
  PDF/Excel to the Reports module O01 (note it in the README).

## Maintenance notes

- The O01 "Bitácora de accesos (PDF/Excel)" report belongs to the Reports module; the
  CSV here is a stop-gap. When O01 lands, point the Exportar button at it.
- If a date-range filter operator is added to `useBaseList`, prefer it over client-side
  filtering for large logs.
- Reviewer: confirm the "Aún dentro" count and the over-stay threshold (8h) match the
  backend `SendLongStayAlertsCommand::LONG_STAY_HOURS`.
