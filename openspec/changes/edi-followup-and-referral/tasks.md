# Tasks: EDI Follow-Up and Referral

Change: `edi-followup-and-referral`

## Delivery forecast (review workload guard)

Decision needed before apply: Yes
Chained PRs recommended: Yes
400-line budget risk: High

Roughly ~1,600 authored backend lines (5 migrations, 5 models, event + listener, 3 services, command, 2 controllers, 5 requests, seeders/registration) + ~700 frontend lines, plus comparable test volume. Recommended chain (each slice autonomous, verifiable, revertible): **PR1** = Phases 0–2 (catalogs + tables + event + listener + overdue command — the domain core, API-less but fully tested), **PR2** = Phases 3–4 (API + notification + registration), **PR3** = Phase 5 (frontend module + catalog page). Resolve open decisions Q1–Q6 (`design.md` §8) before Phase 1.

All paths relative to `/Users/rodriguez/code/edi-app/`. Backend tests run from `backend/` with the host command in `openspec/config.yaml`; reproduce any parallel-flake in series before believing it (AGENTS.md §5). Do NOT create git commits from this pipeline. Do NOT modify `Modules/Screening/app/Services/Scoring/**` or `RuleSetLoading/**`.

---

## Phase 0 — Module skeleton + catalogs

- [x] 0.1 Create the `FollowUp` nwidart module skeleton (`backend/Modules/FollowUp/`: `app/`, `database/migrations/`, `routes/api.php`, `module.json`, provider) following the Clinic/Screening structure; add `"FollowUp": true` to `backend/modules_statuses.json`.
- [x] 0.2 Migrations for `follow_up_type` and `follow_up_status` — `BaseModel::columns($table, false)`, table comment inside the closure, `->comment()` on every own column, partial unique on `key`.
- [x] 0.3 Migration for `referral_specialty` — `BaseModel::columns($table, true)` + partial unique `(tenant_id, name) WHERE deleted_at IS NULL`.
- [x] 0.4 Models `FollowUpType` (constants `ed500001-…0001..3`), `FollowUpStatus` (constants `ed500002-…0001..5`), `ReferralSpecialty` (`BelongsToTenant`). No PHP enums. **Deviation from design.md**: the design's proposed `ed400001-…`/`ed400002-…` prefixes collided with UUIDs already claimed by the Stimulation module (SessionSection/StimulationArea, built in parallel) — moved to unused `ed500001-…`/`ed500002-…` (module-number-5 convention, matching ed1=ChildRecord/ed2=Instrument/ed3=Screening/ed4=Stimulation).
- [x] 0.5 `CatalogSeeder`: `seedFollowUpTypes()` (3 rows) + `seedFollowUpStatuses()` (5 rows) via `upsertCatalog`; `TenantCatalogSeeder::CATALOGS` += `ReferralSpecialty::class` with the 6 default names (design §3).
- [x] 0.6 Feature test `FollowUpCatalogSeedTest`: central keys/UUIDs exact and tenant-free; new tenant receives the 6 specialties; `firstOrCreate` never overwrites an edited row (REQ-FUP-001).

## Phase 1 — Transactional tables, event, listener (the domain core)

- [x] 1.1 Migrations `follow_up` + `referral` per design §3 (tenant-scoped): inbox index `(tenant_id, follow_up_status_id, due_date)`, index `(tenant_id, child_id)`, partial unique `(screening_id, follow_up_type_id) WHERE deleted_at IS NULL` via `DB::statement`.
- [x] 1.2 Models `FollowUp` (`$fillable` EXCLUDES `follow_up_status_id`, `closed_by_screening_id`, `completed_at` — REQ-FUP-006), `Referral`; snake_case single relations (`child()`, `screening()`, `follow_up_type()`, `follow_up_status()`, `referral_specialty()`, `closed_by_screening()`), plural `referrals()`.
- [x] 1.3 `Modules/Screening/app/Events/ScreeningCompleted.php` (readonly `Screening` payload) + dispatch line in `ScreeningLifecycleService::complete()` inside the transaction after the status flip (REQ-SCR-016). Nothing else in that file changes.
- [x] 1.4 `Modules/FollowUp/app/Services/FollowUpCreationService.php`: color matrix + recurrence via frozen `previous_result_color_id` (D3), due dates from `visit.visit_date` (D4), write-time status derivation (D7), create-if-missing on `(screening_id, follow_up_type_id)` (D2).
- [x] 1.5 `Modules/FollowUp/app/Listeners/CreateFollowUpsFromScreening.php`: auto-close open `reassess_3_months` of the child (visit-date guard, `closed_by_screening_id`, `completed_at` — D5) THEN delegate creation to 1.4; registered `ScreeningCompleted => listener` in `FollowUpEventServiceProvider`.
- [x] 1.6 Feature tests: `ScreeningCompletedEventTest` (in `Modules/Screening/tests/Feature/` — fired once on complete, absent on score/rescore/invalidate), `FollowUpCreationFromScreeningTest` (matrix: red→2, first yellow→1, recurrent yellow→2, green→0; due dates; forced listener failure rolls back completion), `FollowUpIdempotencyTest` (double dispatch no-op), `FollowUpAutoCloseTest` (posterior closes; backfilled older visit does not; other types/children untouched; second yellow closes old and opens new).

