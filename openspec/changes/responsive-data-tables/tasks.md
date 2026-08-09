# Tasks: Responsive Data Tables (mobile-reachable row actions + a11y)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 700–900 (additions + deletions across 16 files) |
| 400-line budget risk | High |
| Chained PRs recommended | No (single-pr per proposal + `size:exception` already accepted) |
| Delivery strategy | single-pr · `size:exception` |
| Decision needed before apply | No (size:exception already recorded in proposal) |

### Notes for reviewer

The diff is large by design. It is ordered Phase A → Phase B so the reviewer can
approve the shared layer in isolation before reading the mechanical per-view
adoptions. Phase B adoptions are structurally repetitive (same wrapper call,
different entities) — a single careful review of Phase B-1 (`CatalogCrudModule`)
and B-2 (`UnitListPage`) validates the pattern; B-3 through B-7 are mechanical
confirmations.

Skills referenced for implementers:
- `/Users/rodriguez/code/residia/.agents/skills/react-frontend-architecture/SKILL.md`
- `/Users/rodriguez/code/residia/frontend/.cursor/skills/interface-design/SKILL.md`
- `/Users/rodriguez/code/residia/.agents/skills/web-accessibility/SKILL.md`

---

## Phase A — Shared Layer + A11y Baseline

> Review this phase FIRST. It defines the contract everything else depends on.
> All tasks here are sequential (each builds on the previous).

- [x] **A.1** Verify `frontend/src/test/setup.ts` contains a `window.matchMedia`
      mock. If absent, add the following block (jsdom has no layout engine so
      `matchMedia` throws without it). No other infrastructure changes needed —
      `vitest.config.ts` and `setup.ts` already exist and `pnpm test` already
      passes 11 files.

      ```ts
      // frontend/src/test/setup.ts — ADD if window.matchMedia mock is missing
      Object.defineProperty(window, 'matchMedia', {
        writable: true,
        value: (query: string) => ({
          matches: false, media: query, onchange: null,
          addEventListener: () => {}, removeEventListener: () => {},
          addListener: () => {}, removeListener: () => {}, dispatchEvent: () => false,
        }),
      })
      ```

      Files: `frontend/src/test/setup.ts`
      Verifiable: `pnpm test` still passes all existing test files; no
      `matchMedia is not a function` error when importing `useIsMobile`.
      Spec ref: §1.9 (quality gates), Design §7 Decision 6.

- [x] **A.2** Add `scope="col"` as a defaulted prop on `TableHead` in
      `frontend/src/components/ui/table.tsx`. Change is STRICTLY ADDITIVE —
      no class, style, or visual change. The default `'col'` is overridable
      with `scope={undefined}` or `scope="row"` for the rare row-header case.

      ```tsx
      // BEFORE (line 71 area):
      function TableHead({ className, ...props }: React.ComponentProps<'th'>) {

      // AFTER:
      function TableHead({ className, scope = 'col', ...props }: React.ComponentProps<'th'>) {
        return (
          <th
            data-slot="table-head"
            scope={scope}
            className={cn(/* existing classes unchanged */)}
            {...props}
          />
        )
      }
      ```

      **Reviewer flag — ADR-4 call-site fallback:** if the team rejects ANY
      change to `components/ui/`, apply `scope="col"` explicitly at every
      `<TableHead scope="col">` call site in Phase B views instead and document
      it as the convention going forward. The design RECOMMENDS the primitive
      edit; this note records the fallback for the reviewer to choose.

      Files: `frontend/src/components/ui/table.tsx`
      Verifiable: rendered `<th>` elements in any table carry `scope="col"` in
      the DOM; `pnpm typecheck` green.
      Spec ref: §1.4, Scenario 6.

