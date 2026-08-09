# Archive Report: Responsive Data Tables

**Change**: `responsive-data-tables`
**Archived**: 2026-06-14
**Artifact store**: openspec
**Verdict**: PASS WITH WARNINGS — Ready to archive

---

## Closure Summary

The `responsive-data-tables` change has been successfully implemented, verified, and is now archived. The shared `ResponsiveDataTable` component and its 23 consumer views deliver a fully responsive, accessible data-table solution for the frontend, bringing mobile-reachable row actions and WCAG-compliant a11y to all standalone CRUD list views.

**Status**: **COMPLETE AND ARCHIVED**

---

## What Shipped

### Shared Component and Baseline
- **`ResponsiveDataTable.tsx`**: A thin presentational wrapper (render-props API) that switches between desktop table (md+) and mobile definition-list cards (< md). CSS-driven (`hidden md:block` / `md:hidden`), no first-paint flicker, preserves per-row cell customization. Generic over `T extends IEntity`; no `any`.
- **A11y baseline**: `TableHead` emits `scope="col"` (call-site, per ADR-4 fallback); `StatusPill` and `CatalogPill` mark decorative dots `aria-hidden`; login "Recordarme" checkbox fixed (single node, properly associated label).
- **Test infra**: Vitest config added to `vite.config.ts` + `src/test/setup.ts` with `window.matchMedia` polyfill.
- **Component tests**: 9 test cases for the shared wrapper (card/table mode, `scope="col"`, actions reachability, stopPropagation, empty rows, breakpoint variants C.7).

### Consumer Adoption (23 views)

#### V1 (7 views + 1 declarative fan-out)
1. `CatalogCrudModule.tsx` → ~11 catalogs (tower, section, unit type, unit status, unit feature, unit relationship, expense category, vehicle, etc.)
2. `UnitListPage.tsx`
3. `ExpenseListPage.tsx` (worst case: 8 columns)
4. `PaymentListPage.tsx` (+ missing action aria-labels added)
5. `ProviderListPage.tsx`
6. `BankAccountListPage.tsx`
7. `AnnouncementListPage.tsx`

#### Phase D (16 additional standalone list views)
- **Admin delegates**: `SubscriptionPlanList`, `RoleList`, `UserList`, `AuditList`, `CollaboratorList`, `CompanyList`
- **Collections/Community**: `ChargeConceptListPage`, `ChargeListPage`, `ChargeBatchListPage`, `CommunityProjectListPage`, `ProjectContributionListPage`, `UnitUserListPage`
- **Security/Delinquency**: `AccessLogListPage`, `VisitorListPage`, `MyAccessLogPage`, `DelinquencyPage` (converted from raw `<table>` to shadcn primitives + wrapper)

**Total responsive coverage**: 23 views (7 original V1 + 16 Phase D). **Catalog fan-out**: ~11 simple catalogs inherit responsiveness from `CatalogCrudModule`.

### Column-Priority Convention
Applied `hidden md:table-cell` / `hidden lg:table-cell` across all adopters for tablet-width trimming. Optional `breakpoint="lg"` prop added (C.7) for wide views (Expense, Payment; activated at 1024px).

---

## Behavioral Outcomes

### Mobile (< 768px)
- Each row renders as a stacked definition-list card (`<dl>` with `<dt>`/`<dd>` pairs).
- Card title: entity identifier/name.
- Card body: label/value pairs for all non-action columns (all fields shown; vertical space unconstrained).
- Card header: title + actions (Edit/Delete/etc.) pinned top-right, always visible — **PRIMARY FIX: row actions no longer off-screen**.
- No horizontal scrolling.

### Tablet (768–1023px)
- Desktop table mode.
- Column priority convention: secondary/tertiary columns hidden per class (e.g., `hidden md:table-cell` → hidden below 1024px).
- Actions column always visible.
- No horizontal scrolling.

### Desktop (≥ 1024px or ≥ 768px per default `md` breakpoint)
- Full desktop table (all columns visible unless overridden by priority convention).
- **Zero regression**: desktop row JSX is identical to pre-change (pass-through).
- Actions in rightmost column.

---

## A11y Baseline Realized

1. **`scope="col"` on headers**: Every real `<th>` in all 23 consumers carries semantic scope (call-site per ADR-4; ~16 non-migrated tables do NOT inherit — trade-off noted in WARNING-2).
2. **Status indicators**: Decorative color dots (`aria-hidden`); status meaning conveyed by text.
3. **Action buttons**: All icon-only actions carry entity-aware `aria-label` (e.g., `"Editar unidad"`, `"Descargar comprobante de pago"`). SUGGESTION-1 flags one generic label in Expense desktop view (compliant but inconsistent).
4. **Login checkbox fix**: Single associated checkbox in a11y tree for "Recordarme".
5. **No live regions**: No `role="status"` on static pills (avoids screen-reader spam on list re-render).

---

## Verification Gate Results

| Gate | Result | Details |
|---|---|---|
| **Typecheck** | ✓ Exit 0 | No `any` introduced |
| **Tests** | ✓ 103 tests pass | 9 new component tests + 94 existing tests (16 test files) |
| **Lint** | ⚠ Exit 1 | 6 pre-existing errors in untouched files (`accept-invitation-form`, `ui/sidebar`, etc.); 28 warnings (all pre-existing except CatalogCrudModule max-lines → SUGGESTION-2) |

