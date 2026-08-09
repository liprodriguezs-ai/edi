# Proposal: Responsive Data Tables (mobile-reachable row actions + a11y)

## Intent

The frontend data tables are NOT responsive. The shadcn `<Table>` primitive
(`frontend/src/components/ui/table.tsx`) wraps every table in `overflow-x-auto`
(container, line 9) and applies `whitespace-nowrap` to every `TableHead`
(line 71) and `TableCell` (line 84). The ONLY responsive strategy is horizontal
scroll — there is no card/stacked mobile layout and no breakpoint-driven column
priority/hiding. Verified by code read.

Why it matters (the user's main complaint):

- **Row ACTION buttons are unreachable on mobile.** Edit/Delete live in the
  LAST `<TableCell>`. With nowrap + horizontal scroll, the action cell is pushed
  off-screen. A cluster admin on a phone CANNOT act on a row without scrolling
  horizontally. Mobile admins are a primary persona for Kivo7.
- **Measured at 375px**: a 6-column table needs ~675px but only ~341px is
  visible → forced horizontal scroll. Egresos (8 columns) shows only 2 columns.
- **Systemic**, not local: `<Table>`/`<table>` appears in **33 files** (~39
  wrappers counting detail pages and the shared `CatalogCrudModule`). ~12 list
  pages each roll their own table with the same nowrap pattern.

There is exactly ONE good pattern to generalize: `TenantList.tsx` +
`TenantTableRow.tsx` use responsive column hiding (`hidden md:table-cell` /
`hidden lg:table-cell` — verified, 6 occurrences each). The react-frontend-architecture skill
(§2.7, §2.10) already documents this convention but only Tenant adopts it.

Secondary WCAG gaps found in the same surface (cheap to fix in-flight):

- No `<th scope="col">` anywhere (header association missing).
- `StatusPill`/`CatalogPill` status dots not `aria-hidden` and no
  `role="status"`.
- Some action cells lack `aria-label` (e.g. PaymentListPage).
- Login "Recordarme" checkbox renders a nested duplicate checkbox in the a11y
  tree (`frontend/src/components/login-form.tsx`).

Success looks like: every list table is usable and its primary row actions are
reachable at 375 / 768 / 1440 without horizontal scrolling, headers carry
`scope="col"`, decorative status dots are `aria-hidden`, and `pnpm lint`,
`pnpm typecheck`, and `pnpm test` stay green from `frontend/`.

## Scope

### In Scope

- **Shared responsive solution** for data tables, built on the EXISTING shadcn
  `<Table>` + Tailwind (no new dependency): a column-priority convention plus a
  mobile stacked/card mode, plus the a11y baseline (`scope="col"`, action
  `aria-label`, decorative dots `aria-hidden`).
- **V1 adoption** in a focused set of high-traffic list views (see V1 Scope Cut):
  `unit`, `expense`, `payment`, `provider`, `bankAccount`, `announcement`, and
  the shared `CatalogCrudModule` (which covers ~11 simple catalogs at once).
- **A11y fixes** in the touched surface: `<th scope="col">` in the shared
  primitive/convention; `aria-label` on action cells in V1 views;
  `aria-hidden`/`role="status"` on `StatusPill`/`CatalogPill`; the login
  "Recordarme" nested-checkbox duplicate.

### Out of Scope

- **Sidebar responsiveness** — already responsive (Sheet drawer < 768px). NOT a
  bug, NOT in scope.
- **The outer wrapper** `overflow-hidden rounded-[14px] border ...` — it only
  clips rounded corners; it is NOT the bug. The fix MUST NOT be misattributed to
  it.
- **Adopting all ~39 wrappers** — the remaining list views and detail-page
  tables are an explicit FOLLOW-UP (see V1 Scope Cut), not V1.
- New data-fetching, pagination, sorting, or filtering behavior — purely a
  presentation/responsiveness/a11y change.
- Reworking `DelinquencyPage` away from its raw `<table>` beyond what the a11y
  baseline strictly needs (it does not use the shadcn primitive).

## Capabilities

### New Capabilities

- `frontend-responsive-tables`: a shared, reusable way to render a list table
  that adapts across breakpoints (column priority + mobile stacked layout) with
  row actions always reachable and a baseline of table a11y.

### Modified Capabilities

- Per-module list views (`unit`, `expense`, `payment`, `provider`,
  `bankAccount`, `announcement`) and `CatalogCrudModule`: adopt the shared
  responsive solution; no functional change to their data flow.

## Approach

Three options were weighed. Recommendation: **Option C (hybrid)** — a minimal
a11y/primitive baseline PLUS a thin shared `ResponsiveDataTable` that modules
opt into, with the documented `hidden md:` column convention available for views
that don't need card mode.

### Option A — New shared `ResponsiveDataTable` / `DataTable` component

Build a generic component that takes a typed `columns` config (with a `priority`
/ `hidden` breakpoint per column and a `card`/stacked renderer for mobile) and
renders both desktop table and mobile cards. Modules pass their column defs and
adopt it.

- Pros: One place to get responsiveness + a11y right; strong DRY; matches the
  `CatalogCrudModule` declarative philosophy already in the codebase (§2.11).
- Cons: A full generic table abstraction risks OVER-ABSTRACTION (react-frontend-architecture §4
  warns against this). The 12 hand-rolled list views each have custom cells
  (StatusPill, money formatting, ProfileIdentity); forcing them all through one
  `columns` API is a large, invasive rewrite and fights existing
  `TableRow`-per-module conventions.

### Option B — Bake responsiveness into the `table.tsx` primitive + `hidden md:` convention

Add `scope="col"` to `TableHead`, keep the documented `hidden md:table-cell`
column convention (the TenantList pattern), and stop forcing horizontal scroll
as the only strategy at narrow widths.

- Pros: Smallest change; reuses what already works (TenantList); zero new
  abstraction; aligns with react-frontend-architecture §2.7/§2.10 which ALREADY prescribe this.
- Cons: react-frontend-architecture §4 rule 7 says NEVER modify `components/ui/` (shadcn) — so
  changes there must be surgical and convention-respecting (adding `scope` is
  safe and additive; removing `whitespace-nowrap` globally is risky/regressive).
  Column-hiding ALONE doesn't make the LAST-cell actions reachable on a 6–8
  column table at 375px if too many columns stay visible — actions still need a
  guaranteed-visible home. So B is necessary but not sufficient for the main
  complaint.

### Option C — Hybrid (RECOMMENDED)

1. **Baseline (primitive + convention, from B):** add `scope="col"` to
   `TableHead` (additive, a11y-safe; does NOT violate the "don't restyle shadcn"
   spirit). Keep `overflow-x-auto` as the LAST-resort fallback, not the primary
   strategy. Standardize the `hidden sm/md/lg:table-cell` column-priority
   convention (already documented, already proven by Tenant).
2. **Thin shared layer (a focused slice of A):** a small
   `ResponsiveDataTable` wrapper in `shared/components/` that (a) on `md+`
   renders the normal shadcn table using the module's existing
   `TableRow`/cells, and (b) below `md` renders each row as a definition-list
   card (label/value pairs) with the row's actions pinned in the card header/
   footer so they are ALWAYS visible. It uses the existing `useIsMobile`
   (`hooks/use-mobile.ts`, 768px) breakpoint. Modules pass a small per-row
   render for the card body and the actions node — NOT a full generic `columns`
   engine. This keeps each module's custom cells intact and avoids the Option A
   rewrite.

Why C over A and B (KISS / DRY / react-frontend-architecture conventions):

- **KISS:** we do NOT build a universal table engine. We add the missing
  primitive (card mode for mobile) and the missing a11y baseline, and let
  modules keep their hand-rolled rows.
- **DRY:** the responsive + a11y logic lives once in the shared layer and in the
  primitive; modules stop re-deriving it. `CatalogCrudModule` adopts it once and
  ~11 catalogs inherit the fix for free.
- **No over-abstraction (§4):** modules keep `TableRow`-per-module; the shared
  layer is a thin wrapper, not a config-driven mega-component. This respects the
  existing pattern instead of replacing it.

### Mobile UX decision

For the < md viewport: **stacked definition-list cards (one card per row), with
row actions pinned in the card** (recommended) — NOT pure column-hiding and NOT
"actions in a dropdown only."

- Column-priority hiding alone (B) leaves wide tables (Egresos = 8 cols) still
  needing horizontal scroll and still hides actions. Rejected as the sole
  strategy.
- Actions-in-a-dropdown is GOOD on desktop (`ActionButtonGroup dropdown`
  already exists and is used) and should remain, but on mobile the action
  trigger must be visible in the card — a dropdown whose trigger is off-screen
  solves nothing.
- Cards (definition-list per row) are the most usable for narrow widths: each
  field is `label: value`, the primary actions sit at the top/right of the card,
  and there is zero horizontal scroll. This is the standard responsive-table
  pattern and matches the interface-design "every screen needs grounding / data
  needs states" guidance.

On `md+` the desktop table is unchanged (column-priority hiding still trims
secondary columns on tablet via the documented convention).

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `frontend/src/components/ui/table.tsx` | Modified (surgical) | Add `scope="col"` to `TableHead`; treat `overflow-x-auto` as fallback, not the only strategy. Additive a11y change; keep shadcn API intact (§4 rule 7) |
| `frontend/src/shared/components/molecules/ResponsiveDataTable.tsx` (or similar) | New | Thin wrapper: shadcn table on `md+`, definition-list cards with pinned actions below `md`, using `useIsMobile` |
| `frontend/src/hooks/use-mobile.ts` | Reused | Existing 768px breakpoint hook — no change |
| `frontend/src/shared/components/catalog/CatalogCrudModule.tsx` | Modified | Adopt the shared layer once → ~11 simple catalogs inherit responsiveness |
| `frontend/src/modules/unit/UnitListPage.tsx` | Modified | Adopt shared layer + column priority |
| `frontend/src/modules/expense/ExpenseListPage.tsx` | Modified | Adopt shared layer; worst case (8 columns) |
| `frontend/src/modules/payment/PaymentListPage.tsx` | Modified | Adopt shared layer; ADD missing `aria-label` on action cells |
| `frontend/src/modules/provider/ProviderListPage.tsx` | Modified | Adopt shared layer + column priority |
| `frontend/src/modules/bankAccount/BankAccountListPage.tsx` | Modified | Adopt shared layer + column priority |
| `frontend/src/modules/announcement/AnnouncementListPage.tsx` | Modified | Adopt shared layer + column priority |
| `StatusPill` / `CatalogPill` (status-dot components) | Modified | `aria-hidden` on decorative dot; `role="status"` where a live status is conveyed |
| `frontend/src/components/login-form.tsx` | Modified | Fix "Recordarme" nested duplicate checkbox in a11y tree |
| `frontend/src/modules/tenant/{TenantList,components/TenantTableRow}.tsx` | Reference only | Source of the proven column-priority pattern; not rewritten |
| Remaining ~25 table wrappers (charge, chargeBatch, access/*, collaborator, DelinquencyPage raw table, detail-page tables, etc.) | FOLLOW-UP | Out of V1; tracked for a subsequent change |

## V1 Scope Cut

Delivery strategy is **single-pr** with `size:exception` accepted. Adopting all
~39 wrappers in one PR is too large to review safely, so V1 is bounded:

**V1 delivers (single PR, `size:exception`):**

- The shared responsive solution (primitive a11y baseline +
  `ResponsiveDataTable` + the documented column-priority convention).
- Adoption in: `unit`, `expense`, `payment`, `provider`, `bankAccount`,
  `announcement`, and `CatalogCrudModule` (the latter fans out to ~11 simple
  catalogs).
- The targeted a11y fixes in the touched surface (`scope="col"`, action
  `aria-label` on payment, `StatusPill`/`CatalogPill` dots, login checkbox).

**Explicit FOLLOW-UP (NOT in V1):**

- `charge/ChargeListPage`, `chargeBatch/ChargeBatchListPage`,
  `access/AccessLogListPage`, `access/VisitorListPage`,
  `collaborator/CollaboratorList`, `company/CompanyList`, `user/UserList`,
  `role/RoleList`, `subscriptionPlan/SubscriptionPlanList`, the
  community-project list/detail tables, the audit list, and detail-page tables.
- `payment/DelinquencyPage` (raw `<table>`, not the shadcn primitive) — needs a
  small bespoke adaptation; deferred to keep V1 uniform.

Rationale: prove the shared solution on the highest-traffic admin surfaces +
the catalog fan-out, then roll it across the remainder in a follow-up that is
pure adoption (low risk, no new design).

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Editing `components/ui/table.tsx` violates "never modify shadcn" (§4 rule 7) | Med | Keep it ADDITIVE and minimal: only `scope="col"` + treating overflow as fallback. No restyling of colors/spacing. If the team rejects any primitive edit, fall back to applying `scope` in the shared wrapper and the convention only |
| Card mode duplicates each module's cell logic (label+value) → drift between table and card | Med | Shared layer takes ONE per-row render the module already has; pass field labels from the same source as `TableHead`. Document the contract so table and card stay in sync |
| Over-abstraction creep — `ResponsiveDataTable` grows into a generic engine | Med | Hard cap the API surface: render-props for card body + actions node only; NO generic `columns` sort/filter engine. Reviewer enforces |
| `size:exception` PR is large to review even at V1 scope | High | Order the diff: (1) primitive + shared layer + a11y, (2) per-view adoption. Reviewer can validate the shared layer first; per-view changes are mechanical |
| Money/StatusPill cells render poorly in narrow cards | Med | Card body is a definition list (`label: value`); money keeps `displayFormatDate`/money formatting unchanged; verify visually at 375px before merge |
| Regression on desktop tables already using `hidden md:` (Tenant) | Low | Tenant is reference-only and untouched; convention is unchanged for existing adopters |
| `role="status"` on pills could spam screen readers if applied to static labels | Low | Apply `role="status"` only where the pill reflects a live/changeable state; decorative dots get `aria-hidden` only. Decide per-pill in DESIGN |

## Rollback Plan

- The shared `ResponsiveDataTable` is additive; reverting it restores the prior
  table-only rendering. Per-view adoptions revert independently (each ListPage
  edit is self-contained).
- The `table.tsx` change is additive (`scope` attribute + fallback overflow);
  `down` = restore the prior className/markup. No data or API contract is
  touched, so rollback is presentation-only and low risk.
- A11y fixes (pills, login checkbox) are independent one-line-ish reverts.

## Dependencies

- None external. Uses the existing shadcn `<Table>`, Tailwind v4, and the
  existing `useIsMobile` hook. No new package.
- DESIGN phase must decide: the exact shared-component API (render-props
  contract), where `scope="col"` is applied (primitive vs wrapper), and the
  per-pill `role="status"` vs `aria-hidden` decision.

## Success Criteria

- [ ] At 375 / 768 / 1440px, every V1 list table is usable and its PRIMARY row
      actions (Edit/Delete) are reachable WITHOUT horizontal scrolling.
- [ ] Below `md`, V1 views render stacked definition-list cards with actions
      pinned visibly; on `md+` the desktop table is unchanged.
- [ ] `CatalogCrudModule` adoption fans the fix out to its ~11 simple catalogs.
- [ ] Table headers expose `scope="col"`; decorative status dots are
      `aria-hidden`; payment action cells have `aria-label`; the login
      "Recordarme" checkbox no longer renders a duplicate in the a11y tree.
- [ ] No functional regression in list data flow (search, pagination, sort).
- [ ] `pnpm lint`, `pnpm typecheck`, and `pnpm test` are green from
      `frontend/`; no `any` introduced; all new UI text in Spanish.
- [ ] Follow-up scope (remaining ~25 wrappers + DelinquencyPage) is recorded for
      a subsequent change.