## Phase 2 — Overdue command

- [x] 2.1 `Modules/FollowUp/app/Console/RefreshOverdueFollowUpsCommand.php` (`follow-up:refresh-overdue`): iterate tenants, initialize each, converge `pending ↔ overdue` from `due_date` vs today (both directions), never touch `in_progress`/`completed`/`cancelled` (D7). Registered in the module provider; scheduled `->dailyAt('00:15')` in `backend/routes/console.php`.
- [x] 2.2 Feature test `FollowUpOverdueCommandTest`: flips both directions; immediate second run changes zero rows; manual states untouched; tenant A run never touches tenant B rows (REQ-FUP-005, REQ-FUP-010).
- [x] 2.3 Checkpoint: `migrate:fresh --seed` green; `DB_HOST=127.0.0.1 DB_PORT=5451 DB_USERNAME=rodriguez DB_PASSWORD=password php artisan test --compact Modules/FollowUp/tests Modules/Screening/tests` green (167 passed).

## Phase 3 — API + notification

- [x] 3.1 `FollowUpLifecycleService` (start/complete/cancel with `lockForUpdate` + status re-check, terminal freeze 409 `FOLLOW_UP_FROZEN`, `cancellation_reason` mandatory) and `ReferralService` (type gate 422 `FOLLOW_UP_TYPE_MISMATCH`, `in_progress` side effect, D11).
- [x] 3.2 `FollowUpController` + `ReferralController` (+ `ReferralSpecialtyController` for the tenant catalog) extend `QCH\Core\Http\Controllers\Controller`, `$model`, `$keyForPermission`, `includes()` allowlist, `searchableBy()`); FormRequests per design §4 (manual `store` validates completed screening of the same child → 422 `SCREENING_NOT_ELIGIBLE`; tenant-scoped `exists` everywhere); routes per design §4 incl. the 3 transition endpoints and `Orion::resource('referral_specialty', ...)`. **Deviation from design.md**: URL segments are snake_case (`follow_up`, `referral_specialty`), matching every other Orion resource route in this codebase — same deliberate deviation already documented in Screening's `routes/api.php` for `confirmatory_test` (design.md spelled two of them with a hyphen).
- [x] 3.3 Notification wiring (D8): `NotificationCategory::FOLLOW_UP` constant; `NotificationEvent::REFERRAL_CREATED` constant (`ed500003-0000-4000-8000-000000000001`, same deviation as 0.4) + row in `CatalogSeeder::seedNotificationEvents()` (`key = 'follow_up.referral_created'`, channels/flags per design §3); `ReferralService` dispatches `notifyEvent` to `userIdsWithRole('administrator')` with Spanish fallback title/body (child name + specialty). No new channel, no email template.
- [x] 3.4 Feature tests: `FollowUpLifecycleTest` (transition matrix, forged `follow_up_status_id`/`closed_by_screening_id`/`completed_at` ignored, terminal 409s, update whitelist `due_date`/`notes`/`referral_specialty_id`, manual duplicate 422 `FOLLOW_UP_DUPLICATE`), `ReferralTest` (gate, side effect, N referrals, frozen 409), `ReferralNotificationTest` (recorded in-app for administrators with default channels; tenant opt-out via `NotificationSetting` suppresses; event key resolves — REQ-FUP-008), `FollowUpTenantIsolationTest` (cross-tenant 404; foreign ids rejected).