- [x] **A.3** Add `aria-hidden` to the decorative dot in `StatusPill`. No
      `role="status"` — the text content already conveys the status and a live
      region would spam screen readers on every list re-render.

      ```tsx
      // frontend/src/modules/treasurerPanel/components/StatusPill.tsx
      // BEFORE: <i className={`h-1.5 w-1.5 flex-none rounded-full ${DOT[tone]}`} />
      // AFTER:
      <i aria-hidden className={`h-1.5 w-1.5 flex-none rounded-full ${DOT[tone]}`} />
      ```

      Files: `frontend/src/modules/treasurerPanel/components/StatusPill.tsx`
      Verifiable: rendered `<i>` has `aria-hidden="true"`; text label still
      visible; `pnpm lint` green.
      Spec ref: §1.6, Scenario 8.

- [x] **A.4** Add `aria-hidden` to the decorative dot in `CatalogPill`. Same
      rationale as A.3.

      ```tsx
      // frontend/src/shared/components/CatalogPill.tsx
      // BEFORE: <i className="size-1.5 rounded-full" style={{ backgroundColor: pillColor }} />
      // AFTER:
      <i aria-hidden className="size-1.5 rounded-full" style={{ backgroundColor: pillColor }} />
      ```

      Files: `frontend/src/shared/components/CatalogPill.tsx`
      Verifiable: rendered `<i>` has `aria-hidden="true"`; name text still
      accessible; `pnpm lint` green.
      Spec ref: §1.6, Scenario 8.

- [x] **A.5** Fix the "Recordarme" nested-duplicate-checkbox a11y bug in
      `frontend/src/components/login-form.tsx`. After the fix exactly ONE
      checkbox role node with a properly associated `<label>` must appear in
      the a11y tree for the "Recordarme" control. The likely fix is removing a
      duplicate or nested `<input type="checkbox">` that shadcn's `Checkbox`
      component already renders internally — verify the current markup before
      editing. No visual change intended.

      Files: `frontend/src/components/login-form.tsx`
      Verifiable: browser a11y tree (axe DevTools or Chrome Accessibility panel)
      shows exactly one checkbox for "Recordarme"; `pnpm lint` green.
      Spec ref: §1.7, Scenario 9.

- [x] **A.6** Create `frontend/src/shared/components/molecules/ResponsiveDataTable.tsx`.
      This is the core shared wrapper. Key implementation constraints:
      - Generic over `T extends IEntity`; NO `any`.
      - CSS-driven breakpoint switch (`hidden md:block` for the table branch;
        `md:hidden` for the card branch). Both branches render in the DOM
        simultaneously — CSS hides the inactive one. Do NOT use `useIsMobile`
        for the visual switch (avoids first-paint flicker).
      - Desktop branch: renders `<Table>{header}<TableBody>{rows.map(renderRow)}</TableBody></Table>`
        wrapped in the existing `overflow-hidden rounded-[14px] border` card.
      - Mobile branch: renders `<ul role="list">` of `<li>` cards, one per
        entity. Each card has a header row (`renderCardTitle` + `renderActions`
        pinned right, wrapped in `onClick={stopPropagation}` so card nav click
        does not bleed into action buttons) and a `<dl>` body of
        `{ label, value }` pairs from `renderCardFields`.
      - `onRowClick` on the card triggers navigation (passed through from the
        module); the `stopPropagation` wrapper on actions prevents it from
        firing when the user clicks Edit/Delete.
      - No barrel file export (react-frontend-architecture §4 rule 1 — import by full path).
      - All Spanish UI text (none in this component — it is purely structural).
      - Prop types: `rows`, `header`, `renderRow`, `renderCardTitle`,
        `renderCardFields` (returns `ResponsiveField[]`), `renderActions?`,
        `onRowClick?`, `getRowKey?`.

      Files: `frontend/src/shared/components/molecules/ResponsiveDataTable.tsx`
      Verifiable: `pnpm typecheck` green; imports resolve; no `any`.
      Spec ref: §1.1, §1.3, Design §1 (Architecture Approach), Design §2 Decision 1.

