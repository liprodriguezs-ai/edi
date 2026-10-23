# Tasks: EDI Appointment Scheduling

Change: `edi-appointment-scheduling`

## Review Workload Forecast (informative — no-git working tree)

Delivery: **user directive = work in the tree WITHOUT git.** No commits, no PRs, no chained-PR planning. The four guard lines below are the contract, set for a no-PR run; the per-phase table is a size estimate only.

Decision needed before apply: Yes
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: High

The single decision before apply is the **A1–A5 assumptions confirmation + user OK** (gate below), NOT a PR/size decision — no reviewer line budget applies without git.

| Phase | Scope | Est. authored (± tests) | Focused test command | Rollback boundary |
|---|---|---|---|---|
| 1 | Schema + FSM catalog + walk-in compat | ~250 + ~90 | `... test --compact Modules/ChildRecord/tests` | visit migration + VisitStatus + factory |
| 2 | Availability tables + slots + clash DB layer | ~430 + ~200 | `... test --compact Modules/Clinic/tests` | Clinic tables/service (indep. of FSM) |
| 3 | Lifecycle + endpoints + role/permissions | ~600 + ~300 | `... test --compact Modules/ChildRecord/tests Modules/Screening/tests` | lifecycle service + routes + seeders |
| 4 | Reminders + notifications | ~160 + ~120 | `... test --compact Modules/ChildRecord/tests` | command + events (additive) |
| 5 | Frontend calendar module | ~900 + ~120 | `pnpm exec tsc ... && pnpm exec vitest run` | `modules/appointment/**` (new dir) |
| 6 | Availability UI + coexistence | ~450 + ~100 | `pnpm exec tsc ... && vitest run && pnpm build` | `modules/availability/**` + guarded edits |

Total ≈ **2,800 authored + ~930 test lines**. Big change; phased so each phase is green before the next. No PR slicing planned (no git).

Paths relative to `/Users/rodriguez/code/edi-app/`. **Backend** tests from `backend/`: `DB_HOST=127.0.0.1 DB_PORT=5451 DB_USERNAME=rodriguez DB_PASSWORD=password php artisan test --compact <path>`; format `./vendor/bin/pint --dirty`. **Frontend** from `frontend/`: `pnpm exec tsc -p tsconfig.app.json --noEmit && pnpm exec vitest run && pnpm build`. Reproduce any `--parallel` flake in series before believing it (AGENTS.md §5). Do NOT edit `Modules/Screening/app/Services/Scoring/**`; do NOT touch `visit_tenant_receipt_unique` or the `visit_receipt_sequence` locking (design §9).

---

## Phase 1 — Schema + FSM catalog + walk-in compat  (REQ-APT-001, REQ-APT-003 schema, REQ-CH-008 · D2/D3/D13)

