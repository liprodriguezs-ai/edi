# Plan 074: Make `onRowClick` work — and be keyboard-reachable — on the desktop branch of `ResponsiveDataTable`

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> ```
> git -C frontend diff --stat 7d4e05e..HEAD -- \
>   src/shared/components/molecules/ResponsiveDataTable.tsx
> ```
> If it changed since this plan was written, compare the "Current state"
> excerpts against the live code before proceeding; on a mismatch, treat it as a
> STOP condition.

## Status

- **Priority**: P2
- **Effort**: S–M
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug / accessibility
- **Planned at**: monorepo `48f55be`, frontend submodule `7d4e05e`, 2026-08-04

## Why this matters

`ResponsiveDataTable` renders two branches: cards below the breakpoint, a table
above it. The **cards branch gives `onRowClick` full interaction semantics** —
`role="button"`, `tabIndex={0}`, an Enter/Space key handler, a focus ring. The
**table branch ignores `onRowClick` entirely**; it just maps `renderRow` over the
rows.

Two consequences, and the second is worse than it first appears.

**(a) Desktop row navigation is mouse-only.** Consumers that want a clickable row
hand-wire `onClick` onto their own `<TableRow>`, with no `tabIndex` and no key
handler. On `ComplaintListPage` the row is the *only* path to `/complaint/:id` —
there is no actions column — so a keyboard or screen-reader user on desktop
**cannot open any incidencia at all**. The mobile card works fine, so the bug is
invisible to anyone who tests on a phone.

**(b) Five pages have completely dead desktop rows.** They pass `onRowClick` and
then never wire anything, so clicking a row on desktop does nothing while the
identical card on mobile navigates. Measured across the 11 pages that pass
`onRowClick`:

```
$ for f in <the 11>; do printf "%s " $f; grep -c "cursor-pointer" $f; done
src/modules/unit/UnitListPage.tsx                          1
src/modules/role/RoleList.tsx                              0   ← suspect
src/modules/communityProject/CommunityProjectListPage.tsx   1
src/modules/chargePolicy/ChargePolicyAllListPage.tsx        0   ← confirmed dead
src/modules/payment/PaymentListPage.tsx                     1
src/modules/reservation/AmenityReservationListPage.tsx      1
src/modules/amenity/AmenityListPage.tsx                     0   ← suspect
src/modules/complaint/ComplaintListPage.tsx                 1
src/modules/collaborator/CollaboratorList.tsx               0   ← suspect
src/modules/announcement/AnnouncementListPage.tsx           0   ← suspect
src/modules/chargeBatch/ChargeBatchListPage.tsx             1
```

On `ChargePolicyAllListPage` this is user-visible harm: for a user with read but
not edit permission, `showActions` is false, so the desktop user has **no way at
all** to reach `/charge-policy/:id`, while the mobile user does.

The root cause is that the component *accepts* `onRowClick` and then only half
honors it. The fix belongs in the component, so no consumer can forget again.

## Current state

### The component's contract

```tsx
// frontend/src/shared/components/molecules/ResponsiveDataTable.tsx:38-47
export interface ResponsiveDataTableProps<T extends IEntity> {
  /** Las entidades ya cargadas/paginadas por el módulo (sin transformar). */
  rows: T[]
  /** Encabezado de la tabla (md+) — el <TableHeader>…</TableHeader> del módulo. */
  header: ReactNode
  /** Fila de tabla para md+ — el <TableRow>…</TableRow> EXACTO que el módulo ya escribe. */
  renderRow: (entity: T) => ReactNode
  ...
  /** Navegación al click de fila/card (ej. ir al detalle). Opcional. */
  onRowClick?: (entity: T) => void
```

### The desktop branch — never reads `onRowClick`

```tsx
// frontend/src/shared/components/molecules/ResponsiveDataTable.tsx:104-114
      {/* Desktop: en el breakpoint y superior. Oculto en mobile/tablet via CSS sin JS ni flicker. */}
      <div className={`${branch.table} overflow-hidden rounded-[14px] border border-border bg-card shadow-sm`}>
        <Table>
          {header}
          <TableBody>
            {rows.map((entity) => renderRow(entity))}
          </TableBody>
        </Table>
        {footer}
      </div>
```

### The cards branch — the behavior to mirror