- [x] **A.7** Create `frontend/src/shared/components/molecules/__tests__/ResponsiveDataTable.test.tsx`.
      Standard Mode (strict_tdd = FALSE): tests authored alongside A.6.
      Required test cases (spec §1.9 + Design §7 Decision 6):

      1. **Desktop table present:** given `rows` + `header` + `renderRow`,
         `getAllByRole('table')` returns 1; `getAllByRole('row')` includes header
         + data rows.
      2. **`scope="col"` on column headers:** every `columnheader` role element
         has `scope="col"` attribute (validates A.2 end-to-end through the
         primitive).
      3. **Cards present:** `<ul role="list">` exists; one `<li>` per entity;
         `<dl>` inside each `<li>` contains `<dt>` text matching
         `ResponsiveField.label` values.
      4. **Actions reachable in both branches:** `renderActions` output (e.g.
         `<button aria-label="Editar X">`) is queryable with
         `getAllByRole('button', { name: /editar/i })`; count equals 2 per row
         (one in desktop row, one in card header — both in DOM).
      5. **`onRowClick` fires on card body click; action click does NOT bubble:**
         click on card body → `onRowClick` called; click on action button inside
         card → `onRowClick` NOT called (stopPropagation).
      6. **`matchMedia` mock does not throw:** component mounts without error when
         `window.matchMedia` is the stub from `setup.ts`.
      7. **Empty `rows` array:** component renders without error; no `<li>` in
         the card list; `<TableBody>` is empty.

      Files: `frontend/src/shared/components/molecules/__tests__/ResponsiveDataTable.test.tsx`
      Verifiable: `pnpm test` passes all 7 cases; no `any` in test file.
      Spec ref: §1.9, Scenarios 10, 11, 12.

---

## Phase B — Mechanical V1 Adoption

> Each task is independently verifiable. Desktop behavior must be identical to
> pre-change (the `renderRow` pass-through guarantees this). Do NOT touch data
> fetching, store, permissions, search, pagination, modals, or `ConfirmDialog`
> in any of these. `TenantList.tsx` / `TenantTableRow.tsx` must remain untouched
> (reference-only).

- [x] **B.1** Adopt `ResponsiveDataTable` in `CatalogCrudModule.tsx`.
      This single change fans out to ~11 simple catalog list views (tower,
      section, unit type, unit status, unit feature, unit relationship, expense
      category, vehicle, and any other catalog routed through
      `CatalogCrudModule`).

      Migration contract:
      - `rows` = the entities array the module already uses for its table body.
      - `header` = the existing `<TableHeader><TableRow>…</TableRow></TableHeader>`
        (keep `<TableHead>` text as-is; these strings become the `label` source
        for cards via the design contract).
      - `renderRow` = the existing `<TableRow>…</TableRow>` per entity
        (lines ~434–483); preserve `stopPropagation` on the actions `<TableCell>`.
      - `renderCardTitle` = the entity's primary name/identifier field (the first
        column value).
      - `renderCardFields` = `columns` prop content mapped to
        `{ label: '<TableHead text>', value: <cell value> }` pairs, EXCLUDING
        the actions column. Include "Fecha de creación" if visible in the header.
      - `renderActions` = the existing edit/delete button node (same JSX used in
        the actions `<TableCell>`).
      - Column priority for tablet: add `hidden md:table-cell` to secondary
        columns (e.g. "Fecha de creación") in `renderRow`'s `<TableHead>` and
        matching `<TableCell>`. Mirror in `header`. The card always shows all
        fields (vertical space is unconstrained).
      - Actions `<TableCell>` must keep `onClick={(e) => e.stopPropagation()}`.

      Files: `frontend/src/shared/components/catalog/CatalogCrudModule.tsx`
      Verifiable: any catalog list (e.g. `/tower`) renders cards at 375px without
      horizontal scroll; edit/delete buttons reachable in card; desktop table
      unchanged at 1440px; `pnpm test` green; no `any`.
      Spec ref: §1.8, Scenario 5.

