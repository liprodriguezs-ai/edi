# Technical Design: Responsive Data Tables (mobile-reachable row actions + a11y)

> Scope discipline: this is a PRESENTATION + A11Y change. No data, fetching, pagination,
> sorting, filtering, or permission logic changes. KISS/DRY per CLAUDE.md §1 and
> react-frontend-architecture §4: we add ONE thin shared wrapper, NOT a generic table engine. Verified
> against the actual code (table.tsx, UnitListPage, Tenant{List,TableRow}, CatalogCrudModule,
> Expense/PaymentListPage) on 2026-06-13.

## 0. Reality the design is built on (verified)

| Fact (file:line) | Consequence for the design |
|---|---|
| `table.tsx:9` wraps every table in `relative w-full overflow-x-auto` | Horizontal scroll is the ONLY current responsive strategy. We keep it as a fallback but stop relying on it below `md`. |
| `table.tsx:71` `TableHead` has `whitespace-nowrap`, no `scope` | A11y gap: headers are not programmatically associated. We add `scope="col"` (additive). We do NOT remove `whitespace-nowrap` (it is desktop-correct and removing it is regressive). |
| `table.tsx:84` `TableCell` has `whitespace-nowrap` | Confirms columns never wrap → wide tables overflow on phones. The card mode (below `md`) is what fixes it, not editing this. |
| Two row shapes exist: **inline rows** in the page (`UnitListPage:182-218`, `ExpenseListPage:140-196`, `PaymentListPage:267-304`, `CatalogCrudModule:434-483`) and **extracted memo rows** (`TenantTableRow.tsx`, `DocumentTableRow`). | The shared wrapper must accept the page's EXISTING per-row JSX as-is. No forced extraction, no forced `columns[]` engine. |
| Two action shapes: `ActionButtonGroup dropdown` (Tenant/Document) and raw `<Button size="icon">` clusters (Unit/Expense), plus pill text buttons (Payment). | The shared wrapper takes an opaque `actions` node per row — it does NOT own the action component. |
| `UnitListPage:184-185` row has `onClick={() => navigate(...)}`; actions cell has `onClick={(e) => e.stopPropagation()}` (`:196`). | Migration MUST preserve row-navigation + `stopPropagation`. |
| `useIsMobile()` (`hooks/use-mobile.ts`) = `matchMedia(max-width: 767px)`, returns `false` on first render (state starts `undefined`). | JS-driven mobile detection FLICKERS on first paint. We prefer CSS (`md:` Tailwind) for the desktop/card switch and use `useIsMobile` only where DOM divergence is unavoidable. |
| `@tanstack/react-table` is installed but UNUSED by any list. | We deliberately do NOT adopt it for V1 — that is the Option A over-abstraction the proposal rejected. Noted so a reviewer doesn't "helpfully" pull it in. |
| Vitest 4.1.8 + @testing-library/react + jest-dom + jsdom are in `devDependencies`, but there is **NO `vitest.config`, NO test setup file, and ZERO test files**. `pnpm test` = `vitest run`; `vite.config.ts` has no `test` block. | Test infra is a PREREQUISITE task (Decision 6). Without a jsdom environment + jest-dom setup, no component test can run. |

## 1. Architecture Approach

**Pattern: thin Adapter/Wrapper over the existing shadcn primitive + a documented column-priority convention.** Two cooperating pieces, nothing more:

```
1. A11y baseline (primitive-side, additive)
   └─ TableHead emits scope="col" by default  (one prop, no restyle)
   └─ StatusPill / CatalogPill: decorative dot aria-hidden; optional role="status"

2. ResponsiveDataTable<T>  (shared/components/molecules/)
   └─ md+  : renders the SAME shadcn <Table> the page already builds (children pass-through)
   └─ < md : maps each row to a definition-list <Card> (label: value) with the row's
             actions pinned in the card header — actions ALWAYS visible, zero h-scroll
```

The wrapper is a **presentational adapter**: it owns the breakpoint switch and the card chrome, the MODULE owns its data, cells, formatting, status pills, money, and action buttons. This keeps each module's hand-rolled rows intact (react-frontend-architecture convention) and confines the new responsiveness/a11y logic to ONE place (DRY).

