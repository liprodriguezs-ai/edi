# Design: Unit Limit Enforcement

## Technical Approach

One `UnitLimitService` (Modules/SubscriptionPlan, beside `PlanPricingService`) resolves the tenant's band from ACTIVE entitlements and validates requested increases. The universal guard is `UnitObserver::creating()`/`restoring()` — model-level, so it covers all 5 increase paths (store, batch, restore, batch/restore, importer chunk jobs) and any future path. Batch endpoints add an Orion pre-flight for accurate all-or-nothing copy; the importer preview appends a file-level blocking issue reusing `calculateDistinctCounters()` (zero per-row queries). Failure surfaces as a self-rendering 422 domain exception.

## Architecture Decisions

| # | Decision | Choice | Rejected | Rationale |
|---|----------|--------|----------|-----------|
| 1 | Guard location | `UnitObserver::creating()` + `restoring()` (before `created()` — INSERT already happened there). Observer stays ordered BEFORE `GlobalObserver` | Per-endpoint guards; FormRequest rules | `POST /unit/batch` bypasses module FormRequests; importer calls `Unit::create()` directly in queued chunk jobs. One choke point, no path forgotten |
| 2 | Batch atomicity | Pre-flight in `UnitController::beforeBatchStore()` (delta = `count($request->input('resources'))`) and `beforeBatchRestore()` (delta = trashed count among requested ids); observer stays as per-row backstop | Observer-only | Orion wraps batches in ONE transaction (`batchStoreWithTransaction`, transactions enabled), so even a mid-batch observer throw rolls back all rows — atomicity holds regardless. Pre-flight exists for accurate copy (a row-6 failure would report a mid-batch count) and to avoid wasted inserts + charge generation before rollback |
| 3 | Exception surfacing | `UnitLimitExceededException extends Exception implements ShouldntReport` + `render(): JsonResponse` returning **422**, in `Modules/SubscriptionPlan/app/Exceptions/` | 403; `abort()` at call sites; catch-per-controller | Modeled on `DelinquencyRestrictedException` (verified): `ShouldntReport` prevents Nightwatch alerting routine business blocks; self-render avoids duplicating payload-building across 5 surfaces. 422 (not 403) because the request is well-formed but unprocessable by resource state — matches the sibling guards on the SAME endpoints (identity 422, `beforeDestroy` 422, import validate 422); Delinquency's 403 is actor authorization. Lives beside the service that throws it; keeps `Modules/Collections/**` and `Modules/Configuration/**` untouched (parallel session) |
| 4 | Band resolution + unresolvable case | Distinct `billing_package_id` of `TenantEntitlement::active()` (scope = not expired) → `BillingPackage.suggested_unit_max`. No entitlement or null max → **fail-open** (no cap). >1 distinct package → max cap among them + `report()` | Fail-closed | `SubscriptionStateService` already blocks no-entitlement tenants upstream, and every existing test/factory that seeds no billing data keeps passing (preserves DataImport 72U+47F/455 and Housing baselines with zero churn). Tradeoff accepted: fail-open bug = silent unlimited — mitigated by feature tests pinning the guard on a seeded band (regression breaks CI); fail-closed would brick unit creation on any billing-data bug. Null max also future-proofs an unlimited Enterprise band |
| 5 | Importer preview | In `ImportRunner::validate()` after counters: if `activeUnits + units_to_create > limit`, append `{row: 0, severity: 'error', column: 'unidades', general: true, message}` and `blocked += 1` (`payments_to_register` keeps the row-level count) | New response field; per-row errors | Fits the existing `{row,severity,column,message,general?}` contract and the `rows.<n>.<col>` 422 key (`rows.0.unidades`; data rows are 1-based, 0 = file). +2 constant queries. FE `ValidateStep` renders `row === 0` as "Archivo" |
| 6 | Upgrade hint | Reuse `PlanPricingService::planForUnits(active + attempted)`; hint only if the returned plan fits the needed total AND differs from the current plan; else graceful top-band copy | Hand-rolled band walk | DRY; `planForUnits` clamps above Premium to Premium, which then fails the fits-check → never links a nonexistent plan |
| 7 | Tenancy | Guard early-returns unless `tenancy()->initialized`; count = `Unit::count()` (TenantScope + SoftDeletes default = exactly the tenant-scoped `deleted_at IS NULL` liveness of `unit_identity_unique`). Guard keys on tenancy, NOT `auth()` | `withoutGlobalScope` + manual `where` | An unscoped count would mix tenants. Chunk jobs are covered: `QueueTenancyBootstrapper` re-initializes tenancy in queued jobs (`RowImporter::execute` already calls `tenant('id')` there); `auth()` is false in jobs, which is why the guard must not depend on it |
| 8 | Seeders/factories | No bypass flag. Guard is inert when tenancy is uninitialized or the band is unresolvable (fail-open); `restoring()` skips non-trashed models | `runningInConsole()` gate | The importer also runs in console (queue) — that gate would disable the backstop. DevClusterSeeder fits (Encinos 6/25); tests without entitlements are unaffected by construction |
| 9 | Concurrency | Accept the read-then-insert race in v1; document `pg_advisory_xact_lock(hashtext(tenant_id::text))` in the service as escalation | Locking now | Pre-production, low likelihood (proposal risk table); KISS |

