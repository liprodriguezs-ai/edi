# Spec: EDI Reports and Indicators

Change: `edi-reports-and-indicators`
Capabilities: `reports-and-indicators` (new)

> RFC 2119 keywords (MUST, MUST NOT, SHALL, SHOULD, MAY) are normative.
> "Engine" here = the generic report engine `QCH\Core\Http\Controllers\ReportController` (`customReport` → `mappedModelReport`), reached via `GET|POST /api/customReport`. It MUST NOT be rewritten; this change only fills `REPORT_MODEL_MAP` and model `reportColumns()`.
> "Counted set" of a period = non-invalidated screenings whose `visit.visit_date` falls in that calendar month AND whose `result_color_id` is not NULL (statuses `scored` or `completed`).
> "Closed month" = any calendar month strictly before the current server date's month.
> Aggregate payloads MUST NOT contain PII of minors: no child names, CURP, birth dates, `child_id`, or free-text notes.
> Error envelopes: `{ "message": string (Spanish UI), "code": UPPER_SNAKE constant, ...context }`.
> On archive, REQ-RPT-* merge into `openspec/specs/reports-and-indicators/spec.md`.

---

## ADDED Requirements — capability `reports-and-indicators`

### REQ-RPT-001 — Reports module and snapshot persistence

The system SHALL recreate `backend/Modules/Reports` (nwidart structure, activated in `modules_statuses.json`) owning one tenant-scoped table `screening_indicator_snapshot` (`BaseModel::columns($table, true)` + `BelongsToTenant`): one row per `(tenant_id, health_center_id, period_start)` where `health_center_id` NULL means the whole-organization rollup. Uniqueness MUST treat NULL as a value (PostgreSQL `NULLS NOT DISTINCT` unique index via `DB::statement`). Every own column MUST carry `->comment()`; the table comments itself inside the closure. Exact columns per `design.md` §3. No other module's table is altered (index-only additive migrations allowed per design D16).

#### Scenario: rollup and per-center rows coexist for one period
- WHEN the builder runs for a tenant with 2 health centers in period 2026-07
- THEN exactly 3 snapshot rows exist for that period (center A, center B, NULL rollup), and inserting a second NULL-rollup row for the same period fails on the unique index.

### REQ-RPT-002 — Aggregate definitions

Each snapshot row SHALL be computed from the counted set of its period and grain (one center, or all centers for the rollup): `screenings_applied` (count), `screenings_completed` (status `completed` sub-count), `children_screened` (distinct `child_id`), `result_green_count`/`result_yellow_count`/`result_red_count` (global color), `first_time_count` (situation key `first_time_*`), `alert_sign_screening_count` (`alert_sign_count > 0`), `risk_factor_screening_count` (`risk_factor_count > 0`), `confirmatory_pending_count` (red `completed` without a `confirmatory_test` result), `area_breakdown` JSONB (`{area_code: {green, yellow, red}}` from `screening_area_result` of the counted set), `situation_breakdown` JSONB (`{situation_key: count}`), and `computed_at`. Invalidated screenings and result-less drafts MUST NOT count anywhere. All aggregation MUST be set-based grouped queries (no per-row PHP iteration over screenings).

#### Scenario: rescore and invalidation change the numbers on rebuild
- GIVEN a July snapshot where one screening is yellow and another is later invalidated
- WHEN the first is authoritatively rescored to red and the builder reruns for July
- THEN `result_yellow_count` decreases by 1, `result_red_count` increases by 1, the invalidated screening is absent from every counter, and totals remain internally consistent (`green + yellow + red = screenings_applied`).

### REQ-RPT-003 — Idempotent per-tenant snapshot command

The system SHALL provide `reports:build-indicator-snapshots {--tenant=} {--period=YYYY-MM} {--months=N}` which, for each tenant (or the one given), initializes tenancy, upserts snapshots for the trailing `N` months (default 3, or exactly `--period`), and ends tenancy before the next tenant. The command MUST be idempotent — re-running with unchanged source data produces identical rows (same counters, updated `computed_at` only) and never duplicates. It SHALL be scheduled daily. A failure in one tenant MUST NOT abort the remaining tenants (log and continue).

#### Scenario: rerun converges
- WHEN the command runs twice in a row for the same tenant and window
- THEN the second run performs only updates (no new rows) and every counter is byte-identical to the first run's.

### REQ-RPT-004 — Indicator dashboard endpoints (aggregate, no PII)