- [x] **B.2** Adopt `ResponsiveDataTable` in `UnitListPage.tsx`. This view has
      row-click navigation (`onClick={() => navigate(...)}`); the `stopPropagation`
      on the actions cell MUST be preserved in both representations.

      Migration contract (see Design §8 before/after for the exact pattern):
      - `onRowClick={(unit) => navigate('/unit/'+unit.id)}` passed to wrapper.
      - `renderCardTitle` = `unit.full_identifier ?? unit.number`.
      - `renderCardFields` = Tipo, Sección, Indiviso, Estado.
      - `renderActions` = edit + delete `<Button size="icon">` cluster with
        `aria-label={`Editar ${unit.full_identifier ?? ''}`}` and
        `aria-label={`Eliminar ${unit.full_identifier ?? ''}`}`.
      - Column priority: `<TableHead className="hidden md:table-cell">Sección</TableHead>`,
        `<TableHead className="hidden lg:table-cell">Indiviso</TableHead>` (and
        matching `<TableCell>` classes in `renderRow`). Identificador + Estado +
        actions column always visible.
      - Desktop `renderRow`: keep existing `onClick={() => navigate(...)}` on
        `<TableRow>` and `onClick={(e) => e.stopPropagation()}` on actions cell.

      Files: `frontend/src/modules/unit/UnitListPage.tsx`
      Verifiable: unit list renders cards at 375px; row tap navigates to unit
      detail; edit/delete reachable without horizontal scroll; desktop table
      unchanged; `aria-label` present on action buttons; `pnpm typecheck` green.
      Spec ref: §1.1, §1.3, §1.5, Scenarios 1, 2, 3, 4, 7.

- [x] **B.3** Adopt `ResponsiveDataTable` in `ProviderListPage.tsx`.

      Migration contract:
      - No row-click navigation (verify before editing; omit `onRowClick` if absent).
      - `renderCardTitle` = provider name/business name (primary identifier column).
      - `renderCardFields` = remaining columns (type, service type, is_recurring,
        estimated_amount with `displayMoney`, usual_payment_day — include all
        columns visible in the desktop header).
      - `renderActions` = edit + delete buttons with
        `aria-label={`Editar proveedor`}` / `aria-label={`Eliminar proveedor`}`.
      - Column priority: apply `hidden md:table-cell` to secondary columns
        (type, estimated_amount, usual_payment_day) in `renderRow` and `header`.

      Files: `frontend/src/modules/provider/ProviderListPage.tsx`
      Verifiable: provider list renders cards at 375px; actions reachable;
      `aria-label` on buttons; desktop unchanged; `pnpm typecheck` green.
      Spec ref: §1.1, §1.3, §1.5, §1.8, Scenarios 1, 2, 7.

- [x] **B.4** Adopt `ResponsiveDataTable` in `BankAccountListPage.tsx`.

      Migration contract:
      - `renderCardTitle` = account name or bank name (primary identifier).
      - `renderCardFields` = remaining columns (bank, account_type, purpose,
        current_balance with `displayMoney`, etc.).
      - `renderActions` = edit + delete with
        `aria-label={`Editar cuenta bancaria`}` /
        `aria-label={`Eliminar cuenta bancaria`}`.
      - Column priority: apply `hidden lg:table-cell` to tertiary columns
        (purpose, account number if shown) in `renderRow` and `header`.

      Files: `frontend/src/modules/bankAccount/BankAccountListPage.tsx`
      Verifiable: bank account list renders cards at 375px; balance visible in
      card; actions reachable; desktop unchanged; `pnpm typecheck` green.
      Spec ref: §1.1, §1.3, §1.5, §1.8.

