# Proposal: EDI Reports and Indicators

## Intent

The legacy system's three outputs that staff actually used — the center indicator dashboard, the EDI concentrate (aggregates by period and center), and the exportable nominal list — do not exist here. Screenings persist (737-test baseline) but nobody can see the month's results, compare centers, or export child-by-child detail. This change recreates the `Reports` module with EDI content and registers the EDI entities in the intentionally empty `REPORT_MODEL_MAP`, activating the complete but idle qch/core export engine.

## Why now

- `Reports → Screening → ChildRecord → Clinic` is the next acyclic dependency; screening persistence just landed.
- The export machinery (filters, Excel streaming, async, cleanup) already exists — only the whitelist and `reportColumns()` are missing.
- Coordinators have zero visibility over red/yellow results across centers today.

## Scope

### In Scope
- Backend `Modules/Reports`: `screening_indicator_snapshot` (tenant-scoped materialized aggregates per period × center) + builder service + idempotent per-tenant scheduled command.
- Indicator endpoints (summary, monthly series, area breakdown, concentrate): snapshots for closed months, cached live current month (`CachesTenantReports`/`BuildsTenantReports`).
- `REPORT_MODEL_MAP` entries (`Screening`, `Child`, `ScreeningIndicator`) with correct `with`; `reportColumns()` on those models.
- Nominal list: dedicated paginated endpoint + xlsx export behind its own PII permission, separate from aggregates.
- Frontend `reports` module: indicator dashboard (charts, lazy catalogs) + nominal list page; full registration checklist.
- `scheduled_report` as a final SEVERABLE phase.

### Out of Scope
- SIS/FUA official export (current spec unavailable; readiness documented in design §7).
- Nutritional-status panel (no WHO weight/height reference tables yet — follow-up change).
- Stimulation/FollowUp reports, notifications on results, historical import, engine changes.

## Capabilities

### New Capabilities
- `reports-and-indicators`: materialized screening indicators per period/center, aggregate dashboard + concentrate, and PII-gated nominal listing/exports over the generic report engine.

### Modified Capabilities
- None (`screening-application` consumed read-only; its models only gain `reportColumns()`).

## Approach

Materialize closed months into `screening_indicator_snapshot` (nightly trailing-window recompute absorbs rescores and late captures); compute only the current month live, cached 5 minutes per tenant. One shared aggregation-query class feeds builder and live path so numbers cannot diverge. Exports ride the existing `customReport → mappedModelReport` path (tenant-guarded, streaming) untouched.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `backend/Modules/Reports/` | New | Snapshot table, services, command, indicator/nominal API |
| `backend/core/.../ReportController.php` | Modified | `REPORT_MODEL_MAP` const entries only |
| `Screening`/`Child` models | Modified | Add `reportColumns()` |
| `backend/database/seeders/*`, `modules_statuses.json` | Modified | Registration |
| `frontend/src/modules/reports/`, `App.tsx`, `useCatalogs.ts`, `AngularController` | New/Modified | Dashboard + nominal pages, routes, catalog pairs |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Aggregation full scans / N+1 | Med | Set-based grouped queries; snapshot reads; bounded live window; query-count test |
| PII leak via aggregate endpoints | Med | No-PII payload scenario; separate permission entities |
| Cross-tenant export leak | Low | Mandated isolation scenario (REQ-RPT-009) |
| Review budget | High | Chained PRs; `scheduled_report` severable |

## Rollback Plan

New module plus additive const/seeder entries; no existing table altered. Revert the PR(s); `migrate:rollback` drops the snapshot table; `reportColumns()` additions are inert without map entries.

## Dependencies

- `edi-screening-application` and `edi-clinic-and-child-registry` delivered. NOT dependent on `edi-screening-ui`.

## Success Criteria

- [ ] Tenant-isolation export test: tenant A's xlsx contains zero tenant-B rows.
- [ ] Dashboard endpoints return exact aggregates for a seeded fixture with ZERO PII fields.
- [ ] Snapshot command re-run converges (idempotent) and absorbs a rescore.
- [ ] Backend suite green from 737; frontend green from 221.

## Proposal question round

Interactive round unavailable in this executor context — defaults are encoded in `design.md` §8; correct them before `sdd-apply`.

1. **Nutritional status**: deferred — somatometry is captured per screening, but WHO weight/height reference tables don't exist in `Instrument` (only `head_circumference_reference`). Dashboard ships without that panel. Default: defer to `edi-anthropometry-references`.
2. **Counting basis**: period bucket = `visit.visit_date` month; counted set = non-invalidated screenings with a result (`scored` or `completed`); `completed` tracked as sub-count. Default: yes.
3. **Freshness cut**: nightly rebuild of trailing 3 months; current month live + 5-min tenant cache. Default: yes.
4. **Nominal data source**: dedicated `/api/reports/nominal` endpoint under new `nominal_report` permission (not the `/api/screening` CRUD permission); export ability stays `report.screening` (engine-fixed), granted to the same admin-tier roles. Default: yes.
5. **Scheduled reports**: built as severable Phase 6, in-app notification delivery (no email); recommend dropping to `edi-scheduled-reports` if the budget trips. Default: severable.
