# Verification Report — export-excel-lists

- **Change**: export-excel-lists
- **Project**: residia-monorepo (repo root `/Users/rodriguez/code/residia`)
- **Artifact store**: hybrid (this file + engram `sdd/export-excel-lists/verify-report`)
- **Verified**: 2026-07-06 (fresh context; apply-agent claims re-checked against source)
- **Verdict**: **PASS-WITH-NOTES** — backend is archive-ready; frontend contract is met but archive is **blocked on a stable green `pnpm typecheck`** because a concurrent session is live-reworking the frontend in this shared working tree.

## Completeness (tasks ledger vs reality)

| Phase | Tasks | State |
|---|---|---|
| 1 — ReportController hardening | 1.1–1.8 | ✅ all ticked, all present in code |
| 2 — `reportColumns()` on 15 models | 2.1–2.7 | ✅ all 15 models have it |
| 3 — Tests & lint | 3.1–3.7 | ✅ 5 tests pass, Pint clean |
| 4 — Frontend wiring | 4.1–4.15, 4.17 | ✅ present in tree |
| 4.16 — Collaborator manual regression | — | ⏳ DEFERRED to manual QA (not a failure, per plan) |

## Build / Test / Type-check evidence (verbatim)

**Backend tests** (host php, single process, from `backend/`):
`php artisan test --compact --filter=CustomReport`
```
Tests:    5 passed (13 assertions)
Duration: 14.18s
```
Exit 0.

**Backend lint** (`vendor/bin/pint --dirty --test`):
```
{"tool":"pint","result":"passed"}
```

**Frontend typecheck** (`pnpm typecheck` = `tsc -p tsconfig.app.json --noEmit`, from `frontend/`) — **NON-DETERMINISTIC / currently failing**. Five consecutive samples during verification:
```
run A  exit=0  CLEAN
run B  exit=2  src/shared/hooks/useBaseList.ts(60,12): error TS6133: 'isExporting' is declared but its value is never read.
run C  exit=2  src/modules/expense/ExpenseListPage.tsx(29,35): error TS6133: 'Loader2' is declared but its value is never read.
run D  exit=2  src/modules/provider/ProviderListPage.tsx(42,20): error TS6133: 'Loader2' is declared but its value is never read.
run E  exit=2  src/modules/communityProject/CommunityProjectListPage.tsx(18,20): error TS6133: 'Loader2' is declared but its value is never read.
```
Root cause: the working tree is being mutated by a concurrent session (the documented "shared multi-session WIP" gotcha). The errors are transient mid-edit unused-symbol errors that march across pages as an enhanced export variant (`useBaseList.isExporting` flag + per-page `Loader2` spinner) is progressively applied. This variant is **NOT in the verified design/tasks** and modifies the shared `useBaseList.ts` hook. The originally-delivered frontend (per apply-progress) used the store's `isLoading` + a plain `Download` icon and was typecheck-clean.

## Change surface (regression sanity)

**Backend** — STABLE across the whole verify (`git diff --stat`): 16 files, +713/−14. Exactly `ReportController.php` + the 15 mapped models. New test file `tests/Feature/CustomReportTest.php` is untracked (expected — a "Create"). No unexpected files.

**Frontend** — GREW during the verify: 17 files (+346/−127) at start → **18 files (+403/−132)** now, the added file being `src/shared/hooks/useBaseList.ts`. Per-page line counts increased (e.g. ExpenseListPage 27→33, ProviderListPage 43→47) as the concurrent enhancement rolled out. The 17 originally-expected files match the design's File-Changes table exactly; `useBaseList.ts` is the out-of-design 18th.

## Spec compliance matrix

### custom-report-endpoint

