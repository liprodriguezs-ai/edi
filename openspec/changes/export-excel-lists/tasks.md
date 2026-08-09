# Tasks: Export to Excel on Business List Pages (export-excel-lists)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~900–1300 (backend ~600–700: controller + 15 `reportColumns()` + test file; frontend ~300–400: 13 pages + CatalogCrudModule + useContributionsList + AccessLog replacement) |
| 400-line budget risk | High |
| Chained PRs recommended | No |
| Suggested split | Single PR (size:exception) |
| Delivery strategy | exception-ok |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Entire change (backend + models + tests + frontend) | PR 1 (single) | User accepted `size:exception` for one delivery; do not split despite forecast exceeding the 400-line budget |

## Phase 1: Backend — `ReportController` endpoint hardening

Files: `backend/core/src/Http/Controllers/ReportController.php`

- [x] 1.1 Add `use Illuminate\Auth\Access\AuthorizationException;`; remove the now-unused `UnauthorizedHttpException` import.
- [x] 1.2 Fix `checkPermission()` to throw `AuthorizationException` when the Sanctum token lacks `report.<snake_key>` and lacks `*`; call it as the first statement in `customReport()` for ALL `_model` values (spec: Sanctum Ability Authorization — all 3 scenarios).
- [x] 1.3 Replace the `_model`-missing guard's `$this->error(...)` with `response()->json(['message' => '...'], 422)` (design decision: the empty `Helpers` trait made this a latent fatal).
- [x] 1.4 Add `private const REPORT_MODEL_MAP` (15 entries, inline FQCNs + `with`, per design's Verified Model Map table) and the early-return branch `if (isset(self::REPORT_MODEL_MAP[$request['_model']])) return $this->mappedModelReport($request);` before the legacy string-concat fallback (spec: Model Resolution via Explicit Map — both scenarios).
- [x] 1.5 Delete the dead legacy Audit special case; leave the rest of the legacy switch/body byte-for-byte unchanged (spec: Legacy model resolution is preserved).
- [x] 1.6 Implement `mappedModelReport()`: `ApiHandler::parseMultiple($model)->getBuilder()->with($config['with'])`, call `applyRequestFilters()`, apply `applyAuditTenantGuard()` when `_model === 'Audit'`, `->orderBy('created_at','desc')->get()`, `$model->reportColumns()`, dispatch by `_type` (spec: Eager Loading from the Model Map, Deterministic Result Ordering). DEVIATION: Audit bypasses `ApiHandler::parseMultiple` and builds via `$model->newQuery()->with(...)` directly — see apply-progress notes.
- [x] 1.7 Implement `applyRequestFilters($builder, $model)`: read `request()->input('filters', [])`, whitelist `getFillable() + [id, created_at, updated_at]`, skip dotted fields and unknown operators per-filter (never fatal), support `=, !=, >, >=, <, <=, like, not like, in, not in` (spec: Whitelisted Body-Filter Application — all 3 scenarios).
- [x] 1.8 Implement `applyAuditTenantGuard($builder)` mirroring `AuditController::buildFetchQuery` verbatim (spec: Audit Cross-Tenant Isolation Guard — all 3 scenarios).

## Phase 2: Backend — `reportColumns()` per model

Add `reportColumns(): array` (Collaborator pattern) using the design's Charge reference implementation (§2) and per-entity column-source resolutions for the rest. Money via `(float) formatByDecimal() : 0`; dates `d/m/Y`; datetimes `d/m/Y H:i`; booleans `Sí`/`No`; relations `?->name` (spec: Spanish Column Headers, Value Formatting Rules — all scenarios).

- [x] 2.1 Collections: `Charge.php`, `ChargeBatch.php`, `Payment.php` (`backend/Modules/Collections/app/Models/`).
- [x] 2.2 Treasury: `Expense.php`, `BankAccount.php` (`backend/Modules/Treasury/app/Models/`) — Expense `Beneficiario`/`Estado` per design's resolution.
- [x] 2.3 Housing: `Unit.php`, `Vehicle.php`, `UnitUser.php` (`backend/Modules/Housing/app/Models/`) — Unit `built_m2`/`land_m2`/`ownership_share` cast `(float)`.
- [x] 2.4 Access: `AccessLog.php`, `Visitor.php`, `BlacklistEntry.php` (`backend/Modules/Access/app/Models/`) — AccessLog per design resolution; Visitor MUST NOT export `qr_token`.
- [x] 2.5 Providers: `Provider.php` (`backend/Modules/Providers/app/Models/`).
- [x] 2.6 Projects: `CommunityProject.php`, `ProjectContribution.php` (`backend/Modules/Projects/app/Models/`).
- [x] 2.7 Audit: `Audit.php` (`backend/Modules/Audit/app/Models/Audit.php`, empty subclass) — `old_values`/`new_values` via `json_encode($v, JSON_UNESCAPED_UNICODE)`.

## Phase 3: Backend — tests & lint

- [x] 3.1 Create `backend/tests/Feature/CustomReportTest.php` using `RefreshDatabase` + `Tests\Support\WithTenantSetup`, `Excel::fake()` + `Excel::assertDownloaded()`.
- [x] 3.2 Case: 403 without ability (token `['read.charge']`) — `assertForbidden()`.
- [x] 3.3 Case: 200 + `assertDownloaded('cargos.xlsx')` with `['report.charge']`.
- [x] 3.4 Case: wildcard `['*']` token passes.
- [x] 3.5 Case: Audit tenant isolation via `twoTenants()`; insert fixtures via `DB::table('audits')->insert([...])` (query builder, not `Audit::query()->insert()`, since `Audit` doesn't extend `Model` directly through `Builder::insert` cleanly here) — NOT `create()`/`saveQuietly()` (afterCommit gotcha under `RefreshDatabase`); assert collection has tenant A's row, not B's.
- [x] 3.6 Case: body filters narrow — 2 charges on different units, `filters: [{field:'unit_id',operator:'=',value:$unitA->id}]`, assert `collection()->count() === 1`.
- [x] 3.7 Run `php artisan test --compact --filter=CustomReport` on HOST php (not sail), single process only; then `vendor/bin/pint --dirty`. Result: 5 passed (13 assertions); Pint passed with no changes needed on the second pass.

## Phase 4: Frontend — export button wiring (13 pages)

Standard pattern (`CollaboratorList.tsx:245-272`): `PermissionGuard permission="report.<key>"` + outline Button + Download icon + `onReport('<Model>','xlsx','<title>')`, `disabled={isLoading}` (spec: Permission-Gated Export Button, Export Honors Active List Filters).

- [x] 4.1 `charge/ChargeListPage.tsx` — Charge / cargos / report.charge.
- [x] 4.2 `chargeBatch/ChargeBatchListPage.tsx` — ChargeBatch / cobros / report.charge_batch; wrap lone `PageHeader` in flex row.
- [x] 4.3 `payment/PaymentListPage.tsx` — Payment / pagos / report.payment.
- [x] 4.4 `expense/ExpenseListPage.tsx` — Expense / egresos / report.expense.
- [x] 4.5 `bankAccount/BankAccountListPage.tsx` — BankAccount / cuentas-bancarias / report.bank_account.
- [x] 4.6 `unit/UnitListPage.tsx` — Unit / unidades / report.unit.
- [x] 4.7 `unitUser/UnitUserListPage.tsx` — UnitUser / residentes-por-unidad / report.unit_user; wrap `PageHeader`.
- [x] 4.8 `access/VisitorListPage.tsx` — Visitor / visitantes / report.visitor.
- [x] 4.9 `access/BlacklistListPage.tsx` — BlacklistEntry / lista-negra / report.blacklist_entry.
- [x] 4.10 `provider/ProviderListPage.tsx` — Provider / proveedores / report.provider.
- [x] 4.11 `communityProject/CommunityProjectListPage.tsx` — CommunityProject / proyectos / report.community_project.
- [x] 4.12 `audit/AuditList.tsx` — Audit / auditoria / report.audit.
- [x] 4.13 `shared/components/catalog/CatalogCrudModule.tsx`: add optional `reportModel?`/`reportTitle?` props gating a Download button by `report.${permissionEntity}`; `vehicle/VehicleListPage.tsx` passes `reportModel="Vehicle" reportTitle="vehiculos"` (spec: Vehicle export via CatalogCrudModule scenario).
- [x] 4.14 `communityProject/hooks/useContributionsList.ts`: add `exportXlsx` callback + `isExporting` state per design's exact implementation, posting `_model:'ProjectContribution'` with the hook's existing `filters`/`search`; wire button in `components/ContributionsList.tsx` toolbar, gated `report.project_contribution` (spec: ProjectContribution export via exportXlsx scenario).
- [x] 4.15 `access/AccessLogListPage.tsx`: delete the `downloadCsv()` helper and its button; replace with the standard button `onReport('AccessLog','xlsx','bitacora-accesos')` gated `report.access_log` (spec: AccessLog CSV button replaced scenario).
- [ ] 4.16 Manual regression check: Collaborator export still works with/without active filters (spec: Collaborator Export Regression). DEFERRED — requires the dev server/API, out of scope for this batch per apply instructions; no code touched Collaborator's own wiring, so behavior is unchanged. Needs manual QA before archive.
- [x] 4.17 Run `pnpm lint` and `pnpm typecheck` (`tsc -b`) in `frontend/`. `pnpm typecheck` (`tsc -p tsconfig.app.json --noEmit`) — clean, 0 errors. `pnpm lint` — 18 pre-existing errors, all in `frontend/emails/*.tsx` (unused Blade-placeholder args), unrelated to this change; 0 new errors introduced. Warnings: pre-existing `max-lines` warnings on files that were already over the 500-line threshold before this batch (ChargeListPage, ProviderListPage, BankAccountListPage, VisitorListPage, CatalogCrudModule, CommunityProjectListPage's pre-existing `react-refresh/only-export-components`) — line counts grew modestly but no new files crossed the threshold.
- [x] 4.18 UX fix: export uses local isExporting (button spinner), never the store's global isLoading (user feedback 2026-07-06).
- [x] 4.19 Perf hardening: xlsx-only mapped exports (422 otherwise); >threshold rows stream via lazy(500)+generateExcelToFile (512M) instead of get()+4GB (user feedback 2026-07-06).
