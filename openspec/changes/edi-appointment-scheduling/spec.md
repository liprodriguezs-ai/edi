# Spec: EDI Appointment Scheduling

Change: `edi-appointment-scheduling`
Capabilities: `appointment-scheduling` (new), `practitioner-availability` (new), `child-registry` (modified — delta), `screening-application` (modified — delta)

> RFC 2119 keywords (MUST, MUST NOT, SHALL, SHOULD, MAY) are normative.
> Status constants come from the new central `visit_status` catalog (`VisitStatus::SCHEDULED|CONFIRMED|ATTENDED|CANCELLED|NO_SHOW`).
> "Active appointment" = `visit_status` in {`scheduled`, `confirmed`}. "Terminal" = {`attended`, `cancelled`, `no_show`}.
> Error envelopes: `{ "message": string (Spanish UI), "code": UPPER_SNAKE constant, ...context }`.
> `scheduled_at` is stored UTC (timestamptz); "future", "today", and "past slot" comparisons are evaluated in the tenant's timezone (`tenant.timezone`, default America/Mexico_City).
> **Assumed defaults** (proposal question round — confirm/correct in `design.md` before `sdd-apply`): **A1** reminder lead time 24h before `scheduled_at`, hourly send window; **A2** default duration = `practitioner_schedule.slot_duration_minutes`, overridable per appointment; **A3** `no_show` is manual only (no auto-marking cron); **A4** reschedules uncapped, only counted; **A5** assistant scope is tenant-wide (permission-based, not ownership-based).
> On archive: REQ-APT-\* create `openspec/specs/appointment-scheduling/spec.md`, REQ-AVL-\* create `openspec/specs/practitioner-availability/spec.md`, the MODIFIED REQ-CH-008 replaces the same-numbered block in the `child-registry` spec, and REQ-SCR-017 appends to `screening-application`.

---

## ADDED Requirements — capability `appointment-scheduling`

### REQ-APT-001 — Persistence model and `visit_status` catalog

The system SHALL extend `visit` with an additive migration: `scheduled_at`, `duration_minutes`, `visit_status_id`, `confirmed_at`, `attended_at`, `cancelled_at`, `cancellation_reason`, `cancelled_by_user_id`, `reschedule_count` (default 0), `rescheduled_from_at`, `reminder_sent_at` — every column with `->comment()`. It SHALL create the CENTRAL FSM catalog `visit_status` (keys `scheduled`, `confirmed`, `attended`, `cancelled`, `no_show`; `BaseModel::columns($table, false)`, fixed UUID model constants, seeded once by `CatalogSeeder`, read-only for tenant roles). Every pre-existing visit MUST be backfilled to `attended` (keeping its folio and `visit_date`, `scheduled_at` NULL); `visit_status_id` becomes NOT NULL after backfill. Clients MUST NOT set `visit_status_id` or any lifecycle timestamp directly — injected values in create/update payloads are ignored; state changes happen ONLY through the endpoints of REQ-APT-002. New indexes MUST serve agenda-by-practitioner and agenda-by-status range queries on `scheduled_at` without per-row scans.

#### Scenario: backfill preserves history

- GIVEN a database with historic visits
- WHEN the migration and seeders run
- THEN every historic visit is `attended` with folio and `visit_date` intact and `scheduled_at` NULL, and `visit_status` has exactly 5 central rows (no `tenant_id`), rejected with 403 for tenant-role writes.

#### Scenario: forged lifecycle fields are ignored

- WHEN a client sends `visit_status_id`, `attended_at`, or `reminder_sent_at` in visit create/update payloads
- THEN every injected field is ignored and stored values are server-derived.

### REQ-APT-002 — Appointment lifecycle FSM

The system SHALL expose explicit lifecycle actions: `POST /api/visit/schedule` and `POST /api/visit/{id}/confirm|reschedule|cancel|no_show|attend`. Allowed transitions:

| Action | From | To | Contract |
|---|---|---|---|
| schedule | — (create) | scheduled | `scheduled_at` required, strictly in the future; `child_id`/`practitioner_id`/`health_center_id` required, same tenant; `receipt_number` and `visit_date` stay NULL |
| confirm | scheduled | confirmed | stamps `confirmed_at` |
| reschedule | scheduled, confirmed | scheduled | new future `scheduled_at`; `reschedule_count`+1; stores `rescheduled_from_at`; a confirmed appointment returns to `scheduled` and needs re-confirmation; re-runs the clash guard (REQ-AVL-003); uncapped (Assumption A4) |
| cancel | scheduled, confirmed | cancelled | `reason` mandatory → `cancellation_reason`; stamps `cancelled_at` and `cancelled_by_user_id` |
| no_show | scheduled, confirmed | no_show | manual only (Assumption A3); rejected with 422 `NO_SHOW_BEFORE_DATE` before the scheduled date (tenant timezone) |
| attend | scheduled, confirmed | attended | per REQ-APT-003 |