The system SHALL expose, behind `auth:sanctum` + tenancy + permission `read.screening_indicator`: `GET /api/reports/indicators/summary?period=YYYY-MM&health_center_id=` (KPIs + color + situation breakdowns), `GET /api/reports/indicators/monthly-series?months=12&health_center_id=` (per-month totals and colors), and `GET /api/reports/indicators/area-breakdown?period=&health_center_id=`. Closed months MUST be served from snapshots; the current month MUST be computed live through the same shared aggregation queries the builder uses, wrapped in `CachesTenantReports` (tenant-scoped key, default TTL). Percentages use `BuildsTenantReports::safePercent`. Payloads MUST contain zero PII fields, and the endpoints MUST NOT require any Screening/ChildRecord permission.

#### Scenario: closed from snapshot, current live
- GIVEN July is closed with a stored snapshot and August is the current month
- WHEN `monthly-series` is requested
- THEN July's numbers equal the snapshot row (no screening-table aggregation for July) and August's numbers reflect a screening scored five minutes ago (within cache TTL staleness).

#### Scenario: aggregate payload is PII-free
- WHEN any indicator endpoint responds for a user holding ONLY `read.screening_indicator`
- THEN the JSON contains no child name, CURP, birth date, `child_id`, or notes at any depth.

### REQ-RPT-005 — EDI concentrate (period × center) and its export

`GET /api/reports/indicators/concentrate?from=YYYY-MM&to=YYYY-MM&health_center_id=` SHALL return the matrix rows (one per period × center, plus rollups) from snapshots + live current month, under `read.screening_indicator`. The xlsx export SHALL ride the engine as map key `ScreeningIndicator` → `ScreeningIndicatorSnapshot` (ability `report.screening_indicator`, derived by the engine from the map key). Mapped exports are xlsx-only: `_type=pdf` keeps returning 422 per engine contract.

#### Scenario: concentrate export honors the engine contract
- WHEN `GET /api/customReport?_model=ScreeningIndicator&_type=xlsx&_title=concentrado` runs with the permission
- THEN the file contains one row per snapshot row of the tenant with period, center, totals, color counts, and per-area columns; `_type=pdf` returns 422.

### REQ-RPT-006 — Nominal list endpoint (PII, own permission)

`GET /api/reports/nominal` SHALL return paginated child-by-child screening rows (child full name, CURP, birth date, sex, age at screening, center, receipt number, visit date, applied group, per-axis colors, global color, situation, alert/risk counters, practitioner) behind permission `read.nominal_report` — a DIFFERENT permission from `read.screening_indicator`, and independent from Screening CRUD permissions. Filters: period range, `health_center_id`, `result_color_id`, `situation_id`, `age_group_id`, and child-name/CURP search. Relations MUST be eager-loaded; the response is built from the counted set plus drafts EXCLUDED (result-less screenings never appear).

#### Scenario: indicator-only user cannot reach nominal data
- GIVEN a user holding `read.screening_indicator` but not `read.nominal_report`
- WHEN `GET /api/reports/nominal` runs
- THEN the response is 403 and no query against `child` executes.

### REQ-RPT-007 — REPORT_MODEL_MAP registration without N+1

`REPORT_MODEL_MAP` SHALL gain exactly three entries — `'Screening'` (class `Modules\Screening\Models\Screening`, with `['child.sex','visit.health_center','visit.practitioner','age_group','applied_age_group','result_color','situation','screening_status','area_results.development_area','area_results.result_color']`), `'Child'` (with `['sex','health_center']`), `'ScreeningIndicator'` (class `ScreeningIndicatorSnapshot`, with `['health_center']`) — and those three models SHALL override `reportColumns()` with Spanish column headers. Every column closure MUST read only already-eager-loaded relations; exporting N rows MUST execute a bounded query count that does not grow with N.

#### Scenario: nominal export is streaming-safe and bounded
- GIVEN 30 completed screenings
- WHEN `_model=Screening&_type=xlsx` exports
- THEN the file has 30 data rows with per-axis color columns filled, and the total SQL query count is independent of the row count (asserted via query log).

### REQ-RPT-008 — Permission separation (aggregate vs nominal vs export)

Two new permission entities SHALL exist under the Reports module: `screening_indicator` and `nominal_report` (6 rows each). Role placement: `read.screening_indicator` MAY go to clinical and admin roles; `read.nominal_report`, `report.screening`, `report.child`, and `report.screening_indicator` MUST be restricted to admin-tier roles in `RolePermissionTableSeeder`. Holding aggregate permission MUST NOT grant any nominal surface (endpoint or export), and vice versa.

#### Scenario: export abilities are enforced per model key
- GIVEN a user with `read.screening_indicator` and `report.screening_indicator` only
- WHEN they export `_model=ScreeningIndicator` and then `_model=Screening`
- THEN the first returns the xlsx and the second returns 403 (`report.screening` missing).

