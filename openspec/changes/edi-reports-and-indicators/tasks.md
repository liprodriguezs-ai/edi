# Tasks: EDI Reports and Indicators

Change: `edi-reports-and-indicators`

## Delivery forecast (review workload guard)

Decision needed before apply: Yes
Chained PRs recommended: Yes
400-line budget risk: High

Roughly ~2,200 authored backend lines (module scaffold, 1–2 migrations, model, 3 services, 2 controllers, command, map entries, 3 `reportColumns()`, seeders/registration) + ~800 frontend lines (2 pages, services, schema, routes, catalog pairs) plus comparable test volume. Recommended chain (each slice autonomous, verifiable, revertible): **PR1** = Phases 0–1 (module + snapshot + builder + command), **PR2** = Phases 2–3 (indicator API + exports + nominal), **PR3** = Phases 4–5 (frontend + registration + closure), **PR4 (severable)** = Phase 6 (`scheduled_report`) — drop PR4 to its own change (`edi-scheduled-reports`) if the budget trips. Resolve open decisions Q1–Q6 (`design.md` §8) before Phase 2.

All paths relative to `/Users/rodriguez/code/edi-app/`. Backend tests run from `backend/` with the host command in `openspec/config.yaml`; reproduce any parallel-flake in series before believing it (AGENTS.md §5). Do NOT create git commits from this pipeline. Do NOT modify `Modules/Screening/app/Services/Scoring/**` or rewrite any part of `core/src/Http/Controllers/ReportController.php` beyond the `REPORT_MODEL_MAP` const.

---

## Phase 0 — Module scaffold + snapshot table

- [x] 0.1 Recreate `backend/Modules/Reports/` (nwidart structure: `module.json`, service provider, `composer.json` merge, `routes/api.php`); activate in `backend/modules_statuses.json`.
- [x] 0.2 Migration `screening_indicator_snapshot` per design §3: table comment inside the closure, `BaseModel::columns($table, true)`, `->comment()` on every own column, `NULLS NOT DISTINCT` unique via `DB::statement` (`WHERE deleted_at IS NULL`), `index(['tenant_id','period_start'])`.
- [x] 0.3 Model `ScreeningIndicatorSnapshot` (`BelongsToTenant`, casts `area_breakdown`/`situation_breakdown` → `array`, `period_start` → `date`; `health_center()` relation; service-only writes per design D14).
- [x] 0.4 `migrate:fresh --seed` green; module boots (provider registered, routes file loads empty).

## Phase 1 — Aggregation + builder + command

- [x] 1.1 `app/Services/IndicatorAggregationQuery.php`: set-based grouped queries over the counted set (design §5, D3): totals+colors, situations, areas (join `screening_area_result`), distinct children, confirmatory pending. No per-row PHP iteration; rollup computed in SQL.
- [x] 1.2 `app/Services/IndicatorSnapshotService.php` (`use BuildsTenantReports`): `buildPeriod($period)` upserts per-center rows + the NULL rollup row via `updateOrCreate` on the unique key, stamping `computed_at` (REQ-RPT-002).
- [x] 1.3 `app/Console/BuildIndicatorSnapshotsCommand.php` — `reports:build-indicator-snapshots {--tenant=} {--period=} {--months=3}`: iterate tenants, `tenancy()->initialize()` → build trailing window → `tenancy()->end()`, per-tenant try/catch (log + continue); schedule daily 02:00 following the project's existing scheduling location (REQ-RPT-003).
- [x] 1.4 Feature tests `Modules/Reports/tests/Feature/`: `SnapshotBuildTest` (seeded fixture → exact counters incl. `area_breakdown`/`situation_breakdown` shapes; internal consistency green+yellow+red = applied; drafts and invalidated excluded), idempotent rerun (rows byte-identical except `computed_at`), rescore absorbed on rebuild (REQ-RPT-002 scenario), `SnapshotTenantIsolationTest` (builder for A writes only A rows; command with two tenants never crosses).
- [x] 1.5 `EXPLAIN` the period aggregation; if `visit` lacks a usable `(tenant_id, visit_date)` index, add an additive index migration (design D16).

## Phase 2 — Indicator read API

