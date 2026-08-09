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

- [x] **A.1** Verify `frontend/src/test/setup.ts` contains a `window.matchMedia` mock. If absent, add the mock for jsdom compatibility.
- [x] **A.2** Add `scope="col"` as a defaulted prop on `TableHead` in `frontend/src/components/ui/table.tsx`. Change is STRICTLY ADDITIVE.
- [x] **A.3** Add `aria-hidden` to the decorative dot in `StatusPill`.
- [x] **A.4** Add `aria-hidden` to the decorative dot in `CatalogPill`.
- [x] **A.5** Fix the "Recordarme" nested-duplicate-checkbox a11y bug in `frontend/src/components/login-form.tsx`.
- [x] **A.6** Create `frontend/src/shared/components/molecules/ResponsiveDataTable.tsx` — the core shared wrapper.
- [x] **A.7** Create `frontend/src/shared/components/molecules/__tests__/ResponsiveDataTable.test.tsx` with 7 required test cases.

---

## Phase B — Mechanical V1 Adoption

- [x] **B.1** Adopt `ResponsiveDataTable` in `CatalogCrudModule.tsx` — fans out to ~11 catalogs.
- [x] **B.2** Adopt `ResponsiveDataTable` in `UnitListPage.tsx` with row-click navigation.
- [x] **B.3** Adopt `ResponsiveDataTable` in `ProviderListPage.tsx`.
- [x] **B.4** Adopt `ResponsiveDataTable` in `BankAccountListPage.tsx`.
- [x] **B.5** Adopt `ResponsiveDataTable` in `AnnouncementListPage.tsx`.
- [x] **B.6** Adopt `ResponsiveDataTable` in `ExpenseListPage.tsx` (worst case, 8 columns).
- [x] **B.7** Adopt `ResponsiveDataTable` in `PaymentListPage.tsx` and add missing `aria-label`s.

---

## Phase C — Verification Gates

- [x] **C.1** `pnpm lint` from `frontend/` must exit 0.
- [x] **C.2** `pnpm typecheck` must exit 0 (no `any` introduced).
- [x] **C.3** `pnpm test` must pass all tests (7 new cases in A.7, all existing tests).
- [ ] **C.4** Manual responsive check at 375/768/1440px.
- [ ] **C.5** Confirm no functional regression in list data flows.
- [x] **C.6** (SUPERSEDED by Phase D) `DelinquencyPage` migrated.

---

## Post-Review Fix — Tablet-band breakpoint residual

- [x] **C.7** Added optional `breakpoint?: 'md' | 'lg'` prop to `ResponsiveDataTable`. Set `breakpoint="lg"` on `ExpenseListPage` and `PaymentListPage`.

---

## Phase D — Full migration of remaining list views (scope expansion, user-requested)

### D.1 Batch 1 — admin views delegating to `*TableRow.tsx`
- [x] **D.1.1** `SubscriptionPlanList.tsx`
- [x] **D.1.2** `RoleList.tsx` (row-click /role/:id)
- [x] **D.1.3** `UserList.tsx`
- [x] **D.1.4** `AuditList.tsx`
- [x] **D.1.5** `CollaboratorList.tsx`
- [x] **D.1.6** `CompanyList.tsx`

### D.2 Batch 2 — Cobranza / Comunidad (inlined rows)
- [x] **D.2.1** `ChargeConceptListPage.tsx`
- [x] **D.2.2** `ChargeListPage.tsx`
- [x] **D.2.3** `ChargeBatchListPage.tsx`
- [x] **D.2.4** `CommunityProjectListPage.tsx`
- [x] **D.2.5** `ProjectContributionListPage.tsx`
- [x] **D.2.6** `UnitUserListPage.tsx`

### D.3 Batch 3 — Seguridad / Morosidad (inlined rows; DelinquencyPage raw→shadcn)
- [x] **D.3.1** `AccessLogListPage.tsx`
- [x] **D.3.2** `VisitorListPage.tsx`
- [x] **D.3.3** `MyAccessLogPage.tsx`
- [x] **D.3.4** `DelinquencyPage.tsx` (CONVERTED raw `<table>` → shadcn `Table*` + `ResponsiveDataTable`)

### D.4 Verification (combined, all batches)
- [x] **D.4.1** `pnpm typecheck` exit 0 (no `any`)
- [x] **D.4.2** `pnpm test` — 16 files / 103 tests pass
- [x] **D.4.3** `pnpm lint` — 0 new errors on changed files
- [x] **D.4.4** Playwright spot-check at 375px

### Out-of-scope (still deferred)
- `StatementPage.tsx`, `ChargeBatchDetailPage.tsx`, `CommunityProjectDetailPage.tsx`, and ~14 non-CRUD tables