```tsx
// frontend/src/shared/components/molecules/ResponsiveDataTable.tsx:120-147
          const isClickable = Boolean(onRowClick)

          const handleKeyDown = isClickable
            ? (e: KeyboardEvent<HTMLDivElement>) => {
                if (e.key === 'Enter') {
                  onRowClick!(entity)
                } else if (e.key === ' ') {
                  e.preventDefault()
                  onRowClick!(entity)
                }
              }
            : undefined

          return (
            <li key={key}>
              <div
                className={cn(
                  'bg-card text-card-foreground rounded-xl border shadow-sm overflow-hidden',
                  isClickable && 'cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-ring',
                )}
                role={isClickable ? 'button' : undefined}
                tabIndex={isClickable ? 0 : undefined}
                onClick={isClickable ? () => onRowClick!(entity) : undefined}
                onKeyDown={handleKeyDown}
              >
```

### The dead-row example

```tsx
// frontend/src/modules/chargePolicy/ChargePolicyAllListPage.tsx:176
              onRowClick={(policy) => navigate(`/charge-policy/${policy.id}`)}
```
```tsx
// frontend/src/modules/chargePolicy/ChargePolicyAllListPage.tsx:193
                <TableRow key={policy.id}>
```
No `onClick`, no `cursor-pointer` anywhere in the file.

### The only-path-is-the-row example

```tsx
// frontend/src/modules/complaint/ComplaintListPage.tsx:269
              onRowClick={(complaint) => navigate(`/complaint/${complaint.id}`)}
```
```tsx
// frontend/src/modules/complaint/ComplaintListPage.tsx:287
                  onClick={() => navigate(`/complaint/${complaint.id}`)}
```
`grep -n "navigate(" ComplaintListPage.tsx` shows only these two navigation
sites plus the "create" button — there is no actions column and no detail link.

### Repo conventions that apply

- The component is presentational only; data, permissions and modals stay with
  the owning module (see its docblock at `:73-86`).
- Tailwind JIT requires **complete literal class strings**; do not build class
  names dynamically (`BRANCH_CLASSES` at `:32-35` exists for exactly this).
- `cn()` from `@/lib/utils` is the class merger.
- There is already a test file:
  `frontend/src/shared/components/molecules/__tests__/ResponsiveDataTable.test.tsx`.
  Read it first and extend it rather than creating a new one.
- Strict TypeScript, no `any`.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Typecheck | `cd frontend && pnpm exec tsc -p tsconfig.app.json --noEmit` | exit 0, no output |
| Lint | `cd frontend && pnpm lint` | 0 errors (44 warnings is the known baseline) |
| Tests | `cd frontend && pnpm test` | all pass |
| Focused test | `cd frontend && pnpm exec vitest run src/shared/components/molecules` | all pass |

Do **not** run `pnpm build` and do not install anything.

## Scope

**In scope**:
- `frontend/src/shared/components/molecules/ResponsiveDataTable.tsx`
- `frontend/src/shared/components/molecules/__tests__/ResponsiveDataTable.test.tsx`
- The 11 consumer pages that pass `onRowClick`, listed in the table above.

**Out of scope** (do NOT touch, even though they look related):
- The 24 other `ResponsiveDataTable` consumers that do **not** pass
  `onRowClick`. They must keep rendering exactly as they do now — that is what
  the optional second argument protects.
- The cards branch. It is correct; you are mirroring it, not changing it.
- Any data fetching, permission logic, or route definition in the consumer pages.
  You are only changing how the row element receives its interaction props.
- `frontend/src/components/ui/table.tsx` (the shadcn primitive). No change needed.

## Git workflow

- Branch: `advisor/074-responsive-row-interaction`
- Suggested commits:
  - `feat(shared): give ResponsiveDataTable desktop rows the same interaction contract as cards`
  - `refactor(lists): adopt rowProps on the 11 clickable ResponsiveDataTable consumers`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Extend the component contract with `rowProps`

`renderRow` returns a fully-formed `<TableRow>` built by the consumer, so the
component cannot inject props into it without changing the contract. Add an
**optional second argument** that consumers spread onto their `<TableRow>`.
Optional means the 24 non-clickable consumers keep compiling untouched.

In `ResponsiveDataTable.tsx`:

1. Export a props type for the row:

```tsx
/**
 * Props que el consumidor DEBE esparcir sobre su <TableRow> cuando la tabla
 * define onRowClick. Centralizar acá el click + el soporte de teclado evita
 * que cada lista lo recableé a mano (y que se olvide: 5 de 11 listas tenían la
 * fila de desktop muerta, y ninguna era navegable por teclado).
 */
export interface ResponsiveRowProps {
  onClick?: () => void
  onKeyDown?: (event: KeyboardEvent<HTMLTableRowElement>) => void
  tabIndex?: number
  role?: 'button'
  className?: string
}
```

2. Widen the prop type:

