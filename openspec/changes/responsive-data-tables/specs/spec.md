# Spec: Responsive Data Tables (mobile-reachable row actions + a11y)

**Change**: `responsive-data-tables`
**Date**: 2026-06-13
**Status**: Draft

---

## 1. What Must Be True After This Change Is Applied

This section is authoritative. Every assertion here defines a verifiable post-condition.

### 1.1 Shared Responsive Solution — `ResponsiveDataTable`

- A shared component MUST exist in `frontend/src/shared/components/molecules/` (exact filename is a design decision) that renders a data table with two modes determined by a breakpoint:
  - **Below `md` (< 768px)**: each data row renders as a stacked definition-list card (label + value pairs), with the row's action controls pinned inside the card and always visible without horizontal scrolling.
  - **At `md` and above (≥ 768px)**: the normal shadcn `<Table>` layout MUST be rendered, preserving all existing desktop visual craft with no regression.
- Modules MUST NOT duplicate the responsive or a11y logic; they adopt the shared component.
- The shared component MUST use the existing `useIsMobile` hook (`hooks/use-mobile.ts`, 768 px threshold) to determine the active mode. No new dependency MAY be introduced.
- The component MUST accept per-row render props (or an equivalent API) for the card body and the actions node. A full generic `columns` engine (sort/filter/pagination) is explicitly out of scope and MUST NOT be added.

### 1.2 Column-Priority Convention

- The documented `hidden sm:table-cell` / `hidden md:table-cell` / `hidden lg:table-cell` Tailwind convention (already proven in `TenantList.tsx`) MUST be applied to the V1 list views so that secondary columns collapse at narrow breakpoints.
- At **768 px** (tablet), at most the highest-priority columns (name/identifier + status + actions) MUST remain visible in table mode; lower-priority columns MUST be hidden.
- At **1440 px** (desktop), all columns MUST be visible. No regression MUST be introduced for existing views already using the convention.

### 1.3 Row Actions Always Reachable

- At **375 px**: row actions (Editar / Eliminar / equivalent per module) MUST be reachable without horizontal scrolling. In card mode they are pinned inside the card. In table mode with column hiding, the actions column MUST always remain visible (never hidden by a breakpoint class).
- At **768 px**: same requirement — actions MUST be reachable without horizontal scrolling.
- At **1440 px**: actions MUST remain in the rightmost column, unchanged from the current layout.
- The existing `ActionButtonGroup` component (with `dropdown={true}`) MUST continue to be used as the action renderer inside the shared component. No new action component MAY be created.

### 1.4 Primitive A11y Baseline — `table.tsx`

- `TableHead` in `frontend/src/components/ui/table.tsx` MUST render `scope="col"` on each `<th>` element. This change MUST be strictly additive (no restyling, no removal of existing classes or attributes).
- `overflow-x-auto` MUST be treated as the last-resort fallback, not the primary responsive strategy. The wrapper MUST retain it for safety but the component layout above MUST prevent it from activating in normal use at 375 px for V1 views.
- No other modification to `table.tsx` is permitted in V1.

### 1.5 A11y — Action Buttons

- Every icon-only action button in the V1 surface MUST carry an `aria-label` that identifies the action AND the entity being acted upon (e.g., `aria-label="Editar proveedor"`, `aria-label="Eliminar gasto"`). Entity-awareness is REQUIRED where the module provides the entity name; a generic action label (e.g., `"Editar"`) is only acceptable where no entity name is available at render time.
- `PaymentListPage` action cells MUST add the missing `aria-label` as identified in the proposal.

### 1.6 A11y — Status Indicators

- Decorative color dots inside `StatusPill` and `CatalogPill` MUST carry `aria-hidden="true"` so screen readers do not announce them.
- Status information MUST also be conveyed by visible text or a text equivalent — color alone MUST NOT be the sole means of communicating status (WCAG 1.4.1).
- `role="status"` SHOULD be applied only where the pill reflects a live or user-triggered changeable state; static classification pills (catalog labels) MUST NOT carry `role="status"`.

### 1.7 Login — "Recordarme" Checkbox Fix

- The "Recordarme" field in `frontend/src/components/login-form.tsx` MUST NOT expose a duplicate or nested checkbox in the accessibility tree. After the fix, exactly one checkbox node with a properly associated `<label>` MUST appear in the a11y tree for the "Recordarme" control.

### 1.8 V1 Adoption Scope

