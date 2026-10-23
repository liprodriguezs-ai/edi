# Proposal: EDI Appointment Scheduling

## Intent

Today `visit` is only a completed encounter: immutable folio `V-YYYY-NNNNNN`, date-only `visit_date ≤ today`, no time, no status, no cancellation. Nothing models a future appointment, practitioner availability, or reschedule/cancel — clinics run their agenda outside the system. This change delivers a full medical agenda on the existing entity: a visit is born scheduled and is later marked attended, with real availability (weekly schedules + exceptions + clash-free slots), automatic reminders, and a new `assistant` role so front-desk staff operate the agenda without clinical powers.

## Scope

### In Scope
- Additive `visit` migration (ChildRecord): `scheduled_at`, `visit_status_id`, duration, confirmation/attendance/cancellation/reminder fields; `receipt_number` + `visit_date` → nullable; backfill historic rows to `attended`; new indexes incl. the anti-double-booking partial unique.
- CENTRAL FSM catalog `visit_status` (`scheduled/confirmed/attended/cancelled/no_show`, mold `follow_up_status`); terminal states frozen (409 `VISIT_FROZEN`).
- `VisitLifecycleService` (mold `FollowUpLifecycleService`); FSM actions as custom Orion routes; folio generated at ATTEND via extracted `VisitService::assignReceiptNumber()`; new fine-grained `attend.visit` permission (practitioner/administrator only).
- Availability in Clinic: tenant-scoped `practitioner_schedule` (weekly working hours) + `practitioner_schedule_exception` (blocks/openings), `AvailabilityService` (slot computation + transactional clash guard), Orion CRUD + slots endpoint.
- Idempotent hourly `appointment:send-reminders` command; 4 `NotificationEvent` rows (`appointment.created/rescheduled/cancelled/reminder`) through the existing engine (in-app + push).
- Spatie role `assistant` (schedule/confirm/reschedule/cancel; no attend, no availability writes, no delete) + full registration checklist (CSVs, `SystemTableSeeder`, `RolePermissionTableSeeder`, `$mapperSelects`, `CatalogType`, `Role` consts).
- Harden `ScreeningCreateRequest` to require `visit_status = attended`.
- React: new `appointment` module — Schedule-X calendar as custom surface (mold `ScreeningApplyPage`) — plus new `availability` config page; `IVisit` extended; coexistence with the current walk-in flow; gating 100% by permission.

### Out of Scope
- SMS/WhatsApp/email to the caregiver — recipients are login users; no new notification channel.
- Waitlists, recurring appointments, room/resource booking, patient self-scheduling, auto no-show, `EXCLUDE gist`.

## Capabilities

### New Capabilities
- `appointment-scheduling`: visit lifecycle FSM (schedule/confirm/reschedule/cancel/no-show/attend), folio-on-attend, reminders, assistant role, calendar UI.
- `practitioner-availability`: weekly schedules, date exceptions, slot computation, double-booking prevention.

### Modified Capabilities
- `child-registry`: `visit` gains a scheduled lifecycle; `receipt_number`/`visit_date` become nullable and are set on attend; walk-in creation keeps its contract (born `attended` + folio).
- `screening-application`: screening creation now requires an attended visit.

## Approach

An appointment IS a `visit` born `scheduled`; `attend` fixes `visit_date`, stamps `attended_at`, and generates the folio — the official sequence never burns numbers on cancellations, and the existing partial unique index tolerates N unattended NULLs. `before_or_equal:today` moves to `VisitAttendRequest`; `scheduled_at` validates `after:now`. Double-booking: partial unique on exact start (`scheduled|confirmed`) + `lockForUpdate` guard for overlaps. Slots = weekday schedule − exceptions − active appointments − past slots. Reminders: convergent hourly command stamping `reminder_sent_at`. `VisitFactory` defaults `attended`, so backend (737) and frontend (221) suites stay green unedited. House patterns throughout (fixed-UUID central catalog, partial uniques, afterCommit notifications).

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `backend/Modules/ChildRecord/` | Modified | Additive visit migration, `VisitStatus`, lifecycle service, requests, reminders command, controller/routes |
| `backend/Modules/Clinic/` | Modified | Schedule + exception tables, `AvailabilityService`, controllers/routes |
| `backend/Modules/Notification/`, `backend/Modules/Screening/` | Modified | Event catalog rows; hardened `ScreeningCreateRequest` |
| `backend/database/seeders/*` (incl. `csv/*`), `core/src/Models/Role.php`, `AngularController` | Modified | `visit_status` catalog, `assistant` role + permissions, registration, selects |
| `frontend/src/` | New/Modified | `visit/schema.ts` extended; new `appointment` + `availability` modules; Schedule-X theme block in `index.css`; `App.tsx`, `useCatalogs` |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Overloaded semantics (appointment vs encounter in one row) | Med | `visit_status` single source of truth; `scheduled_at`/`visit_date` exclusive by state; invariants in column comments |
| Nullable folio/`visit_date` breaks legacy behavior | Low | Backfill + factory default + walk-in contract intact; explicit NULL-coexistence test |
| Double-booking race | Med | Partial unique (exact start) + transactional overlap guard; race test |
| Schedule-X theming (versioned `sx__*` classes) and TZ mapping | Med | Overrides isolated in one marked `index.css` block; centralized UTC↔local adapter; live visual check |

## Rollback Plan

Revert the PR(s). Availability tables and `visit_status` are new (`migrate:rollback` drops them); seeder rows are additive. The visit migration is additive except two `nullable()->change()`: `down()` restores NOT NULL safely once unattended appointments are pruned — every historic row already carries folio + date. Walk-in contract never changed.

## Dependencies

- `edi-clinic-and-child-registry` delivered (`visit`, `practitioner_profile`, `health_center`).
- `FollowUp` module as mold (lifecycle service, FSM catalog, convergent cron, notifications).
- Schedule-X already installed (`@schedule-x/calendar`, `@schedule-x/react`, `@schedule-x/theme-default`).

## Success Criteria

- [ ] Full lifecycle via API; terminal states answer 409 `VISIT_FROZEN`; invalid transitions rejected.
- [ ] Folio only on attend, gap-free; N unattended NULL folios coexist under the existing partial unique.
- [ ] Double-booking rejected under race (exact start + overlap); slots endpoint = schedule − exceptions − active appointments.
- [ ] Reminder command idempotent; `assistant` can schedule/confirm/reschedule/cancel but gets 403 on attend and availability writes; screening rejects non-attended visits.
- [ ] Backend green from 737 without editing existing tests; frontend tsc/vitest/build green from 221; live calendar flow verified (schedule → confirm → attend → apply screening).

## Proposal question round

Auto mode — no blocking round. Structural decisions were fixed by the user in the plan's interactive round (extend `visit`; full availability; cron reminders; create `assistant` now). Remaining product defaults are ASSUMED — encode in `design.md` and correct before `sdd-apply`:

1. Reminder lead time: 24h before `scheduled_at`, hourly send window.
2. Default duration: `practitioner_schedule.slot_duration_minutes`, overridable per appointment.
3. `no_show` is manual — no auto-marking cron.
4. Reschedules uncapped, only counted (`reschedule_count`).
5. Assistant scope: any appointment in the tenant (permission-based, not ownership-based).
