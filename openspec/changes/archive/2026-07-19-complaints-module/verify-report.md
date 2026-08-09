# Verify Report — complaints-module (Denuncias)

> Phase: sdd-verify · Status: **pass-with-notes** · Date: 2026-07-19
> Change name: `complaints-module` · Project (engram): `residia-monorepo`
> Mirrored to engram topic `sdd/complaints-module/verify-report`.

## Executive summary

0 CRITICAL, 0 WARNING (blocking), 2 SUGGESTION-level notes. All 15 spec requirements are
COMPLIANT with implementing code + covering tests. All 7 binding user decisions are honored.
43/44 tasks are ticked truthfully (spot-checked); task 8.4 (this verify + `/review-stack`) is
correctly left unticked pending the review-stack run, which is a separate step from sdd-verify
and was NOT performed here. Backend: 20/20 tests passed (59 assertions), re-run live. Frontend:
24/24 tests passed (4 files), re-run live, plus lint (0 errors) and build succeeded per
apply-progress (not re-run here, no code changed since that report).

## Requirement-by-requirement compliance

| # | Requirement | Status | Implementing code | Covering test(s) |
|---|---|---|---|---|
| 1 | Registration Gate | COMPLIANT | `ComplaintService::register()` — `hasAnyRole($this->reporterRoleKeys())` pre-flight abort(403); `ComplaintController::performStore` delegates; route gated `create.complaint` | `ComplaintRegistrationTest::test_board_member_can_register_complaint`, `test_resident_without_permission_denied`, `test_runtime_reporter_role_keys_denies_role_excluded_from_config` (proves the two gates — coarse Spatie vs runtime config — are orthogonal, per ADR-5) |
| 2 | Denounced Subject Shape | COMPLIANT | `complaint` migration: `against_description` `text` NOT NULL, `against_unit_id` nullable FK; `ComplaintCreateRequest` mirrors (`against_description` required, `against_unit_id` nullable); grep confirms **zero** occurrences of `against_user_id` anywhere in `backend/Modules/Complaint/` or `frontend/src/modules/complaint/` | `ComplaintExclusionTest` (complaint with unit), `ComplaintFsmTest`/`ComplaintMessageTest` (complaint without unit) |
| 3 | Category Catalog | COMPLIANT | `ComplaintCategory extends TenantCatalogModel`, `TenantCatalogSeeder::CATALOGS` seeds 8 labels per tenant, `ComplaintCategoryController` full tenant CRUD | Registration/FSM/etc. tests all resolve `ComplaintCategory::first()->id` per-tenant via `catalogIds()` helper — implicitly exercised every test run |
| 4 | Priority Catalog | COMPLIANT | `ComplaintPriority extends TenantCatalogModel`, low/medium/high defaults seeded with color+order; grep of `ComplaintService`/`ComplaintMessageService` confirms **no** branching on `complaint_priority_id` — cosmetic only | Same as above (implicit) |
| 5 | Status FSM | COMPLIANT | `ComplaintStatus` central catalog, 4 fixed UUID consts + `TERMINAL` array; `ComplaintService::changeStatus()` — `assertManagerNotExcluded` + `isValidTransition` matrix, `DB::transaction`, stamps/clears `resolved_at` | `ComplaintFsmTest::test_manager_can_transition_open_to_in_review`, `test_manager_reopen_resolved_clears_resolved_at`, `test_manager_reopen_dismissed_clears_resolved_at` (AMENDED reopen scenario), `test_non_manager_cannot_transition`, `test_invalid_direct_transition_between_terminal_states_rejected` |
| 6 | Confidential Exclusion by Unit Linkage | COMPLIANT | `Complaint::scopeVisibleTo()` (model), `ComplaintController::buildIndexFetchQuery`/`buildShowFetchQuery` apply it, `ComplaintService::excludedUserIdsFor()`/`resolveManagerRecipients()`, `ComplaintMessageService::excludedUserIdsFor()`/`assertVisible()` — all null-safe (`against_unit_id === null` → `[]`) | `ComplaintExclusionTest::test_denounced_unit_manager_excluded_from_detail_and_index`, `test_denounced_unit_manager_excluded_from_complaint_created_recipients`, `test_no_exclusion_applies_when_against_unit_is_null` |
| 7 | Evidence Attachments | COMPLIANT | `Complaint::registerMediaCollections()` (`evidence`), `ComplaintMessage` (`attachment`), `ComplaintService::register()` pre-flight `requiresEvidence()` abort(422) before the transaction opens | `ComplaintRegistrationTest::test_evidence_required_by_config_rejects_missing_evidence`, `test_evidence_required_by_config_accepts_with_evidence`, `test_evidence_optional_by_default` |
| 8 | Follow-up Message Thread | COMPLIANT | `ComplaintMessage` tenant-scoped, `Complaint::messages()` `orderBy('created_at')`, `ComplaintMessageService::postMessage()`/`assertVisible()` gate reporter/assignee/non-excluded-manager only | `ComplaintMessageTest::test_eligible_manager_can_post_and_read_the_thread`, `test_excluded_manager_blocked_from_thread` (both GET and POST paths asserted 403) |
| 9 | Folio Generation | COMPLIANT | `complaint_sequence` counter row + `lockForUpdate()`, `nextFolio()` format `D-%04d-%06d`, partial unique `(tenant_id, folio) WHERE deleted_at IS NULL` | `ComplaintRegistrationTest::test_folio_increments_sequentially_within_a_year` (asserts exact `D-YYYY-000001`/`000002`) |
| 10 | Notification Events | COMPLIANT | 4 events in `CatalogSeeder::seedNotificationEvents()` (fixed UUIDs, `category=NotificationCategory::COMPLAINTS`, `supports_email=false`, `default_channels=['log','firebase_push']`); dispatch exclusively via `NotifiesResidents::notifyEvent()` — grep confirms **zero** direct `SendPushNotificationJob`/`Notification::send` calls anywhere in `backend/Modules/Complaint/` | `ComplaintExclusionTest::test_denounced_unit_manager_excluded_from_complaint_created_recipients` (real `Notification`/`NotificationUser` rows asserted), `ComplaintAssignmentTest::test_manager_assigns_complaint_and_notifies_assignee` |
| 11 | Assignment | COMPLIANT | `complaint.assigned_user_id` single nullable FK; `ComplaintService::assign()` — assignee must be manager-role AND not excluded, `DB::afterCommit` notify | `ComplaintAssignmentTest::test_manager_assigns_complaint_and_notifies_assignee`, `test_excluded_user_cannot_be_assigned` |
| 12 | Per-Tenant Module Configuration | COMPLIANT | `TenantCatalogSeeder.php:327-361` seeds 5 `complaint.*` `Setting` defaults; `ConfigurationController::getComplaintSettings/updateComplaintSettings`; `GET/PUT config/complaint-settings`; `ComplaintConfigSection` registered once in `configSections.tsx` | Exercised indirectly by every test that reads/overrides `complaint.reporter_role_keys`/`requires_evidence` via `Setting::where('slug', ...)` |
| 13 | Tenancy Isolation | COMPLIANT | `BelongsToTenant` on `Complaint` and `ComplaintMessage`; both migrations use `BaseModel::columns($table, true)` | `ComplaintTenancyTest::test_cross_tenant_read_returns_not_found` |
| 14 | Module Registration | COMPLIANT | `modules.csv`/`permissions.csv`/`module_system.csv` rows; `SystemTableSeeder::seedModulePermissionEntities/seedModuleRoutes('/complaint')/seedModuleIcons('IconAlertTriangle')`; `RolePermissionTableSeeder` — 5 board roles get `read/create/update/report.complaint` (`$writeNoDelete`), `administrator`+`account_owner`+`company_admin` get full CRUD via `whereNotIn($superAdminExclusives)` (complaint is absent from that exclusion list), `resident` gets none; `App.tsx` `PermissionRoute` wired; confirmed live-registered on the real dev DB per apply-progress batch 3 (module row, role_has_permissions rows, `Fraccionamiento` business system) | Icon names (`IconAlertTriangle`, `IconCategory`, `IconFlag`, `IconCircleCheck`) verified present in `frontend/src/shared/utils/iconMap.ts`; `modules_statuses.json` has `"Complaint": true` |
| 15 | List + Detail UI | COMPLIANT | `ComplaintListPage.tsx` (filters status/category/priority/assignee via `useLazyCatalogs`+`MultiSelectFilter onFirstOpen`, infra-cost rule respected), `ComplaintDetailPage.tsx` (folio, status badge, priority chip, aging chip, subject, evidence, timeline, assignment, thread), Spanish copy throughout, no `: any` occurrences (grep confirmed) | `ComplaintListPage.test.tsx`, `ComplaintForm.test.tsx`, `schema.test.ts`, `ComplaintResource.test.ts` — 24 tests total, all pass |