- The shared responsive solution MUST be adopted in exactly the following surfaces:
  - `frontend/src/shared/components/catalog/CatalogCrudModule.tsx` — one adoption fans out to ~11 simple catalogs.
  - `frontend/src/modules/unit/UnitListPage.tsx`
  - `frontend/src/modules/expense/ExpenseListPage.tsx`
  - `frontend/src/modules/payment/PaymentListPage.tsx`
  - `frontend/src/modules/provider/ProviderListPage.tsx`
  - `frontend/src/modules/bankAccount/BankAccountListPage.tsx`
  - `frontend/src/modules/announcement/AnnouncementListPage.tsx`
- Remaining ~25 table wrappers (charge, chargeBatch, access, collaborator, DelinquencyPage, detail-page tables, etc.) are explicitly FOLLOW-UP and MUST NOT be modified in V1.
- `TenantList.tsx` / `TenantTableRow.tsx` MUST remain the reference-only source of the column-priority pattern and MUST NOT be modified.

### 1.9 Quality Gates

- No `any` type MUST be introduced in TypeScript anywhere in V1 changes.
- All new and modified UI text MUST be in Spanish.
- `pnpm lint`, `pnpm typecheck` (`tsc -p tsconfig.app.json --noEmit`), and `pnpm test` (`vitest run`) MUST pass green from `frontend/` after V1 is applied.
- Vitest unit tests MUST be written for the new shared component covering: (a) card mode renders at < 768 px, (b) table mode renders at ≥ 768 px, (c) action controls are present in both modes, (d) `scope="col"` is present on rendered `<th>` elements.
- No functional regression is permitted in list data flow (search, pagination, sort) for any V1 view.

---

## 2. Acceptance Scenarios (Given / When / Then)

### Scenario 1 — Card Mode at 375 px: No Horizontal Scroll

- GIVEN a V1 list view (e.g., `ExpenseListPage`) renders with data
- AND the viewport is 375 px wide
- WHEN the user views the list
- THEN each row MUST render as a stacked definition-list card
- AND no horizontal scrollbar MUST be present on the table container
- AND all visible field labels and values MUST be readable within the viewport width

---

### Scenario 2 — Row Actions Reachable at 375 px

- GIVEN a V1 list view renders with at least one row in card mode (375 px)
- WHEN the user views the card
- THEN the Editar and Eliminar action controls MUST be visible inside the card without horizontal scrolling
- AND the controls MUST be interactive (not obscured, not off-screen)

---

### Scenario 3 — Row Actions Reachable at 768 px (Table Mode)

- GIVEN a V1 list view renders at 768 px wide
- WHEN the user views the table
- THEN the table MUST render in desktop table mode (not cards)
- AND the actions column MUST be visible without horizontal scrolling
- AND secondary columns (e.g., Descripción, Fecha de creación) MAY be hidden per the column-priority convention

---

### Scenario 4 — Desktop Regression-Free at 1440 px

- GIVEN a V1 list view renders at 1440 px wide
- WHEN the user views the table
- THEN all columns defined in the desktop layout MUST be visible
- AND the visual presentation MUST match the pre-change desktop layout (no spacing, border, or alignment regression)
- AND the actions column MUST be in the rightmost position

---

### Scenario 5 — `CatalogCrudModule` Fan-Out

- GIVEN `CatalogCrudModule` is updated to adopt the shared responsive solution
- WHEN any of the ~11 simple catalogs (e.g., `TowerListPage`, `SectionListPage`) renders at 375 px
- THEN each row MUST render as a stacked card without horizontal scrolling
- AND row actions MUST be reachable without horizontal scrolling
- AND no individual catalog module file requires direct modification for the responsive behavior to apply

---

### Scenario 6 — `scope="col"` on Table Headers

- GIVEN any V1 list view renders in table mode (≥ 768 px)
- WHEN the rendered HTML is inspected
- THEN every `<th>` element in the `<thead>` MUST have the attribute `scope="col"`
- AND no `<th>` without `scope="col"` MUST appear in the table header row

---

### Scenario 7 — Action Button `aria-label` (Payment and V1 Views)

- GIVEN `PaymentListPage` renders with at least one row
- WHEN an icon-only action button (Editar, Eliminar) is inspected via the a11y tree
- THEN each such button MUST have an `aria-label` attribute whose value identifies both the action and the entity (e.g., `"Editar pago"` or `"Eliminar pago"`)
- AND the same requirement MUST hold for all icon-only action buttons in every V1 view

---

### Scenario 8 — Decorative Status Dot is `aria-hidden`