**Why not Option A (full `columns: ColumnDef<T>[]` engine):** the 12 list pages each render custom cells — `CatalogPill`, `StatusPill`, `displayMoney` with `−` prefix and `tabular-nums`, `ProfileIdentity`, multi-line unit+payer cells, conditional download buttons keyed on media collection. Forcing all of those through one generic `cell: (row) => ...` config buys nothing over the JSX they already have, and it fights the `TableRow`-per-module convention. react-frontend-architecture §4 explicitly warns against this kind of over-abstraction, and `@tanstack/react-table` sitting unused is the tell. **Rejected.**

**Why not Option B alone (`hidden md:table-cell` only):** column hiding trims tablet columns but an 8-column table (Egresos) at 375px still overflows and the LAST-cell actions still scroll off-screen. Column hiding is NECESSARY (we keep it for `md`/`lg` trimming) but NOT SUFFICIENT for the main complaint (mobile actions). **Adopted as a complement, not the whole answer.**

**Chosen: Option C (hybrid)** — primitive a11y baseline + `ResponsiveDataTable` card mode + the documented `hidden md:table-cell` convention for desktop column priority.

## 2. Decision 1 — Component API (`ResponsiveDataTable`)

### Name
`ResponsiveDataTable` — confirmed against react-frontend-architecture naming: PascalCase component, lives in `shared/components/molecules/` (it composes atoms: Card + Table + the module's action node → molecule per §1 of the file tree, alongside `ActionButtonGroup`, `TableLoadingState`, `TableEmptyState`). No barrel file (react-frontend-architecture §4 rule 1 — import by full path).

### Model: data-driven `rows` + per-row render slots (NOT a generic `columns` engine)

The wrapper needs ONE thing the page already has: the array of entities and, per entity, (a) the desktop table row, (b) the card title, (c) the card fields, (d) the actions node. We pass these as small render functions. This is render-props scoped HARD to layout — no sort/filter/pagination ownership.

```typescript
// shared/components/molecules/ResponsiveDataTable.tsx
import type { ReactNode } from 'react'
import type { IEntity } from '@/shared/interfaces/Entity'

/** Un par etiqueta/valor que se muestra como fila de la card en mobile (< md). */
export interface ResponsiveField {
  /** Etiqueta corta en español, ej. "Tipo". Reusar el MISMO texto del <TableHead>. */
  label: string
  /** Valor ya renderizado (puede ser un pill, dinero formateado, etc.). */
  value: ReactNode
}

export interface ResponsiveDataTableProps<T extends IEntity> {
  /** Las entidades ya cargadas/paginadas por el módulo (sin transformar). */
  rows: T[]
  /** Fila de tabla para md+ — el <TableRow>…</TableRow> EXACTO que el módulo ya escribe. */
  renderRow: (_entity: T) => ReactNode
  /** Encabezado de la tabla (md+) — el <TableHeader>…</TableHeader> del módulo. */
  header: ReactNode
  /** Título de la card en mobile (< md), ej. el identificador de la unidad. */
  renderCardTitle: (_entity: T) => ReactNode
  /** Pares etiqueta/valor del cuerpo de la card en mobile. */
  renderCardFields: (_entity: T) => ResponsiveField[]
  /**
   * Nodo de acciones de la fila (Editar/Eliminar/…). El MISMO que va en la última
   * celda en md+. En mobile se fija en el header de la card (siempre visible).
   * Si la fila no tiene acciones, omitir.
   */
  renderActions?: (_entity: T) => ReactNode
  /** Navegación al click de fila/card (ej. ir al detalle). Opcional. */
  onRowClick?: (_entity: T) => void
  /** clave única por fila (default entity.id). */
  getRowKey?: (_entity: T) => string
}

export function ResponsiveDataTable<T extends IEntity>(
  props: ResponsiveDataTableProps<T>,
): ReactNode
```

Key API decisions, justified:
- **`rows: T[]` + `renderRow`/`renderCardFields`** instead of `columns: ColumnDef<T>[]`: the page keeps full control of each cell's JSX. The wrapper never inspects `T` beyond the id. **No `any`** — fully generic over `T extends IEntity`.
- **`header` is a node, not a `columns` array**: the desktop table is rendered UNCHANGED. The module passes its existing `<TableHeader><TableRow>…</TableRow></TableHeader>`. This is the cheapest possible migration (the desktop branch is literally "the table you already had").
- **`renderActions` is opaque**: Unit passes its two `<Button size="icon">`, Tenant passes `<ActionButtonGroup>`, Payment passes its pill buttons. The wrapper doesn't know or care.
- **`renderCardFields` returns `ResponsiveField[]`** — drift mitigation: the `label` strings are the SAME ones used in the `<TableHead>`s. The design contract (documented in the component JSDoc) is "card labels mirror table headers." A future lint/test can assert count parity if drift becomes real.
- The component returns `ReactNode` (React 19), is generic, and introduces no new dependency.

### Internal structure (how it renders — CSS-first, no flicker)

```tsx
// Pseudocode of the body — CSS drives the switch, NOT useIsMobile (avoids first-paint flicker)
return (
  <>
    {/* Desktop: md and up. Hidden below md via CSS, so no JS, no hydration flash. */}
    <div className="hidden md:block overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
      <Table>
        {header}
        <TableBody>{rows.map(renderRow)}</TableBody>
      </Table>
    </div>

    {/* Mobile: below md. Definition-list cards. */}
    <ul className="grid gap-3 md:hidden" role="list">
      {rows.map((entity) => (
        <li key={getRowKey?.(entity) ?? entity.id}>
          <Card
            className={onRowClick ? 'cursor-pointer' : undefined}
            onClick={onRowClick ? () => onRowClick(entity) : undefined}
          >
            <div className="flex items-start justify-between gap-3 px-4 pt-4">
              <div className="font-medium text-[var(--ink-1)]">{renderCardTitle(entity)}</div>
              {renderActions && (
                <div onClick={(e) => e.stopPropagation()}>{renderActions(entity)}</div>
              )}
            </div>
            <dl className="grid gap-1.5 px-4 pb-4 pt-2">
              {renderCardFields(entity).map((f) => (
                <div key={f.label} className="flex items-baseline justify-between gap-3">
                  <dt className="text-[12px] text-[var(--ink-3)]">{f.label}</dt>
                  <dd className="text-[13px] text-[var(--ink-2)] text-right">{f.value}</dd>
                </div>
              ))}
            </dl>
          </Card>
        </li>
      ))}
    </ul>
  </>
)
```

**Both branches render in the DOM; CSS (`hidden md:block` / `md:hidden`) chooses which is visible.** This is intentional: it avoids the `useIsMobile()` first-paint flicker (it returns `false` until `useEffect` runs) and any hydration mismatch. The cost is that both row sets mount; for paginated lists (20–100 rows) this is negligible and the rows are already memoized where extracted. We do NOT use `useIsMobile` inside `ResponsiveDataTable` for the visual switch.

> The module keeps wrapping `ResponsiveDataTable` in its existing `TableLoadingState` / `TableEmptyState` / `Pagination` flow — the wrapper renders ONLY the populated table/cards, exactly where the page currently renders `<div className="overflow-hidden …"><Table>…</Table></div>`.

## 3. Decision 2 — Mobile rendering: definition-list cards (not column-hiding-only)

- **Below `md` (< 768px): definition-list cards.** One `<Card>` per row, `<dl>` body of `label: value`, actions pinned in the card header so they are ALWAYS visible with zero horizontal scroll. This directly fixes the primary complaint (unreachable mobile actions). Chosen over column-hiding-only because an 8-column table can't be made usable at 375px by hiding alone.
- **`md` and up: the desktop shadcn table, UNCHANGED**, with the documented column-priority convention (`hidden md:table-cell` for secondary, `hidden lg:table-cell` for tertiary, `hidden sm:table-cell` for optional) still trimming columns on tablet. This is exactly react-frontend-architecture §2.7 and the proven `TenantList` pattern.
- **Breakpoint = `md` (768px), aligned three ways**: Tailwind `md:` = `min-width: 768px`, `useIsMobile` MOBILE_BREAKPOINT = 768, and the proposal. We standardize on `md` as THE table/card switch. (Column priority may additionally use `sm`/`lg` for finer desktop trimming, but the table↔card boundary is `md`.)
- **CSS vs JS**: CSS (`hidden md:block` / `md:hidden`) for the switch — no flicker, no hydration risk. `useIsMobile` is reserved for cases where the same DOM cannot serve both (none in V1).
- **Actions column collapse**: in the desktop table the actions stay in the last cell. On mobile the SAME `renderActions(entity)` node is reused in the card header. For pages that use a long inline cluster (Payment: download + reject + validate), the existing `ActionButtonGroup dropdown` "⋯" pattern is the recommended target so the card header stays compact — but the wrapper does not force it; the page decides. (Payment's text-pill buttons wrap acceptably in the card header at 375px; if cramped, the page migrates them to `ActionButtonGroup`.)

## 4. Decision 3 — `scope="col"` placement (primitive vs wrapper)

**Decision: add `scope="col"` ADDITIVELY in the primitive `TableHead`, defaultable and overridable.** react-frontend-architecture §4 rule 7 says never to RESTYLE shadcn components; adding a semantic HTML attribute is not a restyle — it changes zero visuals, zero className, zero API ergonomics, and is forward-compatible with shadcn (a `th` accepting `scope` is standard HTML). This is the one-line, DRY fix that benefits ALL ~39 tables at once, not just V1.

```tsx
// table.tsx — TableHead, the ONLY edit to the primitive
function TableHead({ className, scope = 'col', ...props }: React.ComponentProps<'th'>) {
  return (
    <th
      data-slot="table-head"
      scope={scope}
      className={cn(
        'px-4 py-2.5 text-left align-middle text-[10.5px] font-semibold uppercase tracking-[0.06em] whitespace-nowrap text-muted-foreground bg-[var(--surface-2)] [&:has([role=checkbox])]:pr-0 [&>[role=checkbox]]:translate-y-[2px]',
        className,
      )}
      {...props}
    />
  )
}
```

- `scope = 'col'` default → every existing `<TableHead>` (33 files) gets it for free, no per-call change.
- It remains overridable (`scope={undefined}` or `scope="row"`) for the rare row-header case.
- `whitespace-nowrap` and all classes are LEFT INTACT (removing them is the regressive change the proposal warns against).

**Fallback if the team forbids ANY `components/ui/` edit (risk register):** apply `scope="col"` at each `<TableHead scope="col">` call site in the V1 views and document it as the convention for new tables. This is strictly worse (per-call boilerplate, misses non-V1 tables) but compliant. The design RECOMMENDS the primitive edit and records the fallback. Verifier/reviewer makes the final call; tasks will include both so the decision is explicit.

## 5. Decision 4 — Status pill a11y (`StatusPill` + `CatalogPill`)

Both pills render a decorative colored dot (`<i>`) plus text. Decisions:

- **Decorative dot → `aria-hidden="true"`** on the `<i>`. The dot encodes the same meaning as the adjacent text (color == status word), so to a screen reader it is redundant decoration. This is the unambiguous, always-correct fix.
- **`role="status"` → NO, by default.** `role="status"` is an ARIA LIVE region (`aria-live="polite"`); it announces CHANGES. These pills render a static value inside a table cell that is already read in its row/column context (now with `scope="col"`). Making every pill a live region would spam the screen reader on every list re-render/pagination (proposal risk "role=status could spam SRs" — confirmed correct). The text content alone conveys the status. So: **dot `aria-hidden`, text stays as plain accessible text, no `role`.**
  - Where a status is conveyed by COLOR ALONE with no text (not the case for either pill today — both always render a label), we would add an `sr-only` label. Both pills already render `{name}` / `{children}`, so no extra label is needed.

```tsx
// StatusPill.tsx — dot becomes decorative
<i aria-hidden className={`h-1.5 w-1.5 flex-none rounded-full ${DOT[tone]}`} />

// CatalogPill.tsx — same
<i aria-hidden className="size-1.5 rounded-full" style={{ backgroundColor: pillColor }} />
```

`CatalogPill` already falls back to `'—'` when `name` is null — keep. No `role`, no live region. (`StatusPill` is at `modules/treasurerPanel/components/`, `CatalogPill` at `shared/components/` — both are shared-enough to edit directly; neither is a `components/ui/` shadcn atom.)

## 6. Decision 5 — Adoption strategy & ordering (single-PR, `size:exception`)

Delivery is **single-pr** with `size:exception` (per proposal). Order the diff so a reviewer validates the shared layer first, then mechanical adoptions:

**Phase A — shared layer + a11y baseline + test infra (review this first):**
1. Vitest config + test setup (Decision 6) — PREREQUISITE, no tests run without it.
2. `table.tsx` `TableHead scope="col"` (primitive, additive).
3. `StatusPill` + `CatalogPill` dot `aria-hidden`.
4. `ResponsiveDataTable.tsx` + its test file.
5. Login "Recordarme" nested-checkbox fix (`login-form.tsx`) — independent one-liner.

**Phase B — per-view adoption (mechanical, one view at a time):**
Order chosen so the EASIEST generalization lands first and the worst-case validates the design:
1. `CatalogCrudModule.tsx` — **highest leverage**: one change fans out to ~11 catalogs (tower, section, unitType, unitStatus, unitFeature, unitRelationship, expenseCategory, vehicle, userProfile, access, catalogs). The generic already owns its row JSX (`:434-483`), so it builds `renderCardFields` from its `columns` prop + nameLabel + "Fecha de creación", and `renderActions` from its existing edit/delete buttons. This proves the wrapper on the declarative path.
2. `unit/UnitListPage.tsx` — representative inline-row page WITH row-click navigation + `stopPropagation` (the backward-compat proof). See §8 before/after.
3. `provider/ProviderListPage.tsx`, `bankAccount/BankAccountListPage.tsx`, `announcement/AnnouncementListPage.tsx` — same inline-row shape as Unit.
4. `expense/ExpenseListPage.tsx` — **worst case (8 columns)**; validates cards beat horizontal scroll. Conditional media-download buttons go into `renderActions`.
5. `payment/PaymentListPage.tsx` — adopt wrapper AND add the missing `aria-label`s to its action buttons (`download`/`reject`/`validate`/`cancel`); its `PaymentRowActions` becomes the `renderActions` node.

**`DelinquencyPage` (raw `<table>`):** explicitly OUT of V1 (proposal). It does not use the shadcn primitive, so it gets no `scope` from the primitive edit and no `ResponsiveDataTable`. Recorded as follow-up. The ONLY thing V1 owes it is nothing — it is deferred whole.

**Each view's column→field mapping** (the migration contract): every `<TableHead>Label</TableHead>` + its matching `<TableCell>value</TableCell>` becomes one `ResponsiveField { label: 'Label', value: <the cell's inner JSX> }`. The primary/identity column becomes `renderCardTitle`. The actions cell becomes `renderActions`. Columns currently hidden on tablet (`hidden md:table-cell`) STILL appear in the card (mobile shows all fields — the card has vertical room). Desktop column priority is unchanged.

## 7. Decision 6 — Testing (Standard Mode; strict_tdd = FALSE)

strict_tdd is FALSE → Standard Mode: tests authored ALONGSIDE implementation, not test-first.

**Prerequisite (blocking) — there is NO test infra today.** Add a `test` block to `vite.config.ts` (Vitest reads vite config, so the `@/` alias resolves automatically from `resolve.alias`):

```ts
// vite.config.ts — add inside the returned config object
test: {
  environment: 'jsdom',
  globals: true,
  setupFiles: ['./src/test/setup.ts'],
  // El plugin cloudflare() no aplica en jsdom; si interfiere, excluirlo cuando process.env.VITEST está set.
},
```

```ts
// src/test/setup.ts (NEW)
import '@testing-library/jest-dom/vitest'
```

> Gotcha to handle in apply: the `cloudflare()` Vite plugin may not be inert under Vitest/jsdom. If `vitest run` errors on it, gate the plugins array with `process.env.VITEST` (push `cloudflare()` only when not testing). This is an apply-time detail, flagged here so it is expected.

**Component test plan — `src/shared/components/molecules/__tests__/ResponsiveDataTable.test.tsx`:**

`@testing-library/react` queries the rendered DOM; since BOTH branches render and CSS hides one, the test asserts STRUCTURE and reachability rather than visibility. To exercise the "mobile vs desktop" intent we mock `matchMedia` (jsdom has no layout, so CSS `md:` does not actually hide anything — we test that both representations exist and are correct, and we mock `matchMedia` for any code that reads it). Cases:

1. **Desktop table renders**: given `rows` + `header` + `renderRow`, the `<table>` exists with a `columnheader` per `<TableHead>` and a data row per entity. Assert `getAllByRole('row')`.
2. **`scope="col"` present**: every `columnheader` has `scope="col"` (validates Decision 3 end-to-end through the primitive).
3. **Cards render below md**: the `md:hidden` `<ul role="list">` contains one `<li>` per entity, each with a `<dl>` whose `<dt>` text matches the `ResponsiveField.label`s and `<dd>` the values.
4. **Actions reachable in BOTH representations**: `renderActions` output (e.g. a button with `aria-label="Editar X"`) is queryable in the desktop row AND in the card header. `getAllByRole('button', { name: /editar/i })` length == 2 per row (one per representation) — proving actions are never off-screen on mobile.
5. **`onRowClick` fires; action click does NOT bubble to row**: clicking the action button in the card does not trigger `onRowClick` (the `stopPropagation` wrapper). Clicking the card body does.
6. **`matchMedia` mock**: provide a `window.matchMedia` stub in setup (jsdom lacks it) so any component using `useIsMobile` elsewhere doesn't throw; assert the wrapper itself does not depend on it.
7. **a11y smoke**: `StatusPill`/`CatalogPill` dot has `aria-hidden` (render a pill, assert the `<i>` is `aria-hidden` and the label text is in the accessible name of the cell).

Quality gates (run from `frontend/`): `pnpm lint`, `pnpm typecheck`, `pnpm test`. No `any`. All UI text Spanish. Add `window.matchMedia` polyfill to `src/test/setup.ts`:

```ts
// src/test/setup.ts (continued)
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: (query: string) => ({
    matches: false, media: query, onchange: null,
    addEventListener: () => {}, removeEventListener: () => {},
    addListener: () => {}, removeListener: () => {}, dispatchEvent: () => false,
  }),
})
```

## 8. Decision 7 — Backward-compat / migration safety + before→after (UnitListPage)

Migration rules (apply to every V1 view):
- **Zero data/permission changes.** `useXList`, stores, `useMultiplePermissions`, filters, `SearchList`, `Pagination`, modals, `ConfirmDialog` — all untouched.
- **Preserve row navigation.** `onRowClick={(unit) => navigate('/unit/'+unit.id)}` is passed to the wrapper; the card body inherits it; the actions node is wrapped so its clicks `stopPropagation` (the wrapper does this for the card; the desktop `renderRow` keeps the page's existing `onClick={(e)=>e.stopPropagation()}` on the actions `<TableCell>`).
- **Identical desktop output.** Because `header` + `renderRow` pass through the existing JSX, the `md+` table is byte-for-byte the same (plus the `scope` attribute from the primitive).

### Before (UnitListPage.tsx, the table block — `:168-222`)

```tsx
<div className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
  <Table>
    <TableHeader>
      <TableRow>
        <TableHead>Identificador</TableHead>
        <TableHead>Tipo</TableHead>
        <TableHead>Sección</TableHead>
        <TableHead>Indiviso</TableHead>
        <TableHead>Estado</TableHead>
        <TableHead className="text-right"></TableHead>
      </TableRow>
    </TableHeader>
    <TableBody>
      {entities.map((unit) => (
        <TableRow key={unit.id} className="cursor-pointer" onClick={() => navigate(`/unit/${unit.id}`)}>
          <TableCell className="font-medium">{unit.full_identifier ?? unit.number}</TableCell>
          <TableCell>{unit.unit_type?.name ?? '—'}</TableCell>
          <TableCell>{unit.section?.name ?? '—'}</TableCell>
          <TableCell>{unit.ownership_share != null ? `${Number(unit.ownership_share).toFixed(2)}%` : '—'}</TableCell>
          <TableCell><CatalogPill name={unit.unit_status?.name} color={unit.unit_status?.color} /></TableCell>
          <TableCell className="text-right" onClick={(e) => e.stopPropagation()}>
            <div className="flex justify-end gap-1">{/* edit + delete buttons */}</div>
          </TableCell>
        </TableRow>
      ))}
    </TableBody>
  </Table>