```tsx
  /**
   * Fila de tabla para md+ — el <TableRow>…</TableRow> EXACTO que el módulo ya
   * escribe. Cuando la tabla define onRowClick, el segundo argumento trae las
   * props de interacción y DEBE esparcirse sobre el <TableRow>.
   */
  renderRow: (entity: T, rowProps: ResponsiveRowProps) => ReactNode
```

3. Build the props once per row and pass them, reusing the same key semantics as
   the cards branch:

```tsx
            {rows.map((entity) => renderRow(entity, buildRowProps(entity)))}
```

where `buildRowProps` returns `{}` when `onRowClick` is undefined, and otherwise
returns `onClick`, an Enter/Space `onKeyDown` (with `preventDefault()` on Space,
matching the cards branch exactly), `tabIndex: 0`, `role: 'button'`, and the
class string `'cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-ring'`.

Extract the shared Enter/Space logic so the two branches cannot drift — the
cards branch should call the same helper.

**Verify**: `cd frontend && pnpm exec tsc -p tsconfig.app.json --noEmit`
→ exit 0. TypeScript will now flag nothing (the second param is optional to
*consume*, since a callback may ignore extra arguments), so the typecheck
passing here does **not** prove consumers were updated — Step 2 handles that.

### Step 2: Adopt `rowProps` in the 11 clickable consumers

For each of these 11 files:

```
src/modules/role/RoleList.tsx
src/modules/unit/UnitListPage.tsx
src/modules/communityProject/CommunityProjectListPage.tsx
src/modules/chargePolicy/ChargePolicyAllListPage.tsx
src/modules/payment/PaymentListPage.tsx
src/modules/reservation/AmenityReservationListPage.tsx
src/modules/amenity/AmenityListPage.tsx
src/modules/complaint/ComplaintListPage.tsx
src/modules/collaborator/CollaboratorList.tsx
src/modules/announcement/AnnouncementListPage.tsx
src/modules/chargeBatch/ChargeBatchListPage.tsx
```

do exactly this:

1. Change `renderRow={(x) => (...)}` to `renderRow={(x, rowProps) => (...)}`.
2. Spread `{...rowProps}` onto the `<TableRow>`.
3. **Delete** the hand-wired `onClick={...}` on that `<TableRow>` and the
   `cursor-pointer` class, where they exist — they are now supplied by
   `rowProps`. Do not delete `onClick` handlers on *inner* elements.
4. Leave `stopPropagation()` calls in the actions cell exactly as they are (e.g.
   `AmenityListPage.tsx:139`). They exist so an action button does not also
   trigger row navigation, and they are still required.

Three files need extra care — inspect them before editing:
- `RoleList.tsx` renders `<RoleTableRow>`, a subcomponent, not a bare
  `<TableRow>`. Thread `rowProps` through that component's props to the
  `<TableRow>` it renders internally. Do not spread unknown props onto a DOM node.
- `CollaboratorList.tsx` has **two** `onRowClick` sites (lines ~240 and ~313) —
  handle both.
- `AmenityListPage.tsx` passes `onRowClick` conditionally (line ~187). The
  conditional must be preserved; when it resolves to undefined, `rowProps` is
  `{}` and the row is correctly non-interactive.

**Verify** for each file:
```
cd frontend && grep -n "rowProps" <file>
```
→ at least two matches (the `renderRow` signature and the spread).

And globally:
```
cd frontend && pnpm exec tsc -p tsconfig.app.json --noEmit && pnpm lint
```
→ typecheck exit 0; lint 0 errors.

### Step 3: Extend the component test

Open `frontend/src/shared/components/molecules/__tests__/ResponsiveDataTable.test.tsx`
and read it before adding anything — follow its existing render helpers and
query style.

Add cases:

1. **Desktop row responds to click.** Render with `onRowClick` and a `renderRow`
   that spreads `rowProps`; click the row in the table branch; assert the handler
   fired with the right entity.
2. **Desktop row responds to Enter.** Same setup; fire `keyDown` with `Enter`;
   assert the handler fired.
3. **Desktop row responds to Space and prevents default scroll.** Fire `keyDown`
   with `' '`; assert the handler fired.
4. **Desktop row is focusable and announced as a button.** Assert the row has
   `tabIndex=0` and `role="button"` when `onRowClick` is set.
5. **Without `onRowClick`, no interaction props are applied.** Assert the row has
   no `role`, no `tabIndex`, and is not clickable. This is the case that protects
   the 24 untouched consumers.

**Verify**: `cd frontend && pnpm exec vitest run src/shared/components/molecules`
→ all pass.

### Step 4: Verify the five previously-dead pages actually navigate

