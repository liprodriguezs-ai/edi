# Tasks: Complaint Management (Denuncias)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~5,500–6,000 (backend ~3,400 incl. tests; frontend ~2,200 incl. tests) |
| 400-line budget risk | High |
| Chained PRs recommended | No |
| Suggested split | Single PR, `size:exception` (module is not cleanly severable — shared FSM/exclusion logic spans layers) |
| Delivery strategy | exception-ok |
| Chain strategy | size-exception |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: High

### Suggested Work Units (informational review checkpoints within the single exception PR)

| Unit | Goal | Focused test command | Runtime harness | Rollback boundary |
|------|------|----------------------|-----------------|-------------------|
| 1 | Backend core (Phases 1–4): schema, models, services, FSM, exclusion, notifications | `php artisan test --compact tests/Feature/Complaint` | `tenants:seed` on a dev tenant + tinker `ComplaintService::register` | `backend/Modules/Complaint/` + catalog/notification seeder additions |
| 2 | Registration + config (Phases 3.6, 5) | `php artisan test --compact --filter=RolePermission` | Log in as board role, hit `/api/complaint` | CSV/seeder rows + `config/complaint-settings` |
| 3 | Frontend module (Phase 6) | `pnpm vitest run src/modules/complaint` | Load `/complaint` via LAN IP (not localhost) | `frontend/src/modules/complaint/` + `App.tsx` route block |

---

## Phase 1: Backend scaffold, migrations, catalogs

- [x] 1.1 Scaffold `backend/Modules/Complaint/` (ComplaintServiceProvider, RouteServiceProvider, EventServiceProvider, `config/config.php`); set `"Complaint": true` in `modules_statuses.json`.
- [x] 1.2 Migrations `create_complaint_status_table` (central, no `tenant_id`), `create_complaint_category_table`, `create_complaint_priority_table`, `create_complaint_sequence_table` (tenant-scoped, `BaseModel::columns`, `->comment()` on every column + table).
- [x] 1.3 Migration `create_complaint_table` — all §3.1 columns, indexes, partial unique `(tenant_id, folio) WHERE deleted_at IS NULL`.
- [x] 1.4 Migration `create_complaint_message_table` — FK `complaint_id` cascadeOnDelete, tenant-scoped.
- [x] 1.5 `CatalogSeeder::seedComplaintStatuses()` — 4 fixed-UUID rows (open/in_review/resolved/dismissed).
- [x] 1.6 `TenantCatalogSeeder::CATALOGS` — add `ComplaintCategory` (8 labels) + `ComplaintPriority` (low/medium/high).
- [x] 1.7 `ComplaintDatabaseSeeder.php`; verify with `php artisan module:seed Complaint`.

## Phase 2: Models, services, folio, FSM, exclusion

- [x] 2.1 Models `Complaint` (fillable, casts, `HasMedia`+`evidence`, relations category/priority/status/reporter/assignee/against_unit/messages), `ComplaintMessage` (`attachment` media), `ComplaintStatus` (`CentralCatalogModel`, UUID consts), `ComplaintCategory`/`ComplaintPriority` (`TenantCatalogModel`), `ComplaintSequence`.
- [x] 2.2 `ComplaintService::register()` — transaction, stamp `reporter_user_id`, status=OPEN, `nextFolio()` (lockForUpdate, copy `PaymentService::nextReceiptSequence`), attach evidence, `requires_evidence` pre-flight 422, `DB::afterCommit` conditional notify.
- [x] 2.3 `ComplaintService::changeStatus()` — FSM matrix §5.1, manager+not-excluded assert, stamp/clear `resolved_at` on reopen, `abort(422)` on invalid transition.
- [x] 2.4 `ComplaintService::assign()` — assignee ∈ managers AND not excluded; `DB::afterCommit` notify.
- [x] 2.5 Private `nextFolio`, `excludedUserIdsFor` (active `unit_user` of `against_unit_id`), `resolveManagerRecipients` (managers − excluded).
- [x] 2.6 `ComplaintMessageService::postMessage()` — visibility assert, create+media, `DB::afterCommit` notify.
- [x] 2.7 `Complaint::scopeVisibleTo(User)` — excludes denounced-unit members unless caller is the reporter.