</div>
```

### After (same data, responsive)

```tsx
// Extract the actions + the header once, then hand both to the wrapper.
const unitHeader = (
  <TableHeader>
    <TableRow>
      <TableHead>Identificador</TableHead>
      <TableHead>Tipo</TableHead>
      <TableHead className="hidden md:table-cell">Sección</TableHead>
      <TableHead className="hidden lg:table-cell">Indiviso</TableHead>
      <TableHead>Estado</TableHead>
      <TableHead className="text-right" />
    </TableRow>
  </TableHeader>
)

const unitActions = (unit: IUnit) => (
  <div className="flex justify-end gap-1">
    {permissions.canEdit && (
      <Button variant="ghost" size="icon" className="size-8"
        onClick={() => handleEdit(unit)} aria-label={`Editar ${unit.full_identifier ?? ''}`}>
        <Pencil className="size-4" />
      </Button>
    )}
    {permissions.canDelete && (
      <Button variant="ghost" size="icon" className="size-8 text-destructive hover:text-destructive"
        onClick={() => setEntityToDelete(unit)} aria-label={`Eliminar ${unit.full_identifier ?? ''}`}>
        <Trash2 className="size-4" />
      </Button>
    )}
  </div>
)

const renderUnitStatus = (unit: IUnit) => (
  <CatalogPill name={unit.unit_status?.name} color={unit.unit_status?.color} />
)
const indivisoText = (unit: IUnit) =>
  unit.ownership_share != null ? `${Number(unit.ownership_share).toFixed(2)}%` : '—'

