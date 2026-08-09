# Verification Report: Responsive Data Tables

**Change**: `responsive-data-tables`
**Date**: 2026-06-14
**Mode**: Standard verify (strict_tdd = FALSE — tests authored alongside code are compliant)
**Artifact store**: openspec
**Verdict**: **PASS WITH WARNINGS**
**Ready to archive**: YES

---

## Executive Summary

The change is implemented faithfully and exceeds V1 scope as intended (Phase D migrated
ALL standalone CRUD list views). The shared `ResponsiveDataTable` component exists with the
documented API (plus the C.7 `breakpoint` and `footer` extensions). All 23 consumer views
import and use it, every real `<TableHead>` carries `scope="col"`, both pills mark their
decorative dot `aria-hidden`, the login "Recordarme" control is a single associated checkbox,
and action buttons carry entity-aware `aria-label`s. **typecheck = exit 0**, **test = 16 files /
103 tests pass**, **lint = exit 1 but ALL 6 errors are pre-existing in files outside this change**
(no new lint error introduced). Out-of-scope assertions hold (Tenant reference files and detail/
statement tables untouched).

**Issue counts: CRITICAL 0 · WARNING 2 · SUGGESTION 3**

---

## Completeness (tasks)

| Phase | Tasks | Done | Notes |
|---|---|---|---|
| A (shared layer + a11y) | A.1–A.7 | 7/7 | Test infra, scope=col convention, pills, login, wrapper + tests |
| B (V1 adoption) | B.1–B.7 | 7/7 | CatalogCrudModule + 6 inline views |
| C (gates) | C.1–C.6 | 4/4 code + 2 manual | C.4/C.5 manual (tester); C.6 superseded by Phase D |
| C.7 (post-review) | breakpoint fix | 1/1 | `breakpoint='lg'` on Expense + Payment |
| D (full migration) | D.1.1–D.4.4 | 16 views + 4 verif | All marked done |

Manual-only tasks C.4/C.5 are inherently non-automatable in this stack; the orchestrator's
Playwright checks (375/768/1440 on unit/expense/bank-account/charge-batch, keyboard Enter nav,
BankAccount footer flush) stand as evidence, not gaps.

---

## Gate Results (actual)

| Gate | Command | Result |
|---|---|---|
| Typecheck | `pnpm typecheck` (`tsc -p tsconfig.app.json --noEmit`) | **exit 0** — clean |
| Tests | `pnpm test` (`vitest run`) | **16 files / 103 tests passed** |
| Lint | `pnpm lint` | exit 1 — **6 errors, all PRE-EXISTING** (see below); 28 warnings |

### Lint errors — all pre-existing, NONE introduced by this change
All 6 errors are `react-hooks/set-state-in-effect` / `react-hooks/purity` in files NOT touched
by this change:
- `src/components/accept-invitation-form.tsx:224`
- `src/components/ui/sidebar.tsx:610`
- `src/modules/access/components/ScanQrModal.tsx:37`
- `src/shared/auth/SessionProvider.tsx:27`
- `src/shared/components/ChangePasswordModal.tsx:34`
- `src/shared/components/ProfilePhotoModal.tsx:32`

No migrated view, the shared component, the pills, login-form, or table.tsx appears in the
error set. The only change-related lint item is a `max-lines` **warning** on
`CatalogCrudModule.tsx` (515 > 500) — see SUGGESTION-2.

> Note vs spec §1.9 / Scenario 14: the literal requirement is `pnpm lint` exits 0. It exits 1
> due to the pre-existing errors. Because none are attributable to this change and the verify
> brief explicitly classifies these files as pre-existing, this is a WARNING, not a CRITICAL.

---

## Spec Compliance Matrix

| Req / Scenario | Status | Evidence |
|---|---|---|
| §1.1 Shared component (card < md / table ≥ md, no new dep) | MET | `ResponsiveDataTable.tsx` — CSS-driven `hidden md:block` / `md:hidden` |
| §1.1 "MUST use `useIsMobile`" | MET (deviated, ratified) | Uses CSS switch, NOT the hook (ADR-3, anti-flicker). Behavioral intent met. **WARNING-1** |
| §1.2 Column-priority `hidden md/lg/sm:table-cell` | MET | Applied across all V1 and Phase D views |
| §1.3 Actions always reachable (375/768/1440) | MET | Actions pinned in card header; never hidden |
| §1.4 `scope="col"` on `<th>` | MET (call-site fallback) | Applied at all 23 call sites. **WARNING-2** documents the deviation |
| §1.4 `overflow-x-auto` last-resort only | MET | Retained as fallback; cards prevent activation < md |
| §1.5 Action `aria-label` (action + entity) | MET | All views have entity-aware labels. **SUGGESTION-1** (one generic label in Expense) |
| §1.5 PaymentListPage missing aria-labels added | MET | All 4 action buttons labeled |
| §1.6 Decorative dot `aria-hidden` | MET | StatusPill and CatalogPill marked |
| §1.6 Status conveyed by text; no role=status on static pills | MET | Both pills render text; neither carries `role` |
| §1.7 "Recordarme" single checkbox | MET | One checkbox with proper label |
| §1.8 V1 adoption set | MET + EXCEEDED | All 7 V1 surfaces + 16 Phase D views |
| §1.8 Tenant reference untouched | MET | TenantList/TenantTableRow not in diff |
| §1.9 No `any` | MET | No `any` across all changes |
| §1.9 UI text Spanish | MET | All labels/aria-labels Spanish |
| §1.9 lint/typecheck/test green | PARTIAL | typecheck+test green; lint exit 1 (pre-existing only) → WARNING |
| §1.9 Vitest cases | MET | 9 test cases (7 original + 2 from C.7) |
| Scenario 1 Card mode 375px no h-scroll | MET (manual evidence) | Playwright checks verified |
| Scenario 2 Actions reachable 375px | MET | renderActions pinned in card header |
| Scenario 3 Table mode 768px actions visible | MET | Actions col never hidden |
| Scenario 4 Desktop regression-free 1440px | MET (manual evidence) | renderRow pass-through identical |
| Scenario 5 CatalogCrudModule fan-out | MET | Covers ~11 catalogs |
| Scenario 6 scope=col on all `<th>` | MET | All 23 consumers scoped |
| Scenario 7 Payment + V1 action aria-labels | MET | All buttons labeled |
| Scenario 8 Decorative dot aria-hidden | MET | StatusPill and CatalogPill |
| Scenario 9 Single checkbox node | MET | login-form.tsx fixed |
| Scenario 10 Vitest card mode | MET | Test case present |
| Scenario 11 Vitest table mode | MET | Test case present |
| Scenario 12 Vitest actions both modes | MET | Test case validates count = rows × 2 |
| Scenario 13 No data-flow regression | MET (manual + structural) | Stores/hooks untouched |
| Scenario 14 Gates pass | PARTIAL | typecheck+test pass; lint exit 1 (pre-existing) → WARNING |