| Requirement | Evidence | Status |
|---|---|---|
| Sanctum Ability Authorization (missing→403, specific, wildcard) | `checkPermission()` throws `AuthorizationException` (renders 403) as first stmt after `_model` guard; wildcard bypass via `tokenCan('*')`. Tests 1/2/3 pass | ✅ COMPLIANT (tested) |
| Model Resolution via Explicit Map | `REPORT_MODEL_MAP` (15 entries) + early return; legacy string-concat `Modules\{M}\Models\{M}` + `User`→`App\Models\User` + Collaborator switch preserved; dead Audit legacy case removed | ✅ COMPLIANT (mapped path tested via Charge; legacy path by inspection + deferred manual 4.16) |
| Whitelisted Body-Filter Application | `applyRequestFilters()`: allow = `getFillable()`+id/created_at/updated_at; dotted skipped; operator allowlist incl. `not like`; `in`/`not in`→whereIn/NotIn. Filter-narrows tested (test 5) | ✅ COMPLIANT |
| Audit Cross-Tenant Isolation Guard | `applyAuditTenantGuard()`: initialized→`where(qualifyColumn('tenant_id'),tenant('id'))`; else wildcard→unscoped; else `whereRaw('1=0')`. Mirrors `AuditController::buildFetchQuery`. Tenant-scoped path tested (test 4) | ✅ COMPLIANT (fail-closed zero-rows + cross-tenant-wildcard paths verified by inspection, NOT auto-tested — see SUGGESTION) |
| Eager Loading from Map | `->with($config['with'])`; every relation used by each model's closures is covered by its map row (all 15 cross-checked) — no N+1 | ✅ COMPLIANT (inspection) |
| Deterministic Ordering | `->orderBy('created_at','desc')` applied to all mapped models incl. Audit | ✅ COMPLIANT (inspection) |

**Audit deviation scrutiny (as requested):** `mappedModelReport()` builds Audit via `$model->newQuery()->with(...)` instead of `ApiHandler::parseMultiple` because `Modules\Audit\Models\Audit` has no `SoftDeletes` and the shared Parser calls `withTrashed()` unconditionally (documented in-code). VERIFIED SAFE: `applyRequestFilters()` is still called for Audit (body filters **do** apply — no gap), `orderBy('created_at','desc')` still applies (ordering holds), and the tenant guard runs. Well-reasoned, no leak.

### list-excel-export

| Requirement | Evidence | Status |
|---|---|---|
| Permission-Gated Export Button | `PermissionGuard permission="report.<key>"` on every page (13 standard + CatalogCrudModule Vehicle + ProjectContribution) | ✅ COMPLIANT (inspection) |
| Export Honors Active List Filters | Standard pages call `onReport(...)` (serializes `buildParams()`); `exportXlsx` reuses the hook's `filters`/`search` | ✅ COMPLIANT |
| Spanish Column Headers | `reportColumns()` keys are Spanish across all 15 models | ✅ COMPLIANT (all 15 read) |
| Value Formatting Rules | money `(float) formatByDecimal()` (never /100), dates `d/m/Y`, datetimes `d/m/Y H:i`, booleans `Sí`/`No`, relations `?->name` | ✅ COMPLIANT (all 15 read; Visitor omits `qr_token`) |
| Empty Result Feedback | `useBaseList.onReport` `blob.size<=0 → toast.info('No hay datos para exportar')` (useBaseList.ts:227-228); `exportXlsx` same | ✅ COMPLIANT |
| Frontend Wiring Deviations (Vehicle / ProjectContribution / AccessLog) | Vehicle via `reportModel`/`reportTitle` props on `CatalogCrudModule`; `useContributionsList.exportXlsx` (distinct name, empty-blob toast); AccessLog `downloadCsv()` helper + button **fully deleted** and replaced by server-side `onReport('AccessLog','xlsx','bitacora-accesos')` | ✅ COMPLIANT |
| Collaborator Export Regression | No Collaborator wiring touched (backend switch case byte-preserved); behavior unchanged by inspection | ⏳ PENDING manual QA (task 4.16) |

## reportColumns audit (spot-check ≥5; did all 15)