## Binding decisions audit (proposal.md "USER DECISIONS — CONFIRMED 2026-07-19")

| # | Decision | Status |
|---|---|---|
| 1 | No `against_user_id`, free text always + optional unit | CONFIRMED — grep found zero occurrences |
| 2 | No SLA cron; `resolution_days_target` display-only | CONFIRMED — the slug only appears in `TenantCatalogSeeder.php` (default seed) and `ConfigurationController.php` (settings CRUD); no scheduler/command references it |
| 3 | Priority tenant-scoped, cosmetic | CONFIRMED — `TenantCatalogModel`, no branching found |
| 4 | Confidential exclusion by unit linkage, no exclusion when unit is null | CONFIRMED — implemented in 3 places (model scope, both services), tested both branches |
| 5 | No per-category subject validation | CONFIRMED — `ComplaintCreateRequest` applies no category-conditional rules |
| 6 | Core module, no `moduleEnabled` guard | CONFIRMED — `routes/api.php` explicit comment + middleware stack omits `moduleEnabled:complaint`; confirmed live on dev DB (`Fraccionamiento` business system, not Core) |
| 7 | Manager may reopen terminal complaints, `resolved_at` cleared | CONFIRMED — FSM matrix allows `resolved`/`dismissed` → `open`/`in_review`; both reopen tests pass and assert `resolved_at` is null after reopen |