## Data Flow

    POST /unit ───────────────► Orion store (tx) ─────┐
    POST /unit/batch ─► beforeBatchStore ─► (tx) ─────┤ per-row creating/restoring
    POST /unit/{id}/restore ──► Orion restore (tx) ───┼──► UnitObserver ──► UnitLimitService.assertCanIncrease(1)
    POST /unit/batch/restore ─► beforeBatchRestore ───┤          │ throws UnitLimitExceededException → self-render 422
    chunk jobs: RowImporter::execute → Unit::create ──┘          │ (importer: row lands in failed_count, cap never exceeded)
    POST /import/validate ─► ImportRunner::validate ─► UnitLimitService.checkIncrease(units_to_create)
                                                        └► row-0 'unidades' error → blocked>0 → 422 rows.0.unidades

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `backend/Modules/SubscriptionPlan/app/Services/UnitLimitService.php` | Create | Band resolution, active count, `checkIncrease(int $delta): UnitLimitCheck`, `assertCanIncrease(int $delta): void`, import-copy builder |
| `backend/Modules/SubscriptionPlan/app/Exceptions/UnitLimitExceededException.php` | Create | `ShouldntReport` + `render()` 422; named constructor `forIncrease(UnitLimitCheck $check)` |
| `backend/Modules/Housing/app/Observers/UnitObserver.php` | Modify | Add `creating()` + `restoring()` guards (tenancy-gated; restoring skips non-trashed) |
| `backend/Modules/Housing/app/Http/Controllers/UnitController.php` | Modify | Add `beforeBatchStore()` / `beforeBatchRestore()` pre-flights |
| `backend/Modules/DataImport/app/Services/ImportRunner.php` | Modify | Inject `UnitLimitService`; append file-level issue + blocked bump in `validate()` |
| `frontend/src/modules/import/components/ValidateStep.tsx` | Modify | Render `row === 0` as "Archivo" in `IssueTable` |

No changes to `RowImporter`, chunk jobs, routes, migrations, or `frontend` unit CRUD (the 422 `message` surfaces through the existing ApiError → toast path).

## Interfaces / Contracts

422 payload (all paths):

```json
{ "message": "…", "code": "UNIT_LIMIT_EXCEEDED", "plan_name": "Básico", "unit_limit": 25,
  "active_units": 25, "attempted_increase": 10, "suggested_plan_name": "Esencial" }
```

`suggested_plan_name` is `null` at the top band. Copy (Mexican Spanish, tú):

- API, upgrade exists: `"Tu plan {plan} permite hasta {max} unidades activas y ya tienes {current}. No es posible agregar {n} más. Considera cambiar al plan {next}."`
- API, top band: `"…No es posible agregar {n} más. Contacta a soporte para ampliar tu límite."`
- Preview, upgrade exists: `"Tu plan {plan} permite hasta {max} unidades activas y ya tienes {current}; este archivo crearía {n} unidades nuevas. Reduce el archivo o cambia al plan {next}."`
- Preview, top band: `"…este archivo crearía {n} unidades nuevas. Reduce el archivo o contacta a soporte para ampliar tu límite."`

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit (new `Modules/SubscriptionPlan/tests/Unit/UnitLimitServiceTest.php`) | Band resolution: 0 / 1 / >1 distinct packages, expired entitlements, null max → fail-open; remaining math; hint vs top-band | Seed `billing_package` + `tenant_entitlement`, assert `UnitLimitCheck` |
| Feature (new `Modules/Housing/tests/Feature/UnitLimitEnforcementTest.php`) | Reach exactly cap OK; cap+1 → 422 payload/copy; batch overflow admits ZERO (atomic); batch exactly-fills OK; restore + batchRestore counted; soft-deleted excluded; fail-open without entitlement; top-band copy | HTTP tests on all 4 endpoints with a seeded Básico band |
| Feature (new `Modules/DataImport/tests/Feature/ImportUnitLimitTest.php`) | Validate over-band → 422, key `rows.0.unidades`, `blocked > 0`, counters intact; within band → 200; execute backstop: over-cap rows → `failed_count`, cap never exceeded | Reuse existing import test fixtures |
| Existing baselines (must stay green, unmodified) | Row semantics: `RowImporterValidateTest`, `RowImporterExecuteTest`; chunk engine: `ImportExecuteTest`, `RunImportJobTest`; DataImport 72 Unit + 47 Feature, 455 assertions (no entitlements seeded → fail-open keeps behavior identical); Housing identity-guard tests | `php artisan test Modules/DataImport/tests` + Housing suite, host php, one process |
| FE (modify `ValidateStep.test.tsx`) | Row-0 issue renders "Archivo"; Continue disabled via existing `blocked` gating | Vitest + Testing Library |

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary.

## Migration / Rollout

No migration required. Code-only; rollback = revert commit (proposal §Rollback).

## Open Questions

None blocking.