For each of `RoleList`, `ChargePolicyAllListPage`, `AmenityListPage`,
`CollaboratorList`, `AnnouncementListPage`, confirm by reading the file that the
`<TableRow>` now receives `{...rowProps}` and that `onRowClick` is passed
(unconditionally, or under its existing condition).

State in your report, per page, whether its desktop row was dead before your
change. This is a claim the maintainer will want; do not skip it.

**Verify**:
```
cd frontend && for f in src/modules/role/RoleList.tsx \
  src/modules/chargePolicy/ChargePolicyAllListPage.tsx \
  src/modules/amenity/AmenityListPage.tsx \
  src/modules/collaborator/CollaboratorList.tsx \
  src/modules/announcement/AnnouncementListPage.tsx; do \
  printf "%s " "$f"; grep -c "rowProps" "$f"; done
```
→ every file returns ≥ 2.

### Step 5: Full frontend check

```
cd frontend && pnpm exec tsc -p tsconfig.app.json --noEmit
cd frontend && pnpm lint
cd frontend && pnpm test
```

**Verify**: typecheck clean; lint 0 errors (44 warnings is the known baseline —
report the number, and flag it if it went **up**); all vitest tests pass.

## Test plan

- **New tests**: 5 cases added to the existing
  `__tests__/ResponsiveDataTable.test.tsx` (click, Enter, Space, a11y attributes,
  and the no-`onRowClick` negative case).
- **Structural pattern**: the existing tests in that same file.
- **Case 5 is the regression guard for the 24 untouched consumers** — it proves
  the new second argument is inert when unused.
- **Not covered by tests**: the per-page adoption in Step 2. Those are verified
  by the greps in Steps 2 and 4 plus the typecheck. Say so plainly in your
  report rather than implying test coverage you do not have.
- **Verification**: `pnpm test` — all pass, plus the 5 new cases.

## Done criteria

ALL must hold:

- [ ] `ResponsiveRowProps` is exported from `ResponsiveDataTable.tsx`
- [ ] The desktop branch calls `renderRow(entity, <rowProps>)`
- [ ] Enter/Space handling is a single shared helper used by **both** branches
- [ ] All 11 consumer files return ≥ 2 for `grep -c "rowProps"`
- [ ] `grep -rn "cursor-pointer" <the 11 files>` — any remaining occurrence is on an inner element, not the `<TableRow>` (verify by reading, and say so in the report)
- [ ] `cd frontend && pnpm exec tsc -p tsconfig.app.json --noEmit` exits 0
- [ ] `cd frontend && pnpm lint` reports 0 errors and no more than 44 warnings
- [ ] `cd frontend && pnpm test` passes, including the 5 new cases
- [ ] `git -C frontend status --porcelain` lists only the 13 files in Scope
- [ ] Step 4's per-page before/after statement is in the report
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- **A consumer's `renderRow` does not return a `<TableRow>` directly** (e.g. it
  returns a Fragment or an array). Spreading `rowProps` onto the wrong node
  would put `role="button"` somewhere invalid. Report which file and stop
  editing that one; the other consumers can still proceed.
- **`RoleList.tsx`'s `RoleTableRow` cannot accept the props without spreading
  unknown attributes onto a DOM element.** Do not force it with a cast. Report
  and skip that file.
- **`pnpm lint` warnings go above 44.** The baseline is 44 warnings / 0 errors.
  A rise means you introduced something; investigate before proceeding.
- **A page's row click starts firing when an action button is clicked.** That
  means a `stopPropagation()` was removed or the props landed on the wrong
  element. Fix by restoring the `stopPropagation`, and if that does not resolve
  it, stop and report.
- **You are tempted to use `React.cloneElement`** to avoid touching consumers.
  Do not. It was considered and rejected: it silently mis-merges `className` and
  existing handlers, and it cannot be typed without a cast.

## Maintenance notes

- **The durable property this establishes**: `onRowClick` now means the same
  thing in both branches. A future consumer cannot half-wire it, because the
  component supplies the behavior and the consumer only forwards it.
- **What a reviewer should scrutinize**: the actions-cell `stopPropagation()`
  calls. Making rows interactive on desktop for the first time means row-level
  navigation can now steal clicks intended for a button.
- **A follow-up worth doing separately**: `ComplaintListPage` still has no
  actions column, so the row is the only affordance even after this fix. It is
  now keyboard-reachable, which resolves the accessibility blocker, but an
  explicit "Ver" action would be better UX. Not in scope.
- **The 24 non-clickable consumers** are untouched by design. If one of them
  later wants a clickable row, it now only has to pass `onRowClick` and spread
  `rowProps` — no hand-wiring, no chance of a dead row.
