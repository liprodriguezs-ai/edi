# Design: EDI Reports and Indicators

Change: `edi-reports-and-indicators`

## 1. Context

The qch/core report engine (`core/src/Http/Controllers/ReportController.php`, routes `GET|POST /api/customReport`) is complete and idle: case-insensitive whitelist lookup, `mappedModelReport` with a fail-closed tenant guard, query-string filters via `ApiHandler`, sort whitelisting with `id` tie-break, hybrid sync/streaming export (`sync_row_threshold` 5000, `lazy(500)`), old-file cleanup — and an EMPTY `REPORT_MODEL_MAP` waiting for this change. `core/src/Services/` already ships `CachesTenantReports` (tenant-scoped cache keys, `config('dashboard.reports.*')`) and `BuildsTenantReports` (`safePercent`). The Screening module persists the full clinical truth (header result, `screening_area_result`, situation with `sis_code`, score runs); `Visit` carries `visit_date`/`receipt_number`/`health_center_id`; `HealthCenter` carries `clues_code`. The legacy `IndicadorController`/`Censos/TraitCenso` sources are NOT in this repo — the business requirement (dashboard, concentrate, nominal list) is taken from the change brief; where the legacy computed nutritional status, this design defers (D13).

Nothing here touches `Modules/Screening/app/Services/Scoring/**` or rewrites any engine machinery.

## 2. Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | **Materialize closed months; compute only the current month live.** Snapshots are the ONLY source for closed months in dashboard/concentrate; the current month is aggregated on request through the same queries, wrapped in `CachesTenantReports` (TTL default 300s) | A dashboard recomputing over all `screening` rows per load does not scale; materializing everything serves stale current-month numbers. Closed months mutate only via rescore/invalidate/late capture — absorbed by the nightly trailing-window rebuild (D6). Worst case per request: N snapshot rows + one cached bounded aggregation |
| D2 | Snapshot grain: `(tenant_id, health_center_id NULLABLE, period_start date)`; NULL center = organization rollup, stored as its own row. Unique index `NULLS NOT DISTINCT` via `DB::statement` (PostgreSQL 16) | The concentrate needs both grains; computing rollups by summing center rows would silently miss screenings whose visit lacks a center. A sentinel UUID for "all centers" would be a magic value; `NULLS NOT DISTINCT` keeps honesty and uniqueness |
| D3 | Counting basis: period bucket = `visit.visit_date` calendar month; counted set = non-invalidated screenings with `result_color_id` NOT NULL. `screenings_completed` is a sub-count; drafts and invalidated rows count nowhere | `visit_date` is the clinical date (matches legacy censos); `created_at` would misfile late captures. A result-less draft has no semaphore to aggregate. Encoded as default — flagged in proposal question round Q2 |
| D4 | Fixed counters as integer COLUMNS; per-area and per-situation distributions as JSONB (`area_breakdown`, `situation_breakdown`) with documented shapes | Colors are a closed 3-set → sargable hot columns. Areas are versioned catalog content (6 semaphore areas today) and situations a 7-set: 18+ rigid columns would couple the table to catalog contents; JSONB keyed by stable `key`/`code` strings stays honest across instrument versions |
| D5 | ONE shared `IndicatorAggregationQuery` class produces every aggregate (builder AND live current-month path consume it) | Two implementations of "the month's numbers" WILL diverge. Single source of truth makes snapshot-vs-live equality a testable invariant |
| D6 | Command `reports:build-indicator-snapshots {--tenant=} {--period=YYYY-MM} {--months=3}`: per tenant `tenancy()->initialize()` → upsert trailing window → `tenancy()->end()`; per-tenant try/catch (log + continue); scheduled daily at 02:00 | Trailing 3 months absorbs rescores/late captures/invalidations without full-history sweeps; `--period` backfills arbitrary months (initial rollout: one documented backfill run). Isolation per tenant is structural, not incidental |
| D7 | Permission model: new entities `screening_indicator` (aggregates) and `nominal_report` (PII listing). Concentrate export map key is `ScreeningIndicator` so the engine-derived ability is `report.screening_indicator` (snake of the KEY, not the class). Nominal export ability remains `report.screening` (engine-fixed from `_model=Screening`), granted to the same admin tier as `nominal_report` | The brief mandates: nominal behind its OWN permission; aggregates must not require identifiable-data access. The map key trick keeps the 6-row permission convention intact without touching engine code |
| D8 | Nominal data via dedicated `GET /api/reports/nominal` (Reports-owned projection: builder with eager includes + filters + `paginate`), NOT the `/api/screening` Orion index | Decouples the reports surface from Screening CRUD permissions (`read.screening` belongs to clinical operators; `read.nominal_report` to admin/reporting roles), and matches the legacy shape (the censo was its own query). The screening UI change keeps owning `/api/screening` |
| D9 | `REPORT_MODEL_MAP` gains exactly 3 entries (spec REQ-RPT-007); `reportColumns()` overrides live on each model in its owning module (Screening, ChildRecord, Reports). Column closures read ONLY eager-loaded relations; per-axis nominal columns map `area_results` by `development_area.code` in-memory | The const edit is the engine's designed extension point. Cross-module `reportColumns()` additions are additive and inert until the map entry exists (clean rollback). The eager-only rule is the N+1 firewall — enforced by a query-count test |
| D10 | Concentrate xlsx = export of the snapshot MODEL through the engine (xlsx-only per mapped contract; pdf stays 422) | Zero new export machinery; the engine's filters/sort/streaming/cleanup apply for free. The dashboard endpoint serves the interactive matrix; the engine serves the file |
| D11 | Frontend: dashboard uses TanStack Query directly (no entity store — aggregates are server state, not entities) + existing `shared/components/charts/` wrappers; nominal uses the standard `createEntityStore`/`useBaseList` scaffolding over `/api/reports/nominal`. All filter catalogs via `useLazyCatalogs` + `onFirstOpen` | House patterns; an entity store for KPI payloads would fake an entity that doesn't exist. Eager catalog loading is a named anti-pattern (infra cost) |
| D12 | Menu: 2 rows — `Indicadores` → `/reports/indicators` (entity `screening_indicator`), `Listado nominal` → `/reports/nominal` (entity `nominal_report`), both VISIBLE | The permission split IS the menu split: clinical staff can see indicators without ever seeing a PII surface in their menu. Unlike the hidden screening rows (no UI existed), this change ships its UI |
| D13 | Nutritional status: DEFERRED. Somatometry (`weight_grams`, `height_mm`) is already captured per screening; classification needs WHO weight/height reference tables that don't exist in `Instrument` (only `head_circumference_reference` does) and their sourced data | Shipping a classification without the official reference tables would be invented medicine. The data model is ready; a follow-up change (`edi-anthropometry-references`) adds versioned tables + the dashboard panel |
| D14 | Snapshot writes are service-only: `$fillable` empty-ish (builder uses `updateOrCreate` with explicit arrays), no write endpoints for the snapshot beyond the builder; Orion exposure read-only if any | Snapshots are derived truth. A writable snapshot would be a forgeable indicator |
| D15 | `scheduled_report` (SEVERABLE Phase 6): tenant-scoped table + central `report_frequency` catalog (`ed400001-…` fixed UUIDs, keys `daily`/`weekly`/`monthly`) + daily `reports:run-scheduled` generating through the mapped path under the owning tenant and delivering an IN-APP notification with download link. No email in this slice | Frequency is a behavior selector the code branches on → central catalog (hard rule 4). In-app delivery avoids dragging the React Email pipeline into this change; email is a later increment. Recommended: drop to `edi-scheduled-reports` if the budget trips |
| D16 | Index review at apply: `EXPLAIN` the period aggregation (screening ⋈ visit on `visit_id`, filtered by `visit.visit_date` range). If `visit` lacks a `(tenant_id, visit_date)` index, add one additive migration | The builder runs nightly per tenant and live queries cover one month — bounded, but a seq scan over `visit` grows with history. Verify, don't assume |