- GIVEN a `StatusPill` or `CatalogPill` with a decorative color dot renders
- WHEN the a11y tree is inspected
- THEN the color dot element MUST have `aria-hidden="true"`
- AND the status information MUST be conveyed by visible text within the same pill
- AND removing the dot visually MUST NOT cause the status to become unintelligible

---

### Scenario 9 — "Recordarme" Checkbox: Single Node in A11y Tree

- GIVEN `login-form.tsx` renders the login form
- WHEN the a11y tree is inspected for the "Recordarme" field
- THEN exactly one checkbox role node MUST appear for "Recordarme"
- AND a properly associated label MUST identify that node
- AND no duplicate or nested checkbox node MUST appear in the same context

---

### Scenario 10 — Vitest: Card Mode Renders Below Breakpoint

- GIVEN the shared `ResponsiveDataTable` component is mounted in a Vitest/Testing Library test
- AND `useIsMobile` is mocked to return `true` (simulating < 768 px)
- WHEN the component renders with sample row data
- THEN the rendered output MUST contain definition-list card markup (e.g., `<dl>` or equivalent accessible structure)
- AND MUST NOT contain a `<table>` element

---

### Scenario 11 — Vitest: Table Mode Renders at or Above Breakpoint

- GIVEN the shared component is mounted
- AND `useIsMobile` is mocked to return `false` (simulating ≥ 768 px)
- WHEN the component renders
- THEN the rendered output MUST contain a `<table>` element
- AND MUST NOT contain definition-list card markup

---

### Scenario 12 — Vitest: Actions Present in Both Modes

- GIVEN the shared component is mounted with an actions render prop that renders an "Editar" button
- WHEN rendered in both card mode (`useIsMobile = true`) and table mode (`useIsMobile = false`)
- THEN the "Editar" button MUST appear in the rendered output in BOTH modes

---

### Scenario 13 — No Data Flow Regression

- GIVEN a V1 list view has been updated to adopt the shared responsive solution
- WHEN the user searches, paginas (changes page), or sorts
- THEN the behavior MUST be identical to the pre-change behavior
- AND no additional network requests MUST be triggered beyond those already made before the change

---

### Scenario 14 — Quality Gates Pass

- GIVEN all V1 changes are applied
- WHEN `pnpm lint` is run from `frontend/`
- THEN the command MUST exit with code 0 (no errors)
- WHEN `pnpm typecheck` (or `tsc -p tsconfig.app.json --noEmit`) is run
- THEN the command MUST exit with code 0 (no type errors, no `any` introduced)
- WHEN `pnpm test` (`vitest run`) is run
- THEN all tests MUST pass, including the new Vitest unit tests for the shared component

---

## 3. Out-of-Scope Assertions

The following are explicitly NOT specified here:

- Sidebar responsiveness (already responsive; not a bug).
- The outer `overflow-hidden rounded-[14px] border` wrapper (clips rounded corners only; not the cause of the scrolling issue).
- Adoption of the remaining ~25 table wrappers (`charge`, `chargeBatch`, `access/*`, `collaborator`, `DelinquencyPage` raw table, detail-page tables, etc.) — tracked as FOLLOW-UP.
- `payment/DelinquencyPage` (uses raw `<table>`, not the shadcn primitive) — deferred to follow-up.
- New data-fetching, pagination logic, sorting, or filtering behavior.
- A generic `columns`-engine API with sort/filter support on the shared component.
- `role="status"` decisions for individual pills beyond the rule stated in §1.6 — exact per-pill assignment is a DESIGN phase decision.

---

## 4. Open Questions for Design Phase

| ID | Question | Impact |
|---|---|---|
| DQ-1 | Exact render-props API for the shared component: function-as-child, named props (`renderCard`, `renderActions`), or slot pattern? | Component API surface, per-module adoption boilerplate |
| DQ-2 | Where exactly is `scope="col"` applied: inside `table.tsx` primitive or only in the shared component's table head? (Proposal flags §4 rule 7 risk of touching shadcn files) | Whether primitive or shared layer carries the a11y baseline |
| DQ-3 | Card layout semantics: `<dl>/<dt>/<dd>` vs. `<div role="group">` for each row? | Semantic HTML decision, screen reader announcement pattern |
| DQ-4 | Per-module label source for card mode: derived from existing `TableHead` text or a separate label config array passed into the shared component? | Drift risk between table headers and card labels |
| DQ-5 | Exact `aria-label` format for action buttons: `"Editar {entityName}"` (dynamic) vs. `"Editar {entityType}"` (static per module)? | A11y quality and implementation uniformity across modules |