## Phase 4 — Registration

- [x] 4.1 Registration per design §6: `modules.csv` (3 rows `ed000005-…0001/0002/0003`, deviation from design's `ed000004-…` per 0.4's note — that prefix belongs to Stimulation), `permissions.csv` (18 rows), `module_system.csv` (Evaluación system, next sorts 54-56), `SystemTableSeeder` maps (entities incl. `'update:referral_specialty'`; routes `/follow-up`, `/referral-specialty`; icons verified against `frontend/src/shared/utils/iconMap.ts`; `Referencias` in `$hidden`), `RolePermissionTableSeeder` (18 permissions; `follow_up_type` + `follow_up_status` → `$centralCatalogs`). `migrate:fresh --seed` runs every seeder including `RolePermissionTableSeeder`, so no separate `ResetPermissionTableSeeder` call was needed locally.
- [x] 4.2 `AngularController::$mapperSelects` += `FollowUpType`/`FollowUpStatus`/`ReferralSpecialty` (design §6 — the string-coupled pair with 5.2).
- [x] 4.3 Feature test `FollowUpMenuRegistrationTest`: menu shows Seguimientos + Especialidades de Referencia and hides Referencias for `administrator`; 403 matrix without permissions (REQ-FUP-009).
- [x] 4.4 Backend closure: `./vendor/bin/pint --dirty` clean; full suite `DB_HOST=127.0.0.1 DB_PORT=5451 DB_USERNAME=rodriguez DB_PASSWORD=password php artisan test --parallel --processes=10` green from the 770 baseline (reproduce any flake in series per AGENTS.md §5).

## Phase 5 — Frontend

- [x] 5.1 `frontend/src/modules/followUp/schema.ts`: `IFollowUp`, `IReferral` (+ optional included relations typed from existing schemas — no local duplicates, no `IXxxResponse` wrappers), `followUpFormSchema` + `referralFormSchema` (Zod) + `FormValues` types. Zero `any`.
- [x] 5.2 `useCatalogs.ts::CatalogType` += `FOLLOW_UP_TYPE = 'follow_up_type'`, `FOLLOW_UP_STATUS = 'follow_up_status'`, `REFERRAL_SPECIALTY = 'referral_specialty'`; services via `createCrudApi` (`/follow_up`, `/referral`); store via `createEntityStore`.
- [x] 5.3 `FollowUpListPage` (`useBaseList`; status filter via `useLazyCatalogs` + `MultiSelectFilter onFirstOpen`; due-window quick filter — Vencidos / Próximos 7 días / Todos — mapped to server filters; default sort `due_date` asc; status badges; `displayFormatDate`; `boneyard-js` skeletons). Spanish UI copy.
- [x] 5.4 `FollowUpDetailPage` (data + type/status/child context, transition buttons with `sileo` toasts and confirm dialogs — cancel asks the mandatory reason; referrals list + `ReferralFormModal` loading catalogs on open) and `FollowUpFormModal` (manual create: child → completed screening → type → due date; catalogs load on open).
- [x] 5.5 `frontend/src/modules/catalogs/pages.tsx`: `ReferralSpecialtyPage = createSimpleCatalogPage({ title: 'Especialidades de Referencia', itemLabel: 'especialidad', entity: 'referral_specialty', ... })`; `App.tsx` lazy routes `/follow-up`, `/follow-up/:id` under `PermissionRoute permissionEntity='follow_up'` and `/referral-specialty` under `referral_specialty`.
- [x] 5.6 Vitest: schema parse/validation tests + list/detail smoke with mocked API. Closure: `pnpm exec tsc -p tsconfig.app.json --noEmit` (0 errors), `pnpm exec vitest run` green from the 280 baseline (289 passed, +9), `pnpm build` (succeeded).
- [x] 5.7 Updated `.project-context/modules.md` (FollowUp now built: tables, event listener, command, API, UI) and noted follow-ups deferred by design §8 (email channel Q4, invalidation cascade Q6, due-reminder notifications — unchanged from design, no new deferrals identified during apply).