- [x] **B.5** Adopt `ResponsiveDataTable` in `AnnouncementListPage.tsx`.

      Migration contract:
      - `renderCardTitle` = announcement title (primary identifier column).
      - `renderCardFields` = type (`CatalogPill`), priority, status, published_at
        (formatted with `displayFormatDate`), author.
      - `renderActions` = edit + delete buttons with
        `aria-label={`Editar comunicado`}` /
        `aria-label={`Eliminar comunicado`}`.
      - Column priority: apply `hidden md:table-cell` to secondary columns
        (author, published_at) and `hidden lg:table-cell` to tertiary (priority).

      Files: `frontend/src/modules/announcement/AnnouncementListPage.tsx`
      Verifiable: announcement list renders cards at 375px; type pill visible in
      card; actions reachable; desktop unchanged; `pnpm typecheck` green.
      Spec ref: §1.1, §1.3, §1.5, §1.8.

- [x] **B.6** Adopt `ResponsiveDataTable` in `ExpenseListPage.tsx`. This is
      the worst-case view (8 columns) — it validates that cards beat horizontal
      scroll even for the widest table.

      Migration contract:
      - `renderCardTitle` = expense description or concept (primary identifier).
      - `renderCardFields` = ALL non-action columns (amount with `displayMoney`,
        category `CatalogPill`, beneficiary_type, beneficiary name/link,
        bank_account name, expense_date with `displayFormatDate`,
        community_project link if applicable). Show all fields in card (vertical
        space is unconstrained; the card is what replaces an 8-column table).
      - `renderActions` = conditional media-download button + edit + delete buttons.
        Include `aria-label={`Editar gasto`}` and `aria-label={`Eliminar gasto`}`.
        If the download button is icon-only, add `aria-label="Descargar comprobante"`.
      - Column priority: apply `hidden md:table-cell` to 3–4 secondary columns
        (beneficiary, bank_account, category) and `hidden lg:table-cell` to
        tertiary (created_at). Identifier + amount + date + actions stay visible
        at tablet width.

      Files: `frontend/src/modules/expense/ExpenseListPage.tsx`
      Verifiable: expense list renders cards at 375px with all 8 fields visible
      vertically; zero horizontal scroll; download + edit + delete reachable;
      desktop 8-column table unchanged at 1440px; `pnpm typecheck` green.
      Spec ref: §1.1, §1.3, §1.5, §1.8, Scenarios 1, 2.

- [x] **B.7** Adopt `ResponsiveDataTable` in `PaymentListPage.tsx` AND add
      missing `aria-label` to ALL icon-only action buttons in this view.
      Payment is a special case: its inline action cluster may include
      download, reject, validate, and cancel buttons — ALL must carry
      `aria-label`.

      Migration contract:
      - `renderCardTitle` = receipt_number (primary identifier, e.g. `R-2026-000042`).
      - `renderCardFields` = unit/payer identity, amount with `displayMoney`,
        method (payment method label), bank_account name, paid_at with
        `displayFormatDate`, status `StatusPill`.
      - `renderActions` = the existing `PaymentRowActions` node (or inline
        cluster) with ALL icon-only buttons carrying `aria-label`:
        - `aria-label="Descargar comprobante de pago"`
        - `aria-label="Rechazar pago"`
        - `aria-label="Validar pago"`
        - `aria-label="Cancelar pago"`
        Adapt the entity name where the API provides it (e.g.
        `aria-label={`Eliminar pago ${payment.receipt_number}`}`).
      - Column priority: apply `hidden md:table-cell` to method, bank_account;
        `hidden lg:table-cell` to paid_at. Unit + amount + status + actions
        always visible.
      - If Payment's inline text-pill action buttons (reject/validate) appear
        cramped in the card header at 375px, migrate them to
        `ActionButtonGroup dropdown` for the mobile card only, or place them in
        `renderCardFields` as a dedicated "Acciones" row — verify visually at
        375px before merge.

      Files: `frontend/src/modules/payment/PaymentListPage.tsx`
      Verifiable: payment list renders cards at 375px; receipt_number visible;
      all 4 action buttons have `aria-label`; accessible name identifies both
      action AND entity (Scenario 7); `pnpm typecheck` green; `pnpm lint` green.
      Spec ref: §1.3, §1.5, §1.8, Scenarios 2, 7.