### Pre-Existing Lint Errors (NOT introduced by this change)
All 6 errors are `react-hooks/set-state-in-effect` or `react-hooks/purity` violations in unmodified files; no migrated view, component, or pill appears in the error set.

---

## Issues at Archive (2 WARNINGs + 3 SUGGESTIONs)

### WARNINGs (design choices ratified; no blockers)

**WARNING-1**: Spec §1.1 literal "MUST use `useIsMobile`" was deviated (ADR-3 ratified). Actual: CSS-driven switch (`hidden md:block` / `md:hidden`). **Impact**: Zero — behavioral intent met, anti-flicker achieved, zero new dependency. **Reconciliation**: Spec §1.1 should note "CSS breakpoint" as the mechanism.

**WARNING-2**: `scope="col"` applied at call sites (ADR-4 fallback), not the `table.tsx` primitive. **Impact**: All 23 consumers have `scope` applied; non-migrated tables do not inherit. **Reconciliation**: Trade-off acknowledged in design; react-frontend-architecture §4 rule respected.

### SUGGESTIONs (cosmetic follow-ups, no blockers)

**SUGGESTION-1**: `ExpenseListPage.tsx` has one generic desktop action label (`"Editar gasto"`) while card uses entity-aware (`"Editar gasto ${expense.concept}"`). Compliant; recommend unifying to entity-aware for consistency.

**SUGGESTION-2**: `CatalogCrudModule.tsx` line count = 515 (exceeds 500 lint warning). Recommend extracting header/field-builder helpers to drop under 500.

**SUGGESTION-3**: Spec §1.8 / §3 still says "remaining ~25 wrappers MUST NOT be modified in V1." Phase D expanded scope intentionally. Recommend adding closing note to archived spec for clarity on Phase D as intentional supersession.

---

## Artifacts Archived

| Artifact | Path | Status |
|---|---|---|
| Proposal | `/openspec/changes/archive/2026-06-14-responsive-data-tables/proposal.md` | ✓ Archived |
| Spec | `/openspec/changes/archive/2026-06-14-responsive-data-tables/specs/spec.md` | ✓ Archived |
| Design | `/openspec/changes/archive/2026-06-14-responsive-data-tables/design.md` | ✓ Archived |
| Tasks | `/openspec/changes/archive/2026-06-14-responsive-data-tables/tasks.md` | ✓ Archived |
| Verify Report | `/openspec/changes/archive/2026-06-14-responsive-data-tables/verify-report.md` | ✓ Archived |
| Archive Report (this file) | `/openspec/changes/archive/2026-06-14-responsive-data-tables/archive-report.md` | ✓ Archived |

**No main specs merged** (no `openspec/specs/` directory existed at archive time; delta specs became the archival record).

---

## Traceability & Chain of Custody

All SDD artifacts for this change are archived together:
1. **Proposal** (2026-06-13): Intent, scope, approach, success criteria.
2. **Spec** (2026-06-13): Authoritative post-conditions and acceptance scenarios.
3. **Design** (2026-06-13): 6 ADRs (architecture decisions), 11 risk mitigations, API contract, before/after migration pattern.
4. **Tasks** (2026-06-13): Phases A–D, 30 granular checkpoints, per-file verifiables.
5. **Verify Report** (2026-06-14): Gate results, spec compliance matrix, design coherence, issue log, out-of-scope confirmation.
6. **Archive Report** (2026-06-14): Closure summary, what shipped, behavioral outcomes, archive status.

**No commit/PR created** (user elected not to commit; artifacts remain in openspec for audit trail).

---

## Explicitly Out of Scope (Deferred)

The following remain untouched and deferred for future work:
- `StatementPage.tsx` (financial statement format with totals footer)
- `ChargeBatchDetailPage.tsx` / `CommunityProjectDetailPage.tsx` (detail-embedded sub-tables)
- ~14 non-CRUD tables: permission matrices, import wizards, report previews, dashboard widgets
- `TenantList.tsx` / `TenantTableRow.tsx` (reference-only, untouched)

These are **explicitly intentional deferrals**, not regressions.

---

## Recommendations for Next Work

1. **Immediate (cosmetic)**: SUGGESTION-2 (CatalogCrudModule line extraction).
2. **Short-term (spec clarity)**: SUGGESTION-3 (reconcile spec §1.8 language).
3. **Medium-term (a11y uniformity)**: SUGGESTION-1 (unify action label style in Expense).
4. **Long-term (complete coverage)**: Migrate the deferred out-of-scope tables in a focused follow-up (low risk, no new design).
5. **Consideration**: Primitive `scope="col"` edit (WARNING-2 trade-off) can be revisited if non-migrated tables grow significantly and need the baseline a11y for free.

---

## Sign-Off

**Change**: `responsive-data-tables`  
**Verdict**: PASS WITH WARNINGS — Ready to archive.  
**Archived**: 2026-06-14  
**Archiver note**: Implementation is complete, gates (typecheck/test) pass, all 23 consumers adopted, all ADRs honored, 2 design WARNINGs are intentional ratified deviations, 3 SUGGESTIONs are cosmetic follow-ups. The change is **CLOSED**.

No commit/PR created. Artifacts archived for audit trail and future reference.