Any other action on an active appointment MUST return 409 `INVALID_STATUS_TRANSITION`. Terminal rows are frozen: any lifecycle action or mutating update MUST return 409 `VISIT_FROZEN`, and the frozen check takes precedence over transition validity.

#### Scenario: happy path chain

- GIVEN an appointment scheduled for a future slot
- WHEN it is confirmed and later attended
- THEN each step stamps its timestamp and the final state is `attended`.

#### Scenario: cancel requires a reason

- WHEN cancel is called without `reason`
- THEN the response is 422 and the appointment remains active.

#### Scenario: no-show only on or after the date

- GIVEN a confirmed appointment scheduled for tomorrow
- WHEN `no_show` is called today
- THEN the response is 422 `NO_SHOW_BEFORE_DATE`; AND on the scheduled date the same call succeeds.

#### Scenario: terminal freeze wins over transition validity

- GIVEN a `cancelled` appointment
- WHEN confirm, cancel, or reschedule is attempted
- THEN every response is 409 `VISIT_FROZEN` (never `INVALID_STATUS_TRANSITION`).

#### Scenario: invalid transition on a live row

- GIVEN a `confirmed` appointment
- WHEN confirm is called again
- THEN the response is 409 `INVALID_STATUS_TRANSITION`.

#### Scenario: reschedules are counted, not capped

- GIVEN a confirmed appointment
- WHEN it is rescheduled twice to free future slots
- THEN it ends `scheduled` with `reschedule_count = 2`.

### REQ-APT-003 — Folio and visit date are assigned at attend

`attend` SHALL atomically: validate `visit_date` (real encounter date, `before_or_equal:today` — this rule moves from creation to attend), set `visit_date`, stamp `attended_at`, and generate `receipt_number` from the official per-tenant per-year sequence (`V-YYYY-NNNNNN`, year of `visit_date`). Unattended appointments MUST NOT carry a folio; any number of NULL `receipt_number` rows coexist under the existing partial unique index. The issued sequence MUST be gap-free: cancelled and no-show appointments never consume a number.

#### Scenario: folio only on attend, NULLs coexist

- GIVEN three scheduled appointments in the same tenant
- WHEN one is attended
- THEN only it receives the next sequence number and the other two keep `receipt_number` NULL without violating uniqueness.

#### Scenario: cancellations burn no numbers

- GIVEN appointments A and B in tenant T
- WHEN A is cancelled and B attended
- THEN B receives the next consecutive number after T's last issued folio — no gap for A.

#### Scenario: concurrent attends yield no duplicates

- WHEN two attend calls race in the same tenant
- THEN both succeed with distinct consecutive numbers.

### REQ-APT-004 — Roles and permissions: `assistant` and `attend.visit`

A new fine-grained permission `attend.visit` SHALL gate `attend` — granted to `administrator`, NOT to `assistant`. Route gating: schedule → `create.visit`; confirm/reschedule/cancel/no_show → `update.visit`; attend → `attend.visit`; slots read → `read.practitioner_schedule`. A new Spatie role `assistant` SHALL be seeded with: read on child/health_center/practitioner_profile/visit/visit_status/practitioner_schedule, plus `create.visit`, `update.visit`, `report.visit`; and WITHOUT `attend.visit`, `delete.visit`, or `practitioner_schedule` writes. Assistant scope is tenant-wide (Assumption A5). Registration MUST complete the full checklist (roles/permissions CSVs, `RolePermissionTableSeeder` incl. `visit_status` as central catalog, `SystemTableSeeder` maps, `Role` constants, `$mapperSelects` + `CatalogType`).

#### Scenario: assistant operates the agenda

- WHEN an `assistant` schedules, confirms, reschedules, and cancels appointments of any practitioner in the tenant
- THEN every action succeeds.

#### Scenario: assistant cannot attend nor edit availability

- WHEN an `assistant` calls `attend` or writes `practitioner_schedule`
- THEN both responses are 403 and nothing changes; AND an `administrator` attending the same visit succeeds.

### REQ-APT-005 — Automatic reminders

An idempotent `appointment:send-reminders` command, scheduled hourly, SHALL per tenant select active appointments with `scheduled_at` inside the reminder window (default 24h lead — Assumption A1) and `reminder_sent_at` NULL, dispatch `appointment.reminder`, and stamp `reminder_sent_at` in the same unit of work — at most one reminder per appointment. Terminal and already-reminded appointments MUST NOT be selected.