---

## Phase C — Verification Gates

- [x] **C.1** Run `pnpm lint` from `frontend/`. Must exit 0. Fix any lint errors
      introduced in Phase A or B before considering the change complete.
      Files: `frontend/` (lint scope)
      Spec ref: §1.9, Scenario 14.

- [x] **C.2** Run `pnpm typecheck` (i.e. `tsc -p tsconfig.app.json --noEmit`)
      from `frontend/`. Must exit 0. No `any` may have been introduced. Confirm
      with `grep -r ": any" src/shared/components/molecules/ResponsiveDataTable.tsx`
      returns nothing.
      Files: `frontend/` (typecheck scope)
      Spec ref: §1.9, Scenario 14.

- [x] **C.3** Run `pnpm test` (`vitest run`) from `frontend/`. Must pass ALL
      tests including the 7 new cases in A.7. Baseline: 11 existing test files
      continue to pass.
      Files: `frontend/` (test scope)
      Spec ref: §1.9, Scenario 14.

- [ ] **C.4** Manual responsive check (tester note — not automatable in this
      stack). For each V1 view (unit, expense, payment, provider, bankAccount,
      announcement, and one catalog via CatalogCrudModule):
      - **375px**: rows render as cards; no horizontal scrollbar; edit + delete
        buttons visible inside card without scrolling.
      - **768px**: table mode; actions column visible; secondary columns hidden
        per priority convention.
      - **1440px**: all columns visible; desktop layout matches pre-change.

      Navigation note: the auth token is in-memory only (no cookie persistence).
      Navigate between list views via the SPA router (sidebar links), NOT by
      direct URL reload after initial login.

      Files: browser (manual)
      Spec ref: §1.1, §1.2, §1.3, Scenarios 1, 2, 3, 4.

- [ ] **C.5** Confirm no functional regression in list data flows. For each V1
      view: trigger a search, change page, and (if applicable) change sort — data
      must behave identically to pre-change. No additional network requests beyond
      those present before the change.
      Files: browser (manual)
      Spec ref: §1.9, Scenario 13.

- [x] **C.6** (SUPERSEDED by Phase D) Originally asserted `DelinquencyPage` stay
      untouched as a deferred follow-up. Scope was later expanded by user request
      (see Phase D): `DelinquencyPage` was migrated — its raw `<table>` was converted
      to shadcn primitives + `ResponsiveDataTable`. The deferral no longer applies.
      Files: `frontend/src/modules/payment/DelinquencyPage.tsx`

---

## Post-Review Fix — Tablet-band breakpoint residual

- [x] **C.7** Fix tablet-band overflow for wide views (Egresos 8 cols, Pagos 7 cols).
      Added optional `breakpoint?: 'md' | 'lg'` prop to `ResponsiveDataTable` with
      a static `BRANCH_CLASSES` lookup (Tailwind JIT-safe — no dynamic class building).
      Set `breakpoint="lg"` on `ExpenseListPage` and `PaymentListPage` so card mode
      activates up to 1024px. Cleaned up now-redundant `hidden md:table-cell` column
      priority classes on those two `lg`-breakpoint views (desktop ≥1024px shows all
      columns anyway). All other views stay at default `md`.
      Added 2 new test cases (cases 8 and 9) to `ResponsiveDataTable.test.tsx`.
      Gates: typecheck exit 0, 103 tests pass (up from 101), 0 lint errors on changed files.
      Files: `ResponsiveDataTable.tsx`, `__tests__/ResponsiveDataTable.test.tsx`,
             `ExpenseListPage.tsx`, `PaymentListPage.tsx`