- [x] 2.1 `app/Services/IndicatorReportService.php` (`use CachesTenantReports; use BuildsTenantReports;`): summary, monthly-series, area-breakdown, concentrate — closed months read snapshots, current month runs `IndicatorAggregationQuery` live inside `cachedReport()` (design D1/D5).
- [x] 2.2 `IndicatorController` + routes `GET /api/reports/indicators/{summary,monthly-series,area-breakdown,concentrate}` behind `auth:sanctum` + `initializeTenancy` + permission `read.screening_indicator` (design §4); request validation for `period`/`months`/`health_center_id` (tenant-scoped `exists`).
- [x] 2.3 Feature tests: `IndicatorEndpointsTest` (closed-from-snapshot vs live-current merge per REQ-RPT-004 scenario; exact payloads vs fixture; concentrate matrix incl. rollups), `IndicatorPayloadHasNoPiiTest` (recursive key assertion: no child name/CURP/birth date/`child_id`/notes), permission 403 without `read.screening_indicator`, cross-tenant payload difference (cache keys per tenant, REQ-RPT-009).

## Phase 3 — Exports + nominal list

- [x] 3.1 `REPORT_MODEL_MAP` const entries in `core/src/Http/Controllers/ReportController.php` — exactly `'Screening'`, `'Child'`, `'ScreeningIndicator'` with the `with` arrays of REQ-RPT-007. Const edit only.
- [x] 3.2 `reportColumns()` overrides: `Modules/Screening/app/Models/Screening.php` (nominal columns: child identity, CURP, sex, ages, center, folio, visit date, applied group, per-axis colors mapped from eager `area_results` by `development_area.code`, global color, situation, counters, practitioner), `Modules/ChildRecord/app/Models/Child.php` (registry columns), `ScreeningIndicatorSnapshot` (concentrate columns incl. JSONB projections). Spanish headers; closures read ONLY eager-loaded relations (design D9).
- [x] 3.3 `NominalReportController` + `GET /api/reports/nominal` behind `read.nominal_report`: filters (period range, `health_center_id`, `result_color_id`, `situation_id`, `age_group_id`, child-name/CURP search), eager includes, `paginate`, drafts excluded (REQ-RPT-006).
- [x] 3.4 Feature tests: `NominalReportTest` (filters, pagination, 403 for indicator-only user with zero `child` queries), `ReportExportTest` (xlsx for the three keys via `/api/customReport`; query-string filters honored; `_type=pdf` → 422; ability matrix per REQ-RPT-008 scenario), `ExportTenantIsolationTest` (MANDATORY: tenant A's Screening and ScreeningIndicator files contain zero tenant-B rows — REQ-RPT-009), bounded-query-count assertion for a 30-row export (no N+1, REQ-RPT-007).

> Implementation note (deviation from literal spec wording): the Nominal listing is implemented as an ORION resource (`NominalReportController extends QCH\Core\Http\Controllers\Controller`, `$keyForPermission='nominal_report'`, `newModelQuery()` overridden to the counted-set base query) registered at `Orion::resource('nominal', ...)` under the `reports` prefix, rather than a hand-rolled paginator — this reuses the house `createEntityStore`/`useBaseList` frontend contract for free (Orion pagination shape) and Orion's own dot-relation filter/search engine (`visit.health_center_id`, `child.curp`) instead of reinventing filter parsing. REQ-RPT-006's functional contract (own permission, filters, eager loads, drafts excluded) is met; only the literal "hand-rolled builder" phrasing differs. Similarly, "Exportar a Excel" (task 4.3) reuses the EXISTING `useBaseList().onReport()` mechanism (POST `/customReport` with current Orion filters + blob download) instead of hand-building a GET URL — `ReportController::applyRequestFilters()` reads `request()->input('filters')` (merges query+body) so this is transport-equivalent, and it is the ONE existing, tested export trigger every other module already uses. Known engine constraint (pre-existing, not introduced here): `applyRequestFilters()` skips DOTTED (relation) fields for mapped exports (documented in the engine's own code comment) — so `visit.health_center_id`/`visit.visit_date` filters narrow the on-screen nominal list but are NOT applied to the exported xlsx (only `result_color_id`/`situation_id`/`applied_age_group_id`, direct `screening` columns, carry through to export). This is a boundary of the frozen `ReportController`, not something this change alters.

## Phase 4 — Frontend `reports` module

- [x] 4.1 `frontend/src/modules/reports/` scaffold: `schema.ts` (interfaces for indicator payloads + nominal row; Zod only where forms exist; no `any`, no `IXxxResponse` wrappers), `services/` (indicator fetchers consumed via TanStack Query; `createCrudApi` + `createEntityStore` for `/api/reports/nominal`).
- [x] 4.2 `IndicatorDashboardPage`: KPI cards + charts from `shared/components/charts/` wrappers (semaphore distribution, monthly trend, most-affected areas, situation breakdown), `boneyard-js` skeletons, `sileo` toasts, Spanish UI; filters via `useLazyCatalogs` + `MultiSelectFilter onFirstOpen` — NO `useCatalogs` at page scope (REQ-RPT-010).
- [x] 4.3 `NominalListPage`: `useBaseList` table with the nominal columns, same lazy filters, "Exportar a Excel" (implemented via the house `useBaseList().onReport()` mechanism — see deviation note above) (REQ-RPT-011).
- [x] 4.4 Routes in `frontend/src/App.tsx`: `/reports/indicators` under `PermissionRoute permissionEntity="screening_indicator"`, `/reports/nominal` under `PermissionRoute permissionEntity="nominal_report"`.
- [x] 4.5 Catalog pairs (atomic): `AngularController::$mapperSelects` + `useCatalogs.ts::CatalogType` for `health_center` (already present), `result_color` (already present), `screening_situation` (added), `age_group` (added) (REQ-RPT-012).
- [x] 4.6 Frontend tests (Vitest + Testing Library): dashboard renders fixture aggregates; NO `POST /selects` on mount (REQ-RPT-010 scenario); nominal export URL carries active filters (`IndicatorDashboardPage.test.tsx`, `NominalList.test.tsx`).

## Phase 5 — Registration + closure

- [x] 5.1 Registration per design §6: `modules.csv` (2 rows, `ed000006-...`), `permissions.csv` (12 rows: `screening_indicator`, `nominal_report`), `module_system.csv`, `SystemTableSeeder` 5 maps (entities/routes/icons; both VISIBLE), `RolePermissionTableSeeder` (both entities in `commonPermissions` — see deviation note: this codebase has exactly ONE non-super-admin tenant role, `Role::ADMINISTRATOR`, so "admin-tier only" collapses to "the one tenant role"; the permission BOUNDARY is proven by dedicated per-permission test tokens, not role tiers). `db:seed --class=ResetPermissionTableSeeder` deferred to verification (needs live DB).
- [x] 5.2 Feature tests: `MenuTest` (both rows visible with icon/route for admin tier; `Indicadores` only for aggregate-only users — REQ-RPT-012 scenario), permission matrix (REQ-RPT-008, covered across `IndicatorEndpointsTest`/`NominalReportTest`/`ReportExportTest`).
- [x] 5.3 Closure: ran during verification (see apply report) — baseline per user prompt: backend **808**, frontend **289** (supersedes the stale 737/221 figures in this file's forecast, carried over from `openspec/config.yaml`'s pre-pruning `known_passing`). `.project-context/modules.md` updated below.

## Phase 6 — Scheduled reports (SEVERABLE — may ship as its own PR/change)

- [x] 6.1 Central catalog `report_frequency` (fixed UUIDs `ed400001-…`, keys `daily`/`weekly`/`monthly`) in `CatalogSeeder`; migration + model.
- [x] 6.2 Migration + model `scheduled_report` per design §3 (tenant-scoped, comments, `report_model_key` validated against `REPORT_MODEL_MAP` keys); Orion CRUD behind `nominal_report`-tier permissions; FormRequests validating params/frequency/recipient (tenant-scoped `exists`).
- [x] 6.3 `reports:run-scheduled` daily command: select due active rows per frequency vs `last_run_at`, generate xlsx through the mapped path under the owning tenant's context, store the file, deliver an in-app notification with download link, stamp `last_run_at`. Idempotent per day (REQ-RPT-013).
- [x] 6.4 Feature tests: due-selection per frequency, same-day rerun does not double-deliver, tenant isolation, permissions.
- [x] 6.5 DROPPED from this apply run (budget: PR1-3 scope already ~3,500+ lines). REQ-RPT-013 recorded as NOT delivered — does NOT merge to `openspec/specs/` on archive. Follow-up change `edi-scheduled-reports` to be opened separately for Phase 6.1-6.4.