#### Scenario: reminded exactly once

- GIVEN a confirmed appointment ~24h ahead with `reminder_sent_at` NULL
- WHEN the command runs twice back to back
- THEN the first run dispatches and stamps it; the second selects zero rows.

#### Scenario: terminal appointments excluded

- GIVEN a cancelled appointment inside the window
- WHEN the command runs
- THEN nothing is dispatched for it.

### REQ-APT-006 — Lifecycle notifications through the existing engine

`schedule`, `reschedule`, `cancel`, and the reminder command SHALL dispatch `appointment.created` / `appointment.rescheduled` / `appointment.cancelled` / `appointment.reminder` via the existing engine (`DispatchesNotifications`, after commit) — registered as `NotificationEvent` catalog rows (fixed UUID constants) with in-app + push default channels, `supports_email = false`, NO new channel. Recipients are login users: the appointment practitioner's user plus tenant users holding `administrator` or `assistant`. Per-tenant `NotificationSetting` deactivation MUST suppress recording and dispatch. Caregiver SMS/WhatsApp/email is out of scope.

#### Scenario: scheduling notifies the agenda actors

- WHEN an appointment is scheduled
- THEN the practitioner's user and the tenant's administrator/assistant users receive the in-app notification (and push per default channels) after commit.

#### Scenario: tenant opt-out suppresses

- GIVEN the tenant deactivated `appointment.cancelled`
- WHEN an appointment is cancelled
- THEN no notification is recorded or dispatched.

### REQ-APT-007 — Calendar UI, availability UI, and registration

The frontend SHALL ship a new `appointment` module: a calendar surface (day/week/month) querying appointments by `scheduled_at` range, with permission-gated actions — schedule into a free slot via slot picker, confirm, reschedule, cancel with mandatory reason, and attend linking to the existing screening-apply flow — plus a new `availability` configuration page (weekly editor + date blocks) gated by `practitioner_schedule` permissions. UI copy in Spanish; zero `any`; no eager catalog loading. Registration MUST complete the checklist (modules/permissions/module_system CSVs, `SystemTableSeeder` maps, `App.tsx` routes under `PermissionRoute`, `useCatalogs::CatalogType`). Existing visit surfaces MUST tolerate NULL `visit_date`, splitting upcoming appointments (by `scheduled_at`) from attended history (by `visit_date`); the screening-apply visit picker MUST offer attended visits only.

#### Scenario: actions gated by permission

- GIVEN a logged-in `assistant`
- WHEN viewing an appointment's detail
- THEN the attend action is not offered (and its endpoint answers 403 regardless).

#### Scenario: menu and coexistence

- WHEN seeders run and users log in
- THEN `GET /api/menu` shows the agenda/availability entries per role, and child visit history renders attended visits without errors from NULL-date appointments.

---

## ADDED Requirements — capability `practitioner-availability`

### REQ-AVL-001 — Weekly schedules and date exceptions

The system SHALL create two tenant-scoped tables (`BaseModel::columns($table, true)` + `BelongsToTenant`) with Orion CRUD: `practitioner_schedule` (practitioner + health center + `weekday` 0–6 + `start_time`/`end_time` + `slot_duration_minutes` + `is_active`) defining the weekly working pattern, and `practitioner_schedule_exception` (practitioner + `date` + `type` `block`|`open` + optional `start_time`/`end_time` + reason) overriding specific dates. Writes require `practitioner_schedule` write permissions (administrator); cross-tenant rows MUST 404 under `TenantScope`; payload FKs use tenant-scoped `exists`; `end_time` after `start_time` MUST be validated.

#### Scenario: weekly pattern definition

- WHEN an administrator defines Mon–Fri 09:00–14:00 with 30-minute slots for practitioner P at center C
- THEN the rows persist and are listable by P.

#### Scenario: cross-tenant invisible

- GIVEN a schedule of tenant A
- WHEN a tenant-B user requests it by id
- THEN the response is 404.

### REQ-AVL-002 — Slot computation

`GET /api/practitioner/{practitioner}/slots?date=` (permission `read.practitioner_schedule`) SHALL return the bookable slots for that date: the weekday's active schedule windows divided into `slot_duration_minutes` steps, MINUS `block` exceptions, PLUS `open` exceptions (extra windows), MINUS slots overlapping active appointments, MINUS already-past slots when the date is today (tenant timezone). Appointment default duration = the schedule's `slot_duration_minutes`, overridable per appointment (Assumption A2). With no schedule and no `open` exception for the date, the list MUST be empty.