---

## Phase D — Full migration of remaining list views (scope expansion, user-requested)

> After V1 (Phase B) the user asked to migrate ALL remaining hand-rolled list
> tables, not just the V1 set. Enumeration found 16 pending standalone list
> views (11 catalog pages were already covered transitively by
> `CatalogCrudModule`; ~14 trivial tables — permission matrices, import wizards,
> report previews, dashboard widgets, `StatementPage`/`ChargeBatchDetailPage`
> detail/statement tables — were intentionally left out as not CRUD lists).
> Each migration preserves data fetching, stores, permissions, search,
> pagination, modals, row-click nav, and `stopPropagation` on action cells.
> `TenantList`/`TenantTableRow` remain reference-only (untouched).

### D.1 Batch 1 — admin views delegating to `*TableRow.tsx`
- [x] **D.1.1** `SubscriptionPlanList.tsx` (md)
- [x] **D.1.2** `RoleList.tsx` (md; row-click /role/:id; IRoleRuntime cast bridges schema gap)
- [x] **D.1.3** `UserList.tsx` (lg; super-admin conditional columns synced in header + card fields)
- [x] **D.1.4** `AuditList.tsx` (md; read-only, "Ver cambios")
- [x] **D.1.5** `CollaboratorList.tsx` (lg; `tableOnly` → no actions, preserved)
- [x] **D.1.6** `CompanyList.tsx` (lg)

### D.2 Batch 2 — Cobranza / Comunidad (inlined rows)
- [x] **D.2.1** `ChargeConceptListPage.tsx` (lg; Nombre Link in card title; actions gated by canEdit)
- [x] **D.2.2** `ChargeListPage.tsx` (lg; discount/cancel icon actions; null actions for cancelled charges)
- [x] **D.2.3** `ChargeBatchListPage.tsx` (md; onRowClick → /charge-batch/:id; no actions)
- [x] **D.2.4** `CommunityProjectListPage.tsx` (md; onRowClick nav; no actions)
- [x] **D.2.5** `ProjectContributionListPage.tsx` (md; "Aportar" action; Proyecto Link)
- [x] **D.2.6** `UnitUserListPage.tsx` (lg; Unidad Link; read-only)

### D.3 Batch 3 — Seguridad / Morosidad (inlined rows; DelinquencyPage raw→shadcn)
- [x] **D.3.1** `AccessLogListPage.tsx` (lg; "Corregir" action gated by canCorrect; pagination outside table)
- [x] **D.3.2** `VisitorListPage.tsx` (lg; QR/cancel/approve/reject actions with entity-specific aria-labels)
- [x] **D.3.3** `MyAccessLogPage.tsx` (md; resident read-only; prev/next pagination outside table)
- [x] **D.3.4** `DelinquencyPage.tsx` (md; CONVERTED raw `<table>` → shadcn `Table*` + `ResponsiveDataTable`; `getRowKey` since DelinquencyUnit has no `id`)

### D.4 Verification (combined, all batches)
- [x] **D.4.1** `pnpm typecheck` exit 0 (no `any`)
- [x] **D.4.2** `pnpm test` — 16 files / 103 tests pass
- [x] **D.4.3** `pnpm lint` — 0 new errors on changed files (6 pre-existing errors in untouched files remain)
- [x] **D.4.4** Playwright spot-check at 375px: `/charge-batch` renders cards (title + fields + progress bar), no horizontal scroll. (Earlier: unit/expense/bank-account verified at 375/768/1440; keyboard nav on card confirmed.)

### Out-of-scope (still deferred)
- `StatementPage.tsx` (financial statement format with totals footer), `ChargeBatchDetailPage.tsx` / `CommunityProjectDetailPage.tsx` (detail-embedded sub-tables), and ~14 non-CRUD tables (permission matrices, import wizards, report previews, dashboard widgets) were intentionally NOT migrated.