## Phase 3: Orion controllers, requests, routes, permissions

- [x] 3.1 `ComplaintController` — Orion CRUD, `$keyForPermission='complaint'`, `performStore`→`register`, `buildIndexFetchQuery`/`buildShowFetchQuery` apply `visibleTo`, custom actions `transition`/`assign`/`message`/`messages` (resolve by id inside method, no route-model binding).
- [x] 3.2 `ComplaintCategoryController`, `ComplaintPriorityController` — tenant-catalog CRUD.
- [x] 3.3 `ComplaintStatusController` — Orion `->only(['index','show'])`.
- [x] 3.4 FormRequests (array-style rules): `ComplaintCreateRequest`, `ComplaintUpdateRequest`, `ComplaintMessageRequest`, `ComplaintTransitionRequest`, `ComplaintAssignRequest`, category/priority Create+Update.
- [x] 3.5 `routes/api.php` — custom routes BEFORE `Orion::resource('complaint', ...)`; register category/priority/status resources.
- [x] 3.6 Registration checklist: `modules.csv`, `permissions.csv` (6 rows), `module_system.csv`; `SystemTableSeeder::seedModulePermissionEntities/seedModuleRoutes('/complaint')/seedModuleIcons('IconAlertTriangle')/seedModuleVisibility`; `RolePermissionTableSeeder` — `create.complaint`→board roles, `read/update/delete/restore/report.complaint`→account_owner/company_admin via `whereNotIn`. Ran `php artisan db:seed --class=ResetPermissionTableSeeder`. (Icon changed from proposed `ShieldAlert` to existing Tabler `IconAlertTriangle`/`IconCategory`/`IconFlag`/`IconCircleCheck` — no new frontend iconMap.ts entries needed this batch.)

## Phase 4: Notifications

- [x] 4.1 `CatalogSeeder::seedNotificationEvents()` — 4 events (`complaint_created/assigned/status_changed/message`), fixed UUIDs, `audience='board'`, `supports_email=false`, `default_channels=['log','firebase_push']`. Added `NotificationCategory::COMPLAINTS` (resolves design assumption A3).
- [x] 4.2 Wire `NotifiesResidents::notifyEvent()` calls per §8 recipients table, `click_action='/complaint/{id}'`.

## Phase 5: module-config slice

- [x] 5.1 Run `module-config` dialog to confirm the 5 settings (`manager_role_keys`, `reporter_role_keys`, `auto_notify_managers_on_create`, `requires_evidence`, `resolution_days_target`); delegate to `module-config-scaffolder`.
- [x] 5.2 Verify output: `Setting` slugs `complaint.*`, `ConfigurationController::getComplaintSettings/updateComplaintSettings`, `GET/PUT config/complaint-settings`, defaults seeded via the `TenantCreated`/`tenants:seed` chain, `ComplaintConfigSection.tsx` registered in `configSections.tsx`.

## Phase 6: Frontend module `frontend/src/modules/complaint/`