## Tasks audit

All 44 tasks in `tasks.md` are ticked `[x]` except **8.4**, which is correctly left `[ ]` — it
is explicitly annotated "Orchestrator-owned, not this batch" and covers both this sdd-verify run
AND a separate `/review-stack` full-4R pass (the diff is hot-path/>400 lines per the Review
Workload Forecast). Spot-checked ticks:
- 1.1-1.7 (scaffold/migrations/seeders): all files present, migrations well-formed with comments.
- 3.6 (registration CSVs + `ResetPermissionTableSeeder`): CSVs correct; the seeder run gap found
  and fixed by the apply agent in batch 3 was verified fixed (module/permissions rows exist live).
- 7.1-7.7 (tests): all 6 backend + 4 frontend files exist and are substantive (verified by
  reading all 6 backend files in full — none are vacuous placeholder assertions).
- 8.1-8.3: re-ran 8.1 (backend) and the frontend slice of 8.2 myself — counts match exactly what
  apply-progress reported (20/59 backend, 24 frontend). Did not re-run `pnpm build` or the dev-DB
  menu inspection from 8.3 (no code changed since that report, low value to repeat).

## Convention audit (spot-checked)

- Migrations: every own column across all 6 migration files carries `->comment()`; every
  `Schema::create` closure's first line is a table `$table->comment(...)`. Compliant with rule 9.
- English identifiers: all table/column/class names in English (`complaint`, `folio`,
  `against_unit_id`, `resolved_at`, etc.); Spanish confined to UI copy and seeded label values
  (category names, status names). Compliant with rule 6.
- Catalog split (rule 8): `complaint_status` is CENTRAL (`CentralCatalogModel`, no `tenant_id`,
  fixed UUID consts, code branches on it in `isValidTransition`/`ComplaintStatus::TERMINAL`).
  `complaint_category`/`complaint_priority` are TENANT-SCOPED (`TenantCatalogModel`,
  `BelongsToTenant`, no branching). Correctly split per the decision rule.
- Notifications: grep of `backend/Modules/Complaint/` for `SendPushNotificationJob`,
  `Notification::send`, `Notification::dispatch` returns zero matches — all 4 events dispatch
  exclusively through `NotifiesResidents::notifyEvent()`.
- No `any` in new TS files: grep of `frontend/src/modules/complaint/` (excluding `__tests__`) for
  `: any` returns zero matches.

## Gaps / notes (non-blocking)

1. **SUGGESTION** — `ComplaintMessageService` duplicates `DEFAULT_MANAGER_ROLE_KEYS` and the
   `managerRoleKeys()`/`excludedUserIdsFor()` private methods verbatim from `ComplaintService`
   (design's own §5.2 acknowledges the split but doesn't call out a shared trait). Minor DRY
   violation; functionally correct and tested, low risk, could be extracted to a shared
   `ComplaintExclusion` trait/concern in a follow-up if the module grows further.
2. **SUGGESTION** — Task 8.4 bundles `/review-stack` (full 4R: `laravel-architecture-reviewer`,
   `react-frontend-reviewer`, `integration-reviewer`, `tenancy-reviewer`) as required before
   archive, given the diff is hot-path/>400 lines per the tasks.md Review Workload Forecast. This
   sdd-verify pass covered spec compliance and test re-execution only — it did NOT run
   `/review-stack`. Recommend running it before `sdd-archive` to close out 8.4 fully, per the
   orchestrator's own Agent Trigger Rules (post-sdd-phase: start review/start(target) after apply
   when no valid receipt exists).

## Test evidence (verbatim, re-run live during this verify)

- Backend: `php artisan test --compact --filter=Complaint` (host php, single process) →
  **20 passed (59 assertions)**, 57.51s.
- Frontend: `pnpm vitest run src/modules/complaint` → **4 test files, 24 tests, all passed**,
  3.79s.

## Result

- **status**: pass-with-notes
- **next_recommended**: `/review-stack` (full 4R, per tasks.md 8.4) before `sdd-archive`
- **risks**: none CRITICAL; the two SUGGESTION notes above are non-blocking