#### Scenario: subtraction algorithm

- GIVEN Tuesday 09:00–11:00 with 30-minute slots, a block exception 09:30–10:00, and an active appointment at 10:00
- WHEN slots are requested for that Tuesday
- THEN exactly 09:00 and 10:30 are returned.

#### Scenario: today discards elapsed slots

- WHEN slots are requested for today at 12:00 tenant time
- THEN no slot starting at or before 12:00 is returned.

#### Scenario: read is permission-gated

- WHEN a user without `read.practitioner_schedule` requests slots
- THEN the response is 403.

### REQ-AVL-003 — Double-booking prevention

`schedule` and `reschedule` MUST NOT place an appointment whose interval (`scheduled_at` + duration) overlaps an active appointment of the same practitioner: the response is 409 `SCHEDULE_CLASH` and nothing persists. The exact same start MUST additionally be blocked at the database by a partial unique index over active statuses (defense in depth under race). Clash check and write MUST be atomic (no check-then-write window). Terminal appointments MUST NOT block their slot.

#### Scenario: partial overlap rejected

- GIVEN an active appointment 10:00–10:30 for practitioner P
- WHEN scheduling P at 10:15
- THEN the response is 409 `SCHEDULE_CLASH`.

#### Scenario: race on the exact start

- WHEN two schedule requests race for the same practitioner and start
- THEN exactly one succeeds and the other receives 409.

#### Scenario: cancellation frees the slot

- GIVEN a cancelled appointment at 10:00
- WHEN a new appointment is scheduled at 10:00 for the same practitioner
- THEN it succeeds.

---

## MODIFIED Requirements — capability `child-registry` (delta)

### REQ-CH-008 — Visit header

The system SHALL expose Orion CRUD for `visit` with required `child_id`, `health_center_id`, `practitioner_id` (a `practitioner_profile` of the tenant). `visit_date` and `receipt_number` are nullable while an appointment is unattended: walk-in creation (`POST /api/visit`) keeps its contract — it requires `visit_date` (not in the future), creates the visit directly `attended`, and assigns the folio immediately; scheduled appointments (REQ-APT-002) are created with both NULL and receive `visit_date` + folio at attend (REQ-APT-003). `receipt_number` is server-generated per tenant with format `V-YYYY-NNNNNN` (year of `visit_date`, zero-padded sequence), MUST be unique per tenant among non-deleted visits (DB partial unique — NULLs coexist), and MUST be immutable once assigned — update requests MUST NOT change it (client-supplied values on create/update are ignored or rejected). Visits carry no screening/application logic in this capability.
(Previously: `visit_date` was required not-in-future at creation and `receipt_number` was always generated at creation; no lifecycle existed.)

#### Scenario: walk-in contract intact

- WHEN `POST /api/visit` runs with today's `visit_date`
- THEN the visit is created `attended` with an immediate folio — the same request/response contract as before this change.

#### Scenario: receipt number generated and unique

- WHEN two visits obtain folios in tenant T1 during 2026 (walk-in create or attend)
- THEN they receive distinct numbers `V-2026-000001` and `V-2026-000002`; AND tenant T2's first 2026 folio is also `V-2026-000001`.

#### Scenario: concurrent folio assignment yields no duplicates

- WHEN two folio assignments race in the same tenant
- THEN both succeed with distinct receipt numbers (sequence acquisition is transactional/locked).

#### Scenario: referenced entities must share the tenant

- WHEN a visit is created for a T1 child with a T2 `practitioner_id` or T2 `health_center_id`
- THEN the request fails with 422.

#### Scenario: child visit history query

- WHEN listing visits filtered by `child_id` sorted by `visit_date` descending
- THEN results are served by the composite index (`tenant_id`, `child_id`, `visit_date DESC`) — the query MUST NOT require a per-row scan of the tenant's visits.

---

## ADDED Requirements — capability `screening-application` (delta)

### REQ-SCR-017 — Screening creation requires an attended visit

`POST /api/screening` SHALL additionally require the referenced visit's `visit_status` to be `attended`: any other status MUST return 422 `VISIT_NOT_ATTENDED` and persist nothing. All other rules of REQ-SCR-002 are unchanged — an attended appointment satisfies them exactly like a walk-in visit (both carry `visit_date`).

#### Scenario: a future appointment cannot be screened

- GIVEN a `scheduled` appointment
- WHEN `POST /api/screening` references it
- THEN the response is 422 `VISIT_NOT_ATTENDED` and no `screening` row exists.

#### Scenario: an attended appointment screens normally

- GIVEN an appointment attended today
- WHEN the screening draft is created
- THEN the response is 201 per REQ-SCR-002.