All 15 verified: Spanish labels; money via `formatByDecimal()` with `0` fallback (never `/100`); `d/m/Y` dates, `d/m/Y H:i` datetimes; booleans `Sí`/`No`; relations null-safe `?->`; full names via `trim(name last_name second_last_name)`. Eager-load coverage complete per model (no lazy-load N+1). Plain-column assumptions verified against source: Expense `concept`/`beneficiary_text` are fillable columns (no relation shadowing); User + Collaborator both expose `name`/`last_name`/`second_last_name`; `Audit::user()` relation exists (core Audit:74). Visitor correctly does NOT export `qr_token`.

Minor: CommunityProject's map eager-loads `amount_mode` which no column consumes (harmless one-relation over-fetch; matches design map). `ChargeBatch.progress_pct` / `CommunityProject`-style plain attributes render blank if absent — not a crash.

## Design coherence

Implementation matches the design's decisions: 403 via `AuthorizationException` (not the plan's 401); permission check for ALL `_model`s; `_model`-missing guard returns 422; nested `unit.unitType`+`unit.tower` eager loads; filter whitelist = fillable+timestamps with per-filter skip; Audit guard = verbatim mirror. The one in-code deviation (Audit bypasses `parseMultiple`) is documented and safe. The **frontend `isExporting`/`Loader2` rework now in the tree is a divergence from the design** (design specifies the store `isLoading` + plain `Download`; lists neither `useBaseList.ts` nor a spinner).

## Issues

### CRITICAL (archive-blocking)
1. **Frontend `pnpm typecheck` is currently failing / non-deterministic.** At verification time it flaps between exit 0 and TS6133 unused-symbol errors (`useBaseList.ts` `isExporting`; `ExpenseListPage`/`ProviderListPage`/`CommunityProjectListPage` `Loader2`). Archive requires a stable green typecheck. **Root cause is NOT the delivered export logic** (backend is green; the apply-progress frontend snapshot was clean) — it is a concurrent session live-reworking the export UI in this shared checkout. Do NOT archive until concurrent work is quiesced and `pnpm typecheck` is green on a stable tree.

### WARNING
2. **Out-of-contract frontend rework in the working tree.** A concurrent variant adds `isExporting` to the shared `useBaseList.ts` hook and per-page `Loader2` spinners — not in the verified design/tasks and touching a hook used far beyond this change. If it is meant to ship with export-excel-lists, the design/tasks must be updated and it needs its own review (shared-hook blast radius); if it belongs to another work item, it must be separated before committing this change.
3. **Task 4.16 (Collaborator export regression) not executed** — needs the live API. Legacy resolution is preserved by inspection, but the regression is unproven at runtime.

### SUGGESTION
4. **Audit fail-closed path has no automated test.** The tenant-scoped path is covered (test 4), but "no tenancy + no wildcard → zero rows" and "no tenancy + wildcard → unscoped" are inspection-only. A 6th case asserting the `whereRaw('1=0')` fail-closed branch would lock the cross-tenant-leak guard.
5. **No automated coverage for the frontend spec scenarios** (button gating, Spanish headers, formatting, empty-toast, the 3 deviations). These are inspection-verified; a manual click-through is the current safety net.

## Pending manual-QA checklist (for the user)

1. **Quiesce concurrent sessions** editing `frontend/`, then re-run `pnpm typecheck` until it is green on a stable tree. (Blocking for archive.)
2. **Decide the fate of the `isExporting`/`Loader2` rework**: fold into this change (update design+tasks, review shared-hook impact) or split it out.
3. **Collaborator export regression** (task 4.16): export the Collaborator list with and without active filters via the live app; confirm the 2-sheet xlsx downloads with correct, filtered data.
4. **Click-through the 15 export buttons** (auth as a role holding each `report.<key>`): confirm Spanish headers, filter-respecting rows, money as decimals, `Sí`/`No` booleans, relation labels (not UUIDs), and the empty-filter → toast path (e.g. Visitor).
5. **Audit export cross-tenant check** in a super-admin/no-tenancy context: confirm a non-wildcard token yields zero rows (fail-closed) and a wildcard token sees all tenants.