---

## Design Coherence

| ADR | Status | Evidence |
|---|---|---|
| ADR-1 Wrapper over engine | HONORED | No `@tanstack/react-table`; render-props only |
| ADR-2 Cards below breakpoint | HONORED | `<dl>` card branch + `hidden md/lg:table-cell` priority |
| ADR-3 CSS switch not JS | HONORED | `useIsMobile` NOT used for switch; CSS-driven |
| ADR-4 scope=col in primitive | DEVIATED (intended) | Call-site scope applied; table.tsx untouched — respects react-frontend-architecture §4 |
| ADR-5 Pills aria-hidden, no role | HONORED | Dots aria-hidden; no role=status |
| ADR-6 Test infra prerequisite | HONORED | vitest.config.ts + setup.ts present, 103 tests run |

---

## Issues

### CRITICAL
None.

### WARNING

**WARNING-1 — Spec §1.1 literal "MUST use `useIsMobile`" deviation (ratified by ADR-3).**
The shared component switches modes via CSS (`hidden md:block` / `md:hidden`), NOT the
`useIsMobile` hook, to avoid first-paint flicker. This is a deliberate, documented design
decision (design.md §3, ADR-3) that satisfies the spec's behavioral intent (responsive switch
at the 768px md breakpoint, zero new dependency) while contradicting the spec's literal hook
wording. Does NOT break any acceptance scenario. Recommend the spec text be reconciled at
archive (note the CSS switch as the chosen mechanism).

**WARNING-2 — `scope="col"` applied at call sites, not the `table.tsx` primitive (ADR-4 fallback).**
Spec §1.4 and design ADR-4 RECOMMENDED adding `scope="col"` as a default on the `TableHead`
primitive. The team chose the documented fallback: leave `components/ui/table.tsx` untouched
(respecting react-frontend-architecture §4 "never modify components/ui") and apply `scope="col"` at every
call site. Verified COMPLETE across all 23 consumers. Trade-off (acknowledged in design): the
~16 non-migrated tables in the app do NOT get `scope="col"` for free. Not a blocker; it is the
sanctioned alternative.

### SUGGESTION

**SUGGESTION-1 — One desktop-side generic action label in ExpenseListPage.**
`ExpenseListPage.tsx:179` uses `aria-label="Editar gasto"` (generic) in the desktop `renderRow`,
while the card branch (`:240`) uses the entity-aware `aria-label="Editar gasto ${expense.concept}"`.
Spec §1.5 permits a generic label, so this is compliant, but the two representations are
inconsistent. Consider making the desktop label entity-aware too for uniformity.

**SUGGESTION-2 — `CatalogCrudModule.tsx` exceeds the 500-line lint warning (515).**
A `max-lines` WARNING (not an error). Consider extracting the header/card-field builders to a
helper to drop under 500. Cosmetic; does not affect the gate.

**SUGGESTION-3 — Reconcile spec/tasks scope language at archive.**
The spec still says §1.8 "remaining ~25 wrappers MUST NOT be modified in V1," which Phase D
intentionally superseded. tasks.md already records the expansion; the spec §1.8 / §3 text
should get a closing note so the archived delta is unambiguous.

---

## Out-of-Scope Verification

| Assertion | Result |
|---|---|
| `TenantList.tsx` / `TenantTableRow.tsx` untouched | CONFIRMED — not in diff |
| `StatementPage.tsx` not migrated | CONFIRMED |
| `ChargeBatchDetailPage.tsx` / `CommunityProjectDetailPage.tsx` not migrated | CONFIRMED |
| `DelinquencyPage` raw `<table>` converted | CONFIRMED — uses `getRowKey={u=>u.unit_id}` |
| Non-CRUD matrix/import/preview/dashboard tables not migrated | CONFIRMED |

---

## Final Verdict

**PASS WITH WARNINGS.** Zero CRITICAL issues. Implementation is complete, faithful to design,
gates (typecheck/test) green, and lint failures are entirely pre-existing and outside this
change's footprint. The two WARNINGs are documented, intentional design deviations that do not
break any acceptance scenario. **The change is READY TO ARCHIVE.** Recommend reconciling spec
§1.1 (CSS vs hook), §1.4/ADR-4 (call-site scope), and §1.8 (Phase D scope expansion) into the
archived delta for an accurate historical record.