- [x] 6.1 `schema.ts` — `IComplaint`, `IComplaintMessage`, `IComplaintStatus`, category/priority `INamed`, Zod schemas (form/message/transition/assign).
- [x] 6.2 `services/ComplaintResource.ts` — `createCrudApi` + customMethods `transition/assign/postMessage/messages`.
- [x] 6.3 `store/complaintStore.ts` — `createEntityStore<IComplaint>('complaint')`.
- [x] 6.4 `ComplaintListPage.tsx` — filters status/category/priority/assignee via `useLazyCatalogs` + `MultiSelectFilter onFirstOpen`, `useBaseList`.
- [x] 6.5 `ComplaintDetailPage.tsx` — folio header + `ComplaintStatusBadge` + priority chip + aging chip; subject/evidence/timeline/assignment/thread sections; local `isSubmitting` spinner (never global `isLoading`).
- [x] 6.6 `ComplaintForm.tsx` — RHF+Zod, catalogs loaded on form open, `against_unit_id` optional, `against_description` required.
- [x] 6.7 `category/`, `priority/` — `CatalogCrudModule` screens (schema/resource/store per folder).
- [x] 6.8 `ComplaintConfigSection.tsx` — React Query + `useSectionEdits`, one entry in `configSections.tsx`.
- [x] 6.9 `App.tsx` — `<PermissionRoute permissionEntity="complaint">` for `/complaint`, `/complaint/:id`, catalog admin routes.

## Phase 7: Tests

> Backend tests run on HOST php against the shared `testing` DB — only ONE `php artisan test` process at a time (see project rule: parallel `RefreshDatabase` runs corrupt the shared schema).

- [x] 7.1 `tests/Feature/Complaint/ComplaintRegistrationTest.php` — board registers (201); non-board denied (403); runtime `complaint.reporter_role_keys` config gate denies a role that holds the coarse Spatie permission (orthogonal-gates check); evidence-required config (both branches); folio sequential across two sequential creates.
- [x] 7.2 `tests/Feature/Complaint/ComplaintFsmTest.php` — valid transition; reopen resolved/dismissed clears `resolved_at`; non-manager rejected; invalid direct terminal-to-terminal swap rejected (422).
- [x] 7.3 `tests/Feature/Complaint/ComplaintExclusionTest.php` — denounced-unit member excluded from list/detail/`complaint_created` recipients; null unit → no exclusion (visible + included in recipients).
- [x] 7.4 `tests/Feature/Complaint/ComplaintMessageTest.php` — thread visible to eligible manager; excluded manager blocked (GET + POST).
- [x] 7.5 `tests/Feature/Complaint/ComplaintAssignmentTest.php` — assign notifies assignee; excluded assignee rejected (422).
- [x] 7.6 `tests/Feature/Complaint/ComplaintTenancyTest.php` — cross-tenant read → not-found.
- [x] 7.7 Frontend: `__tests__/schema.test.ts`, `ComplaintResource.test.ts`, `ComplaintListPage.test.tsx`, `ComplaintForm.test.tsx` (Package precedent).

## Phase 8: Verification

- [x] 8.1 `php artisan test --compact --filter=Complaint` (solo run, host php) — 20 passed (59 assertions).
- [x] 8.2 `pnpm lint && pnpm build` in `frontend/` — 0 errors (46 pre-existing warnings elsewhere, none in `complaint`); build succeeded, `ComplaintListPage`/`ComplaintDetailPage` chunks emitted.
- [x] 8.3 Confirmed sidebar "Denuncias" + `/complaint` reachable for a board role (DB + `MenuController` code inspection on the real dev DB, not just `testing`); verified design assumptions A2–A4 resolved. **Found and fixed a real gap**: batch 1's Phase 3.6 checklist edited the CSVs correctly but `php artisan db:seed --class=ResetPermissionTableSeeder` had never actually been run against the dev DB (`module`/`permissions`/`role_has_permissions` had zero Complaint rows) — ran it now (also re-ran `php artisan billing:recompute-entitlements` per the seeder's own follow-up requirement, 10 tenants re-projected cleanly). Confirmed via tinker: `Denuncias` module row exists (icon `IconAlertTriangle`, route `/complaint`, `permission_entity=complaint`, `visible=true`), president role has `create.complaint`/`read.complaint`, resident role does not have `create.complaint`, module assigned to the `Fraccionamiento` business system (not Core, not gated by `moduleEnabled` — confirms A4 core-module decision).
- [x] 8.4 `sdd-verify` against `spec.md` scenarios; run `/review-stack` (laravel-architecture-reviewer, react-frontend-reviewer, integration-reviewer, tenancy-reviewer) — this diff is hot-path/>400 lines, so full 4R applies at review/start. **Orchestrator-owned, not this batch.**