// …in JSX, replacing the <div className="overflow-hidden …"> block:
<ResponsiveDataTable<IUnit>
  rows={entities}
  header={unitHeader}
  onRowClick={(unit) => navigate(`/unit/${unit.id}`)}
  renderRow={(unit) => (
    <TableRow key={unit.id} className="cursor-pointer" onClick={() => navigate(`/unit/${unit.id}`)}>
      <TableCell className="font-medium">{unit.full_identifier ?? unit.number}</TableCell>
      <TableCell>{unit.unit_type?.name ?? '—'}</TableCell>
      <TableCell className="hidden md:table-cell">{unit.section?.name ?? '—'}</TableCell>
      <TableCell className="hidden lg:table-cell">{indivisoText(unit)}</TableCell>
      <TableCell>{renderUnitStatus(unit)}</TableCell>
      <TableCell className="text-right" onClick={(e) => e.stopPropagation()}>{unitActions(unit)}</TableCell>
    </TableRow>
  )}
  renderCardTitle={(unit) => unit.full_identifier ?? unit.number}
  renderCardFields={(unit) => [
    { label: 'Tipo', value: unit.unit_type?.name ?? '—' },
    { label: 'Sección', value: unit.section?.name ?? '—' },
    { label: 'Indiviso', value: indivisoText(unit) },
    { label: 'Estado', value: renderUnitStatus(unit) },
  ]}
  renderActions={unitActions}