## 3. Data model (definitive)

**`screening_indicator_snapshot`** — `$table->comment('Materialized screening indicator aggregates per tenant, health center (NULL = whole organization), and calendar month');` then `BaseModel::columns($table, true)`.

| Column | Type | Constraints | Comment |
|---|---|---|---|
| `health_center_id` | uuid, index | nullable | FK to health_center; NULL = organization-wide rollup row |
| `period_start` | date | NOT NULL | First day of the aggregated calendar month |
| `screenings_applied` | integer | NOT NULL default 0 | Counted set size: non-invalidated screenings with a result in the period |
| `screenings_completed` | integer | NOT NULL default 0 | Sub-count with status completed |
| `children_screened` | integer | NOT NULL default 0 | Distinct children in the counted set |
| `result_green_count` | integer | NOT NULL default 0 | Global semaphore green count |
| `result_yellow_count` | integer | NOT NULL default 0 | Global semaphore yellow count |
| `result_red_count` | integer | NOT NULL default 0 | Global semaphore red count |
| `first_time_count` | integer | NOT NULL default 0 | Screenings whose situation key is first_time_* |
| `alert_sign_screening_count` | integer | NOT NULL default 0 | Screenings with alert_sign_count > 0 |
| `risk_factor_screening_count` | integer | NOT NULL default 0 | Screenings with risk_factor_count > 0 |
| `confirmatory_pending_count` | integer | NOT NULL default 0 | Red completed screenings without a confirmatory test result |
| `area_breakdown` | jsonb | NOT NULL default '{}' | Per development-area color counts: {area_code: {green, yellow, red}} |
| `situation_breakdown` | jsonb | NOT NULL default '{}' | Per situation-key counts: {situation_key: count} |
| `computed_at` | timestamp | NOT NULL | Moment this row was (re)computed by the builder |

Indexes: `DB::statement('CREATE UNIQUE INDEX ... ON screening_indicator_snapshot (tenant_id, health_center_id, period_start) NULLS NOT DISTINCT WHERE deleted_at IS NULL')`; `$table->index(['tenant_id', 'period_start'])`.