- [ ] 1.1 Migration `Modules/ChildRecord/database/migrations/..._add_appointment_fields_to_visit_table.php`: add the 11 columns (design §3 table) each with `->comment()`; alter `receipt_number` + `visit_date` → `nullable()->change()`; add range indexes `(tenant_id, practitioner_id, scheduled_at)` and `(tenant_id, visit_status_id, scheduled_at)`.
- [ ] 1.2 Same migration: backfill `visit_status_id = VisitStatus::ATTENDED` and `attended_at = created_at` on every existing row, THEN `visit_status_id` → NOT NULL; `down()` restores NOT NULL on `receipt_number`/`visit_date`.
- [ ] 1.3 Migration `..._create_visit_status_table.php`: `BaseModel::columns($table, false)`, table comment in-closure, `key/name/sort_order` each `->comment()`, partial unique on `key WHERE deleted_at IS NULL` via `DB::statement`.
- [ ] 1.4 Model `Modules/ChildRecord/app/Models/VisitStatus.php` extends `CentralCatalogModel`, fixed UUID consts `SCHEDULED/CONFIRMED/ATTENDED/CANCELLED/NO_SHOW = ed600001-0000-4000-8000-000000000001..0005`. No PHP enum.
- [ ] 1.5 `Visit.php`: add `visit_status()` belongsTo + lifecycle-timestamp casts; keep `visit_status_id` and all stamps OUT of `$fillable` (service-only writes — REQ-APT-001 "forged fields ignored").
- [ ] 1.6 `CatalogSeeder::seedVisitStatuses()` (5 rows via `upsertCatalog`); append `visit_status` to `$centralCatalogs` in `RolePermissionTableSeeder`.
- [ ] 1.7 `VisitService`: extract public `assignReceiptNumber(Visit $visit, int $year)` from the current folio code; `create()` (walk-in) `forceFill` `visit_status_id = ATTENDED` + `attended_at` (folio-at-create contract unchanged).
- [ ] 1.8 `VisitFactory`: default state `attended` (folio + `visit_date` + status ATTENDED, today's shape) + new `scheduled()` state (`scheduled_at` future, NULL folio/date).
- [ ] 1.9 Test `VisitStatusCatalogSeedTest`: exactly 5 central rows, no `tenant_id`, exact keys/UUIDs; tenant-role write → 403 (REQ-APT-001 "backfill preserves history").
- [ ] 1.10 Test `VisitCompatTest`: historic rows are `attended` with folio + `visit_date` intact and `scheduled_at` NULL; N NULL-folio rows coexist under `visit_tenant_receipt_unique` (REQ-APT-003, REQ-CH-008).
- [ ] 1.11 Checkpoint: `migrate:fresh --seed` green; `... test --compact Modules/ChildRecord/tests` green; full baseline (737) green UNEDITED (regression contract D13); `pint --dirty` clean.

## Phase 2 — Availability + slots + double-booking DB layer  (REQ-AVL-001, REQ-AVL-002, REQ-AVL-003 · D4/D5)

- [ ] 2.1 Migration `practitioner_schedule` (Clinic): `BaseModel::columns($table, true)`, table comment in-closure, `practitioner_id`/`health_center_id` uuid idx, `weekday` smallint (comment "0=Sunday…6=Saturday Carbon dayOfWeek"), `start_time`/`end_time` time, `slot_duration_minutes` smallint, `is_active` bool default true; partial unique `(tenant_id, practitioner_id, weekday, start_time) WHERE deleted_at IS NULL`.
- [ ] 2.2 Migration `practitioner_schedule_exception` (Clinic): tenant cols, `date`, `type` string (`block`|`open` via class consts + TYPES array — no PHP enum), nullable `start_time`/`end_time` (NULL pair = whole day), nullable `reason`; index `(tenant_id, practitioner_id, date)`.
- [ ] 2.3 Migration (visit, additive) partial unique `visit_practitioner_slot_unique ON visit (tenant_id, practitioner_id, scheduled_at) WHERE deleted_at IS NULL AND scheduled_at IS NOT NULL AND visit_status_id IN (SCHEDULED, CONFIRMED)` via `DB::statement` (REQ-AVL-003 exact-start race layer).
- [ ] 2.4 Models `PractitionerSchedule`, `PractitionerScheduleException` (`BelongsToTenant`, relations to `practitioner_profile`/`health_center`) + factories.
- [ ] 2.5 `Modules/Clinic/app/Services/AvailabilityService.php` `availableSlots(practitionerId, date)`: weekday active windows sliced by `slot_duration_minutes` − `block` + `open` windows − overlapping active (`scheduled|confirmed`) appts − past-today slots (tenant tz); empty when no schedule/open (REQ-AVL-002).
- [ ] 2.6 `AvailabilityService::assertNoClash(...)`: `lockForUpdate` on the practitioner's active appts in the bounding window, per-row-duration overlap → throw 409 `APPOINTMENT_CLASH` (REQ-AVL-003 overlap layer; consumed in Phase 3).
- [ ] 2.7 `PractitionerScheduleController` + `PractitionerScheduleExceptionController` (both `$keyForPermission = 'practitioner_schedule'`) + 4 FormRequests (`end_time` after `start_time`, tenant-scoped `exists`); `Modules/Clinic/routes/api.php`: 2 `Orion::resource` + `GET practitioner/{practitioner}/slots?date=` (`read.practitioner_schedule`).
- [ ] 2.8 Unit `AvailabilityServiceTest`: slot slicing, block/open math, overlap predicate, past-today drop (REQ-AVL-002 "subtraction algorithm" → 09:00 & 10:30).
- [ ] 2.9 Feature `AvailabilitySlotsTest` (endpoint + 403 without `read.practitioner_schedule`) + `PractitionerScheduleCrudTest` (CRUD + cross-tenant 404 + shared permission key — REQ-AVL-001).
- [ ] 2.10 Checkpoint: `... test --compact Modules/Clinic/tests` green; `pint --dirty` clean.

## Assumptions confirmation gate — SOFT-BLOCK before Phase 3

- [ ] G.1 Confirm/correct **A1–A5** with the user before starting Phase 3 (each is a one-line change): **A1** reminder lead 24h + hourly window; **A2** default duration = `slot_duration_minutes` (overridable per appointment); **A3** `no_show` manual only (no cron); **A4** reschedules uncapped (counted only); **A5** assistant scope tenant-wide (permission-based). Encode confirmations; if any changes, adjust the touched task before coding it.

## Phase 3 — Lifecycle + endpoints + role/permissions  (REQ-APT-002, REQ-APT-003, REQ-APT-004, REQ-CH-008, REQ-SCR-017 · D6/D7/D10/D11)

- [ ] 3.1 `Modules/ChildRecord/app/Exceptions/VisitDomainException.php` (mold `FollowUpDomainException`, self-rendering `ShouldntReport`): `frozen()`→409 `VISIT_FROZEN`, `invalidStatusTransition()`→409 `INVALID_STATUS_TRANSITION`, `clash()`→409 `APPOINTMENT_CLASH`.
- [ ] 3.2 `Modules/ChildRecord/app/Services/VisitLifecycleService.php` (mold `FollowUpLifecycleService`): `schedule/confirm/reschedule/cancel/noShow/attend`, each `DB::transaction` + `lockForUpdate` re-read + `assertOpen` (frozen-first precedence) + transition check + `forceFill`. `attend` sets `visit_date`/`attended_at` + folio via `assignReceiptNumber`; `schedule`/`reschedule` call `assertNoClash`; `reschedule` copies `scheduled_at`→`rescheduled_from_at`, `reschedule_count++`, resets to `scheduled`; `noShow` rejects before scheduled date → 422 `NO_SHOW_BEFORE_DATE`.
- [ ] 3.3 Requests `VisitScheduleRequest` (`scheduled_at after:now`, tenant `exists`, birth-date guard), `VisitRescheduleRequest`, `VisitCancelRequest` (`reason` required), `VisitAttendRequest` (`visit_date before_or_equal:today` + birth-date guard — rule MOVES here from create). `VisitCreateRequest`/`VisitUpdateRequest` contract unchanged (walk-in).
- [ ] 3.4 `Modules/ChildRecord/routes/api.php`: keep `Orion::resource('visit')`; add `POST visit/schedule` (`create.visit`), `POST visit/{visit}/confirm|reschedule|cancel|no_show` (`update.visit`), `POST visit/{visit}/attend` (`attend.visit`). `VisitController`: 6 action methods (`response()->json(['message','data'])`), `includes()` += `visit_status`,`cancelled_by_user` (`withoutGlobalScope(UserTenantScope::class)`); `filterableBy` += `visit_status_id`,`scheduled_at`; `sortableBy` += `scheduled_at`.
- [ ] 3.5 Role `assistant`: `database/seeders/csv/roles.csv` row (Asistente); `core/src/Models/Role.php` consts `ASSISTANT='1a2b3c4d-000b-0000-0000-000000000011'` + `SLUG_ASSISTANT='assistant'` appended to `ORGANIZATION_SLUGS`.
- [ ] 3.6 New fine permission `attend.visit`; `RolePermissionTableSeeder` assistant block = `read.{child,health_center,practitioner_profile,visit,visit_status,practitioner_schedule}` + `create.visit`,`update.visit`,`report.visit` — NO `attend.visit`/`delete.visit`/`practitioner_schedule` writes; `administrator` gets `attend.visit` (REQ-APT-004).
- [ ] 3.7 Harden `Modules/Screening/app/Http/Requests/ScreeningCreateRequest.php`: referenced visit `visit_status` MUST be `attended` → 422 `VISIT_NOT_ATTENDED` (REQ-SCR-017). Nothing else in Screening changes.
- [ ] 3.8 Registration (AGENTS.md rule 9): `modules.csv` (Agenda, Disponibilidad), `permissions.csv` (`attend.visit` + 6× `practitioner_schedule`), `module_system.csv` (2 rows), `SystemTableSeeder` 5 maps (`'Agenda'=>'visit'`, `'Disponibilidad'=>'update:practitioner_schedule'`, routes `/appointment`,`/availability`), `AngularController::$mapperSelects` += `'VisitStatus'`,`'PractitionerSchedule'`.
- [ ] 3.9 **Apply-time free-value verification** (design D11/D12 — resolve, do not guess): (a) next free ids + exact menu group in `modules.csv`; (b) calendar icon name EXISTS in `frontend/src/shared/utils/iconMap.ts`; (c) exact `PermissionRoute` prop the catalog pages use with the `update:` prefix (mirror the ChildRecord catalog-page gating).
- [ ] 3.10 RED-first security tests: `VisitLifecycleTest` (full matrix; invalid live → 409 `INVALID_STATUS_TRANSITION`; terminal → 409 `VISIT_FROZEN` precedence; forged `visit_status_id`/stamps ignored; reschedule count; `no_show`-before-date 422), `VisitScheduleTest`/`VisitClashTest` (`after:now`, exists-in-tenant, exact-start race → one 409, partial overlap 409, cancellation frees slot), `VisitAttendFolioTest` (folio only at attend, gap-free after cancel, N NULL coexist, concurrent attends distinct), `VisitPermissionsTest` (assistant 403 on attend + availability writes + delete; administrator full), `ScreeningCreateRequest` non-attended → 422.
- [ ] 3.11 Checkpoint: `... test --compact Modules/ChildRecord/tests Modules/Screening/tests` green; `pint --dirty` clean.

## Phase 4 — Reminders + notifications  (REQ-APT-005, REQ-APT-006 · D8/D9)

- [ ] 4.1 4 `NotificationEvent` rows fixed UUID `ed600002-…0001..0004` (`appointment.created/rescheduled/cancelled/reminder`), `NotificationCategory::APPOINTMENT='appointment'`, `supports_email=false`, `supports_push=true`, `default_channels=['log','firebase_push']`; seeded in `CatalogSeeder::seedNotificationEvents()`.
- [ ] 4.2 Emit events from `VisitLifecycleService` via `notifyEvent` afterCommit: `schedule`→created, `reschedule`→rescheduled, `cancel`→cancelled. Recipients = practitioner's user + `userIdsWithRole('administrator'|'assistant')`.
- [ ] 4.3 `Modules/ChildRecord/app/Console/SendAppointmentRemindersCommand.php` (`appointment:send-reminders`, mold `RefreshOverdueFollowUpsCommand`): per tenant select `scheduled|confirmed` with `reminder_sent_at IS NULL AND scheduled_at > now() AND scheduled_at <= now()+lead`, fire `notifyEvent('appointment.reminder')`, stamp `reminder_sent_at` same transaction. Lead = `config('childrecord.appointment_reminder_lead_hours')` (default 24). Register + `Schedule::command(...)->hourly()` in `routes/console.php`.
- [ ] 4.4 Tests `AppointmentReminderCommandTest` (window edges; idempotent second run selects 0 — `reminder_sent_at`; terminal excluded; per-tenant isolation) + notification dispatch/opt-out per event (tenant `NotificationSetting` deactivation suppresses record + dispatch — REQ-APT-006).
- [ ] 4.5 Checkpoint: `... test --compact Modules/ChildRecord/tests` green; full backend suite green in series (regression); `pint --dirty` clean.

## Phase 5 — Frontend calendar module  (REQ-APT-007 · D12)

- [ ] 5.1 Install `@schedule-x/drag-and-drop` pinned `4.6.0` (match calendar; `frontend/package.json`) — NOT currently installed; NOT `@dnd-kit`.
- [ ] 5.2 Extend `frontend/src/modules/visit/schema.ts` `IVisit`: `scheduled_at`, `duration_minutes`, `visit_status`/`visit_status_id`, cancellation fields; `receipt_number`/`visit_date` → nullable. Zero `any`.
- [ ] 5.3 `modules/appointment/services/AppointmentResource.ts`: `createCrudApi` over `/visit` with `customMethods` `schedule/confirm/reschedule/cancel/noShow/attend/slots/agenda` (existing `VisitResource` untouched).
- [ ] 5.4 `modules/appointment/utils/scheduleXAdapter.ts`: centralize UTC ISO ↔ Schedule-X local `'YYYY-MM-DD HH:mm'` (date-fns) + `visit_status.key` → calendar/color (indigo/neutral ONLY; g/y/r semaphore stays reserved for clinical results).
- [ ] 5.5 `hooks/useAppointmentCalendar.ts` (`@schedule-x/react` `useCalendarApp`, day/week/month, events service, TanStack Query keyed by visible range via `POST /visit/search` range filters + includes) + `AppointmentCalendarPage.tsx`.
- [ ] 5.6 Components `ScheduleAppointmentSheet` + `SlotPicker` (slots query), `AppointmentDetailPanel` (permission-gated actions), `RescheduleDialog` (NATIVE `@schedule-x/drag-and-drop`, optimistic update + rollback on 409), `CancelDialog` (reason required), `AppointmentStatusBadge`.
- [ ] 5.7 Theme Schedule-X inside the ONE marked `.sx__calendar-wrapper` block in `index.css` (~L383): sharp corners (`radius 0` on `.sx__event`/popups), white surfaces, mono hours, indigo primary — all overrides in that single section (one place to audit on version bumps).
- [ ] 5.8 Registration: `App.tsx` route `/appointment` under `PermissionRoute` gated by `visit`; `useCatalogs.ts::CatalogType` += `VISIT_STATUS`, `PRACTITIONER_SCHEDULE` (design D11 — NO `appointment_type` catalog exists in this change, so no `APPOINTMENT_TYPE`); calendar icon in `iconMap` if missing.
- [ ] 5.9 Vitest: `scheduleXAdapter` TZ/color tests + `IVisit` schema parse + calendar/detail smoke with mocked API. Closure: `pnpm exec tsc -p tsconfig.app.json --noEmit` (0), `pnpm exec vitest run` green from 221 baseline, `pnpm build`.

## Phase 6 — Availability UI + coexistence  (REQ-APT-007, REQ-SCR-017 picker · D13)

- [ ] 6.1 `modules/availability/AvailabilityConfigPage.tsx` (custom weekly editor per practitioner+center: 7 days × active/start/end/slot-duration + date blocks) + `ScheduleResource`/`ScheduleExceptionResource` (`/practitioner_schedule`, `/practitioner_schedule_exception`); `App.tsx` route `/availability` gated by the `practitioner_schedule` write convention.
- [ ] 6.2 `ChildDetailPage` (visits tab) + `VisitListPage`: split "Próximas citas" (by `scheduled_at`) from "Historial" (attended, by `visit_date`), add `AppointmentStatusBadge`.
- [ ] 6.3 Audit every `IVisit` consumer to tolerate NULL `visit_date` (worklist sort, `displayFormatDate`, labels) — no crashes on unattended rows.
- [ ] 6.4 `ApplyVisitPickerDialog`: filter list + `searchByTerm` to `attended` visits only (screening hangs only from attended — REQ-SCR-017); "Marcar atendida" links to the existing `/screening/apply/{visitId}` CTA.
- [ ] 6.5 Closure: `pnpm exec tsc -p tsconfig.app.json --noEmit && pnpm exec vitest run && pnpm build` green.
- [ ] 6.6 Live (Playwright): as administrator — define availability → schedule a free slot → slot disappears → confirm → drag-reschedule → attend → "Aplicar tamizaje"; as `assistant` — schedule/cancel OK but attend hidden + endpoint 403; reminder command dry-run stamps `reminder_sent_at`; Schedule-X theming visual check.