### REQ-RPT-009 — Every report surface inherits tenant isolation

With two tenants seeded (A and B, each with screenings): `_model=Screening|Child|ScreeningIndicator` exports as tenant A MUST contain zero tenant-B rows; the snapshot builder for A MUST write rows only with A's `tenant_id`; indicator/nominal endpoints as A MUST never include B data; cached indicator payloads MUST differ per tenant (tenant-scoped cache keys). The engine's fail-closed guard stays: a mapped export without initialized tenancy and without a wildcard token throws authorization.

#### Scenario: cross-tenant export leak is impossible
- GIVEN tenants A and B each with 5 completed screenings
- WHEN tenant A exports `_model=Screening&_type=xlsx`
- THEN the file has exactly A's 5 rows and no value originating from any B row, and the same holds for `ScreeningIndicator` after building both tenants' snapshots.

### REQ-RPT-010 — Frontend indicator dashboard

`frontend/src/modules/reports/` SHALL provide `IndicatorDashboardPage` at `/reports/indicators` behind `PermissionRoute permissionEntity="screening_indicator"`: KPI cards, semaphore distribution, monthly trend, most-affected-areas and situation charts using the existing typed wrappers in `shared/components/charts/`, with loading skeletons (`boneyard-js`) and Spanish UI. Filters (month, range, health center) MUST load catalogs lazily (`useLazyCatalogs` + `onFirstOpen`); mounting the page MUST NOT fire `POST /selects`. Data fetching via TanStack Query keyed by filters.

#### Scenario: no eager catalog load
- WHEN the dashboard mounts
- THEN zero `POST /selects` requests occur until the center filter dropdown first opens.

### REQ-RPT-011 — Frontend nominal list page

`NominalListPage` at `/reports/nominal` behind `PermissionRoute permissionEntity="nominal_report"` SHALL render the paginated nominal table (standard `createEntityStore`/`useBaseList` scaffolding over `/api/reports/nominal`), the same lazy filters, and an "Exportar a Excel" action calling `GET /api/customReport` with `_model=Screening`, `_type=xlsx`, and the CURRENT filter values as query params (the engine reads filters from the query string). Strong typing throughout (`schema.ts` interfaces, no `any`, no `IXxxResponse` wrappers).

#### Scenario: export carries the active filters
- GIVEN the list filtered to July + center A + red results
- WHEN "Exportar a Excel" is clicked
- THEN the customReport URL includes those exact filter params and the downloaded file matches the filtered rows.

### REQ-RPT-012 — Module registration checklist

Registration SHALL follow AGENTS.md rule 9 completely: 2 rows in `modules.csv` (`Indicadores`, `Listado nominal`), 12 permission rows in `permissions.csv` (entities `screening_indicator`, `nominal_report`), `module_system.csv` rows, the 5 `SystemTableSeeder` maps (entities, routes `/reports/indicators` and `/reports/nominal`, icons from `iconMap.ts`, both VISIBLE), `RolePermissionTableSeeder` placement per REQ-RPT-008, and the coupled `$mapperSelects`/`CatalogType` pairs for every catalog the filters consume (`health_center` if absent, `result_color`, `screening_situation`, `age_group`) — pairs added atomically.

#### Scenario: menu and routes light up with the permission
- WHEN an admin-tier user loads `GET /api/menu`
- THEN both module rows appear with icon and route; a user without `read.nominal_report` sees only `Indicadores` and receives 403 navigating to the nominal route's data.

### REQ-RPT-013 — Scheduled reports (SEVERABLE)

IF Phase 6 ships: a tenant-scoped `scheduled_report` table (name, report map key, params JSONB, `report_frequency_id` FK to a new central `report_frequency` catalog — keys `daily`/`weekly`/`monthly`, fixed UUIDs — recipient user, `is_active`, `last_run_at`), Orion CRUD behind `nominal_report`-tier permissions, and a daily `reports:run-scheduled` command that generates the xlsx through the SAME mapped path under the owning tenant's context and delivers an in-app notification with a download link. Idempotent per day (a rerun does not double-deliver). This requirement MAY be dropped to its own change (`edi-scheduled-reports`) without affecting REQ-RPT-001..012; on drop, it does NOT merge to `openspec/specs/` as delivered.

#### Scenario: daily run is idempotent and tenant-bound
- GIVEN an active monthly scheduled report of tenant A due today
- WHEN `reports:run-scheduled` runs twice
- THEN exactly one generated file and one notification exist, both under tenant A, and tenant B saw nothing.