**`scheduled_report`** (Phase 6, severable) — tenant-scoped: `name` string NOT NULL (Spanish label) · `report_model_key` string NOT NULL (REPORT_MODEL_MAP key) · `params` jsonb nullable (saved filter query params) · `report_frequency_id` uuid NOT NULL (FK to central report_frequency) · `recipient_user_id` uuid NOT NULL (FK to user receiving the notification) · `is_active` boolean NOT NULL default true · `last_run_at` timestamp nullable. Central catalog **`report_frequency`**: `BaseModel::columns($table, false)`, fixed UUIDs `ed400001-0000-4000-8000-00000000000{1,2,3}`, keys `daily`/`weekly`/`monthly`, seeded by `CatalogSeeder`.

## 4. API surface

| Route | Permission | Source |
|---|---|---|
| `GET /api/reports/indicators/summary?period&health_center_id` | `read.screening_indicator` | Snapshot (closed) / live+cache (current) |
| `GET /api/reports/indicators/monthly-series?months&health_center_id` | `read.screening_indicator` | Snapshots + live current month |
| `GET /api/reports/indicators/area-breakdown?period&health_center_id` | `read.screening_indicator` | Same cut |
| `GET /api/reports/indicators/concentrate?from&to&health_center_id` | `read.screening_indicator` | Snapshots + live current month |
| `GET /api/reports/nominal?filters…&page` | `read.nominal_report` | Live eager-loaded projection (never materialized: PII + arbitrary filters) |
| `GET\|POST /api/customReport` (`_model=Screening\|Child\|ScreeningIndicator`) | `report.screening` / `report.child` / `report.screening_indicator` | Existing engine, untouched |

Controller: `Modules/Reports/app/Http/Controllers/` — indicator + nominal controllers extending the project base controller; services `IndicatorReportService` (uses `CachesTenantReports` + `BuildsTenantReports`), `IndicatorSnapshotService`, shared `IndicatorAggregationQuery`. Routes in `Modules/Reports/routes/api.php` inside the standard `auth:sanctum` + `initializeTenancy` + permission middleware group.

## 5. Aggregation queries

All set-based on the counted set (D3): one grouped query per dimension — totals+colors (`GROUP BY health_center_id, result_color_id`), situations (`GROUP BY health_center_id, situation_id`), areas (join `screening_area_result`, `GROUP BY health_center_id, development_area_id, result_color_id`), distinct children (`COUNT(DISTINCT child_id)`), confirmatory pending (left join `confirmatory_test`). Center grain and rollup come from the same result sets (rollup aggregates in SQL, not PHP loops over rows). `TenantScope` applies automatically under initialized tenancy; the builder never queries without it.

## 6. Registration (AGENTS.md rule 9)

`modules.csv`: `ed000004-0000-4000-8000-000000000001,Indicadores,Reportes` and `…0002,Listado nominal,Reportes` (group per existing CSV conventions — verify group column values at apply). `permissions.csv`: 12 rows (read/create/update/delete/restore/report × `screening_indicator`, `nominal_report`). `module_system.csv`: both under the business system used by clinical modules. `SystemTableSeeder`: `seedModulePermissionEntities` (`Indicadores => screening_indicator`, `ListadoNominal => nominal_report`), `seedModuleRoutes` (`/reports/indicators`, `/reports/nominal`), `seedModuleIcons` (from `iconMap.ts`), NOT in `$hidden`. `RolePermissionTableSeeder`: aggregate reads broad; `read.nominal_report` + `report.screening` + `report.child` + `report.screening_indicator` admin-tier only (REQ-RPT-008). `AngularController::$mapperSelects` + `useCatalogs.ts::CatalogType`: add the coupled pairs for `health_center` (if absent), `result_color`, `screening_situation`, `age_group`. Then `php artisan db:seed --class=ResetPermissionTableSeeder`.

## 7. SIS/FUA readiness (out of scope, documented)

Ready today: `screening_situation.sis_code` (1–6, persisted per screening), `visit.receipt_number`/`physical_receipt_number` (folio), `health_center.clues_code` (CLUES), `child.curp`, corrected/chronological ages in days, per-axis colors. Present but unwired: the `population_type` central catalog exists in ChildRecord with no FK from `child`/`visit` — the SIS export change must wire it. Missing entirely: the CURRENT official SIS/FUA layout specification (file format, field order, catalog code mappings) — blocked on obtaining it; when it lands, the export should ride the same engine as a new mapped key or a dedicated formatter, NOT a parallel export stack.

## 8. Open decisions (defaults encoded — correct before `sdd-apply`)

| Q | Default taken | Where |
|---|---|---|
| Q1 | Nutritional status deferred to `edi-anthropometry-references` | D13, proposal round 1 |
| Q2 | `visit_date` bucketing; counted set = non-invalidated with result | D3, REQ-RPT-002 |
| Q3 | Trailing window 3 months, daily 02:00; current month TTL 300s | D1/D6 |
| Q4 | Nominal via `/api/reports/nominal` + `nominal_report` entity; export ability stays `report.screening` | D7/D8 |
| Q5 | `scheduled_report` severable, in-app delivery, no email | D15 |
| Q6 | Both menu rows VISIBLE at ship (dashboard has no dependency on `edi-screening-ui`) | D12 |