/>
```

Result: `md+` renders the IDENTICAL table (now with `scope="col"` headers + tablet column trimming); `< md` renders one card per unit with Tipo/Sección/Indiviso/Estado as `label: value` and the Edit/Delete buttons pinned in the card header — reachable with zero horizontal scroll. Row navigation and `stopPropagation` preserved in both representations.

## 9. File / structure plan (per react-frontend-architecture)

| Path | Action | Notes |
|---|---|---|
| `frontend/src/shared/components/molecules/ResponsiveDataTable.tsx` | NEW | The wrapper (Decision 1/2). Molecule, no barrel. |
| `frontend/src/shared/components/molecules/__tests__/ResponsiveDataTable.test.tsx` | NEW | Vitest + Testing Library (Decision 6). |
| `frontend/src/components/ui/table.tsx` | EDIT (additive) | `TableHead scope="col"` default (Decision 3). |
| `frontend/src/shared/components/CatalogPill.tsx` | EDIT | dot `aria-hidden` (Decision 4). |
| `frontend/src/modules/treasurerPanel/components/StatusPill.tsx` | EDIT | dot `aria-hidden` (Decision 4). |
| `frontend/src/components/login-form.tsx` | EDIT | "Recordarme" nested-checkbox fix. |
| `frontend/vite.config.ts` | EDIT | add `test` block (Decision 6). |
| `frontend/src/test/setup.ts` | NEW | jest-dom + `matchMedia` polyfill (Decision 6). |
| `frontend/src/shared/components/catalog/CatalogCrudModule.tsx` | EDIT | adopt wrapper → fans out to ~11 catalogs. |
| `frontend/src/modules/unit/UnitListPage.tsx` | EDIT | adopt wrapper (§8). |
| `frontend/src/modules/provider/ProviderListPage.tsx` | EDIT | adopt wrapper. |
| `frontend/src/modules/bankAccount/BankAccountListPage.tsx` | EDIT | adopt wrapper. |
| `frontend/src/modules/announcement/AnnouncementListPage.tsx` | EDIT | adopt wrapper. |
| `frontend/src/modules/expense/ExpenseListPage.tsx` | EDIT | adopt wrapper (worst case, 8 cols). |
| `frontend/src/modules/payment/PaymentListPage.tsx` | EDIT | adopt wrapper + add missing `aria-label`s. |
| `frontend/src/modules/tenant/{TenantList,components/TenantTableRow}.tsx` | REFERENCE ONLY | not rewritten. |
| `frontend/src/modules/payment/DelinquencyPage.tsx` | FOLLOW-UP | raw `<table>`, deferred. |

## 10. Risks & mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| `components/ui/table.tsx` edit rejected as a shadcn modification (§4 rule 7) | Med | The edit is purely additive (semantic `scope` attr, zero visual/className change). Fallback documented (§4): apply `scope="col"` at call sites. Tasks carry BOTH so the reviewer chooses. |
| Card `renderCardFields` drifts from `<TableHead>` labels | Med | Contract: card labels MUST mirror table headers (JSDoc + design). Test asserts label/value structure. A follow-up lint could enforce count parity. |
| `ResponsiveDataTable` grows into a generic engine | Med | API HARD-capped: render-props for row/card/actions only. NO sort/filter/pagination/`columns[]`. `@tanstack/react-table` stays unused. Reviewer enforces. |
| Vitest has no config → first test won't run | High (certain) | Decision 6 makes test infra a blocking prerequisite task (config + setup) BEFORE any component test. |
| `cloudflare()` plugin breaks under jsdom | Med | Gate plugins by `process.env.VITEST` at apply time; flagged in Decision 6. |
| Both DOM branches mounting doubles row nodes | Low | Paginated lists (≤100 rows); negligible. Avoids the worse flicker/hydration of a JS switch. |
| Payment's inline text-pill actions cramped in card header at 375px | Med | If cramped, migrate Payment actions to `ActionButtonGroup dropdown` (already used elsewhere); verify visually at 375px before merge. |
| Money/pill cells render poorly in narrow cards | Low | Card `<dd>` is right-aligned; money keeps `tabular-nums` + `displayMoney`; pills already inline-flex. Visual check at 375px in success criteria. |
| Single-PR `size:exception` hard to review | High | Diff ordered (Phase A shared+a11y+infra, then Phase B mechanical per-view). Reviewer validates the wrapper once; adoptions are repetitive. |
| Regression on existing `hidden md:` adopters (Tenant) | Low | Tenant is reference-only and untouched; the convention is unchanged. |

## 11. ADR summary (decisions locked)

- **ADR-1 Wrapper over engine.** `ResponsiveDataTable` is a thin presentational adapter (render-props for row/card/actions), NOT a `columns[]` engine and NOT `@tanstack/react-table`. Rationale: preserve module-owned custom cells, KISS/DRY, avoid the §4 over-abstraction trap. Rejected: Option A full DataTable.
- **ADR-2 Cards below `md`.** Definition-list `<Card>` per row with pinned actions for < 768px; desktop table unchanged with `hidden md/lg:table-cell` column priority. Rejected: column-hiding-only (insufficient for wide tables / mobile actions).
- **ADR-3 CSS switch, not JS.** Both branches in DOM, `hidden md:block`/`md:hidden` chooses. Rejected: `useIsMobile` switch (first-paint flicker / hydration risk).
- **ADR-4 `scope="col"` in the primitive.** Additive default on `TableHead`; benefits all tables. Fallback: call-site `scope`. Rejected (unless team forbids): editing nothing.
- **ADR-5 Pills: `aria-hidden` dot, no `role="status"`.** Live regions would spam SRs on re-render; text already conveys status. Rejected: `role="status"` on static pills.
- **ADR-6 Test infra is a prerequisite.** Add Vitest `test` block + `src/test/setup.ts` (jsdom, jest-dom, `matchMedia` polyfill) before any component test. Standard Mode (tests alongside code).