## Review fixes (batch 4)

Consolidated fixes from 5 reviewers (laravel-architecture, react-frontend, integration, tenancy, infra-cost) applied against `backend/Modules/Complaint/**`, `frontend/src/modules/complaint/**`, and the module-config slice.

**Critical**
- [x] C1 — `ComplaintController` had no exclusion scope on update/destroy/restore (only index/show). Added `buildUpdateFetchQuery`/`buildDestroyFetchQuery`/`buildRestoreFetchQuery` overrides applying `->visibleTo()`. New test `ComplaintExclusionTest::test_excluded_manager_cannot_update_or_destroy_the_complaint` (PUT/DELETE by excluded manager → 404).
- [x] C2 — `ComplaintListPage.test.tsx` mock selector typed `(s: object) => unknown` broke `tsc -b` (TS2345). Retyped as `(state: IEntityStore<IComplaint>) => unknown`. `pnpm build` verified green.
- [x] C3 — `ComplaintListPage` desktop `TableRow` had `cursor-pointer` but no `onClick` (the shared `ResponsiveDataTable`'s `onRowClick` prop only wires the mobile card branch). Added explicit `onClick={() => navigate(...)}` on the `TableRow`, matching `PaymentListPage` precedent.

**Hard-rule rename**
- [x] R1 — Renamed identifier `folio` → `case_number` everywhere (CLAUDE.md rule 6, English-only identifiers): migration column/comment/index, `Complaint::$fillable`, `ComplaintController::searchableBy`, `ComplaintService::nextFolio` → `nextCaseNumber` (+ notify-event context key `folio` → `case_number`, matching the `Payment`/`receipt_number` precedent), all 6 backend test files, frontend `IComplaint`, pages, and tests. Applied `ALTER TABLE complaint RENAME COLUMN folio TO case_number` + rebuilt the partial unique index as `complaint_case_number_tenant_unique` on the dev DB so file and DB agree. UI label stays "Folio" in Spanish (unchanged placeholder/tooltip copy). `spec.md`/`design.md` updated for backtick-wrapped identifier mentions only; prose "folio" (the human/UI concept) left as-is, mirroring the `receipt_number` precedent.

**Warnings**
- [x] W1 — DRY: extracted `Modules\Complaint\Support\ComplaintAccess` (`managerRoleKeys()`, `excludedUserIds(?string $unitId)`) as the single source of truth, removing the byte-identical duplicates from `ComplaintService` and `ComplaintMessageService`. `Complaint::scopeVisibleTo()` deliberately NOT folded in — it queries the opposite direction (caller's own units, for bulk index-query efficiency) and doesn't depend on manager role keys at all, so literal reuse wasn't a fit.
- [x] W2 — Removed the `evidence` rule from `ComplaintUpdateRequest` (update has no attach hook; a validated file was silently discarded) + a comment explaining why.
- [x] W3 — `ComplaintController::messages` capped to the latest 100 (`reorder('created_at','desc')->limit(100)`, then re-sorted ascending in PHP for display order).
- [x] W4 — Added `abort_unless(tenancy()->initialized, 500, ...)` at the top of `ComplaintService::register()`, protecting the tenant-scoped `complaint_sequence` counter.
- [x] W5 — `ComplaintResource.ts`: `create`/`transition`/`assign`/`postMessage`/`messages` now pass `skipPermissionsModal: true` (403s here are business rules — reporter/manager role config or the confidentiality exclusion — not coarse permission gaps). `create` overrides the generic `createCrudApi` method since it has no config passthrough.
- [x] W6 — New `GET /complaint/{id}/eligible-assignees` (`ComplaintController::eligibleAssignees` + `ComplaintService::eligibleAssignees`): configured managers minus the unit-linkage exclusion set. `AssignmentControl` in `ComplaintDetailPage` switched from `UserResource.searchByTerm` (any tenant user) to this endpoint, lazy-loaded on menu open. New test `ComplaintAssignmentTest::test_eligible_assignees_excludes_the_denounced_unit_manager`.
- [x] W7 — `permissions.csv` was missing BOTH `restore` and `report` for `complaint_category`/`complaint_priority` (not just `restore` — the 6-permission convention per CLAUDE.md rule 3 requires both). Added all 4 rows; re-ran `ResetPermissionTableSeeder` + `billing:recompute-entitlements` on the dev DB.
- [x] W9 — `ComplaintController::afterShow` now appends the tenant's `complaint.resolution_days_target` (memoized per request) to the detail payload. `ComplaintDetailPage`'s `agingClass()` now derives its amber/red thresholds from it (`> ceil(target/2)` amber, `> target` red) instead of hardcoded 15/7. `ComplaintConfigSection` tooltip copy left unchanged — it already described this exact behavior (previously aspirational, now actually wired).
- [x] W8 — Added Spanish `.max()` messages to `schema.ts` (`title`, `against_description`, `complaintMessageSchema.body`) and aligned `category`/`priority` schema.ts `.max()`/`.min()` with their backend FormRequests (`max:100` name, `max:20` color).

**Minor**
- [x] M1 — Explicit `authorize(): bool { return true; }` added to the 4 catalog FormRequests (`ComplaintCategoryCreateRequest`, `ComplaintCategoryUpdateRequest`, `ComplaintPriorityCreateRequest`, `ComplaintPriorityUpdateRequest`).
- [x] M2 — Confirmed `NotifiesResidents::notifyEvent()` already defers via `DB::afterCommit()` internally (`notifyUsers()`); added a clarifying class-level comment in both services instead of double-wrapping.
- [x] M3 — Added `->index()` for `assigned_user_id`/`reporter_user_id` in the migration + matching `CREATE INDEX IF NOT EXISTS` on the dev DB.
- [x] M4 — Wrapped the lone `Asignación` `DetailField` in a `<dl>` (was a bare `dt`/`dd` pair). Removed the `target!` non-null assertion in `TransitionControl` by passing the status id as the `mutate()` argument instead of closing over state.
- [x] M5 — `ComplaintConfigSection`: `resolution_days_target` typed `number | undefined` (dropped the `undefined as unknown as number` cast), added `buildSavePayload()` to explicitly drop `undefined` edit keys before PUT, added an `isError` state (Spanish message + retry button calling `refetch()`).
- [x] M6 — Moved the `ComplaintSettings` interface out of `ComplaintConfigSection.tsx` into `frontend/src/modules/complaint/schema.ts` as `IComplaintSettings` (project convention: `I` + PascalCase) and imported it.
- [x] M7 — `ComplaintListPage`: removed the redundant `(s.name as string | undefined) ?? s.id` casts (catalog `ICatalogItem.name` is already `string`). `ComplaintForm`: wired `evidenceRef` into `resetForm()` (native file inputs are uncontrolled) and unified the error-toast fallback to Spanish (`'Error al guardar'` instead of `'Error'`). `category`/`priority` `schema.ts`: dropped the redundant `tenant_id?: string` (already on `IEntity`).

**Verification (verbatim)**
- Backend: `php artisan test --compact --filter=Complaint` → **22 passed (69 assertions)**, 62.34s, single host-php run.
- `vendor/bin/pint --dirty --format agent` → `{"result":"passed"}`.
- Frontend: `pnpm typecheck` → clean. `pnpm lint` → **0 errors**, 46 pre-existing warnings (unchanged files, none in `complaint`). `pnpm build` → succeeded in 3.02s, `ComplaintListPage`/`ComplaintDetailPage` chunks emitted, PWA precache 299 entries. `pnpm vitest run src/modules/complaint` → **4 files, 26 tests, all passed**.
