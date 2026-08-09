# Tasks — Amenity Reservations

> **v2 (earmark money model).**
> **Mode:** Standard Mode (tests ALONGSIDE code; strict_tdd = false — not test-first mandatory).
> **Branch:** single `feat/amenity-reservations` (from clean `main`, no parallel branches).
> **Delivery:** sequential PRs to main per slice, each ≤400 lines, all behind the
> per-tenant flag `amenity_reservations.enabled=false` until the GA slice (S17).
> **Backend root:** `backend/Modules/AmenityReservations/`. **Frontend:** `frontend/src/modules/{amenity,reservation}/`.
> Slices are ordered S1→…→S17; S17 (menu + toggle) is LAST and is the slice that exposes everything.
> **Note:** `backend/modules_statuses.json` ALREADY has `"AmenityReservations": true`
> (deliberate placeholder) — S1 must NOT re-add it, only create the module files.
> **vs v1:** the folio/payment-tables slice and the `createStandaloneCharge` slice are
> GONE; S8 is now the earmark handler + payment wiring (less code, more reuse). Payment
> frontend reuses the `payment` module sheets. Calendar = schedule-x free MIT core.

## S1 — Scaffold module + `reservation_status` catalog + flag [M] (dep: —) — FIRST PR
- [x] `php artisan module:make AmenityReservations` (module files only — do NOT re-add to `modules_statuses.json`, it is already `true`)
- [x] Central catalog migration `reservation_status` with `BaseModel::columns($table, false)` (NO tenant_id)
- [x] `ReservationStatus` extends `CentralCatalogModel` with fixed UUID constants (pending_approval, pending_payment, confirmed, cancelled, rejected, completed, no_show)
- [x] Seed via `CatalogSeeder::upsertCatalog` (idempotent)
- [x] Per-tenant flag `amenity_reservations.enabled` (default `false`) kill-switch
- [x] Tests: seeders idempotent, `reservation_status` has no `tenant_id`, flag default false
- [ ] Reviewers: `@tenancy-reviewer`, `@laravel-architecture-reviewer`

## S2 — Permissions + identity [S/M] (dep: S1)
- [x] 12 permissions (amenity + amenity_reservation: read/create/update/delete/restore/report)
- [x] Residents get `read.amenity` + `read/create.amenity_reservation`
- [x] Edit `csv/{modules,permissions}.csv` + `RolePermissionTableSeeder` (`module_system.csv` + `SystemTableSeeder` entity/route/icon are S17 — no menu yet)
- [x] Tests: residents hold the expected permissions; admin holds full set
- [ ] Reviewers: `@tenancy-reviewer`, `@laravel-architecture-reviewer`

## S3 — Amenity domain model [M] (dep: S1)
- [x] Migrations + models for `amenity`, `amenity_slot`, `amenity_blackout` (tenant-scoped, `BaseModel::columns($table, true)` + `BelongsToTenant`)
- [x] Spatie Media `gallery` (multi) on amenity (`App\Models\Media`)
- [x] Column comments mandatory; money columns = bigInteger minor units (`TenantMoneyCast`)
- [x] Tests: model factories + tenant scoping + media collection
- [ ] Reviewers: `@tenancy-reviewer`, `@laravel-architecture-reviewer`

## S4 — Amenity Orion CRUD [M] (dep: S2, S3)
- [x] `AmenityController` + slot/blackout controllers + Create/Update requests + routes
- [x] `includes()` exposes `media`; `searchableBy()`/`sortableBy()` set
- [x] Tests: CRUD round-trip + media include
- [ ] Reviewers: `@tenancy-reviewer`, `@laravel-architecture-reviewer`, `@infra-cost-reviewer`

## S5 — Availability service [M] (dep: S3)
- [x] `AmenityAvailabilityService` (weekdays + slots + capacity + blackout + out_of_service + overlap)
- [x] `GET amenity/{id}/availability` endpoint
- [x] All date/time math in `tenant('timezone')`
- [x] Tests: availability across weekday/blackout/out_of_service/capacity; timezone correctness
- [x] `amenity_reservation` table + model + factory created HERE (ahead of S6) so the service has occupancy data to query — includes the `amenity_reservation_by_day_unique` partial unique index (by_day double-booking guard); by_slot capacity enforcement (FOR-UPDATE + COUNT) is deferred to S6's `ReservationService::create`
- [ ] Reviewers: `@tenancy-reviewer`, `@laravel-architecture-reviewer`, `@infra-cost-reviewer`

## S6 — Create reservation + model [M] (dep: S5, S1) — DONE
- [x] `amenity_reservation` migration + model (snapshots, `holds_slot`, `amount_outstanding`, `balance_due_date`, `payment_deadline`) — DONE in S5, see above (data model landed early so the availability service could query occupancy)
- [x] `ReservationService::create` (availability recheck via `AmenityAvailabilityService::forDate`, unit-membership authorization, `rules_accepted_at`, money snapshots, double-booking index) — `Modules/AmenityReservations/app/Services/ReservationService.php`
- [x] `AmenityReservationCreateRequest` + `AmenityReservationController` (Orion, `performStore` delegates to the service) + `POST amenity_reservation` route registered under the existing amenity tenancy+flag middleware group
- [x] `by_day` partial unique index `(tenant_id, amenity_id, reservation_date) WHERE holds_slot AND slot_id IS NULL AND deleted_at IS NULL` — DONE in S5 (name: `amenity_reservation_by_day_unique`); `by_slot` capacity enforced via amenity-row `lockForUpdate` + COUNT under lock in `ReservationService`
- [x] Tests: `Modules/AmenityReservations/tests/Feature/AmenityReservationCreationTest.php` (14 tests) — initial FSM status happy paths, unit-membership (403/201), rules acceptance, availability rejections (out_of_service/closed_weekday/blackout/past_date), double-booking (by_day 2nd→409; by_slot capacity N then N+1→409), tenant isolation. Full module suite: 71 passed (295 assertions), zero regressions.
- [ ] Reviewers: `@tenancy-reviewer`, `@laravel-architecture-reviewer`

## S7 — FSM transitions [M] (dep: S6)
- [ ] approve/reject/cancel/complete + endpoints with `lockForUpdate` + recheck guards
- [ ] Forbidden-transition enforcement (terminal states, pay-before-approval)
- [ ] Tests: every allowed transition + forbidden transitions rejected
- [ ] Reviewers: `@tenancy-reviewer`, `@laravel-architecture-reviewer`

## S8 — Earmark handler + payment wiring [M] (dep: S7)
- [ ] `AmenityReservationEarmarkHandler implements PaymentEarmarkHandler` (`EARMARK_TYPE='amenity_reservation'`): `validateSubmission` (tenant-scoped Eloquent, unit match, payable state, amount ≤ `amount_outstanding`, ≤1-pending guard) + `resolveApplications` (lockForUpdate + recheck, create standalone charge settled by the payment amount)
- [ ] Register the handler in `AmenityReservationsServiceProvider` via `PaymentEarmarkRegistry::register(...)`
- [ ] `AmenityChargeApplicationObserver` (template `ProjectChargeApplicationObserver`) advances the reservation FSM (pending_payment→confirmed / recompute `amount_outstanding`)
- [ ] Reuse `payment/submit` + `payment/register` + `payment/{id}/validate` with `earmark_type='amenity_reservation'` (NO new payment/folio table)
- [ ] Tests: earmark payment confirms → standalone charge **pinned & fully paid** + reservation `confirmed` + counts as treasury income + bank +exact + unified `R-` folio + NO cross-FIFO; deposit-only → `amount_outstanding = base − deposit` + `balance_due_date`, second payment settles; `payment_one_pending_per_earmark` (2nd pending rejected); tenancy (`earmark_id` of another tenant not resolved); cancel → reversal via `PaymentService::cancel`
- [ ] Reviewers: `@integration-reviewer` (PRIMARY), `@tenancy-reviewer`, `@laravel-architecture-reviewer` (+ consult `@collections-expert` in design)

## S9 — Surcharges [M] (dep: S7)
- [ ] `AmenitySurchargeService` — standalone **OPEN** charge (template `ProjectActivationService::createChargeForUnit` / `DirectChargeService`) + `reservation_surcharge` migration/model + admin endpoint
- [ ] Tests: surcharge creates a real OPEN Collections charge (batch_id null), appears in statement + FIFO + can go overdue
- [ ] Reviewers: `@laravel-architecture-reviewer`, `@integration-reviewer` (+ consult `@collections-expert` in design)

## S10 — Notifications — full matrix (owner: `@notifications-expert`) [M/L] (dep: S7, S8)
- [x] 11 per-tenant configurable events (NOT 12 — no `deposit_returned`: the deposit is an anticipo/part of cost, never a refundable garantía), category `AMENITIES`, dispatched after commit via `NotifiesResidents` + `notifyEvent()`
- [x] created→admin/board (configurable president+treasurer board-role picker UNION fixed `administrator` role); approved/rejected/payment_required/deposit_confirmed/payment_confirmed/payment_rejected/surcharge_added→resident; cancelled→resident (+ fixed board roles, two `notifyEvent` calls sharing one `resolve()`); `payment_reminder`/`expired` DEFINED here as public `ReservationService::notifyPaymentReminder()`/`notifyExpired()` hooks for S11's cron to call; `click_action` resident `/my-reservations`, admin `/amenity-reservation`
- [x] `payment_rejected` wired via a new `AmenityReservationPaymentRejectionObserver` on the `Payment` model (registered in `AmenityReservationsServiceProvider`, mirrors the existing `PaymentApplication` observer) — Collections' `PaymentService` untouched
- [x] Tests: `Modules/AmenityReservations/tests/Feature/AmenityReservationNotificationWiringTest.php` (11 tests) — each event fires once after commit with correct recipients, respects `notification_setting.is_active`, and a payment reversal never re-fires a settlement notification
- [x] Reviewers: `@notifications-expert` (OWNER), `@laravel-architecture-reviewer` — NOT yet run (pending)

## S11 — Cron cancel-expired + reminders [S/M] (dep: S7, S10)
- [ ] `amenities:cancel-expired-reservations` (per-tenant, respects flag, lockForUpdate + recheck, idempotent, reverses confirmed earmark payments via `PaymentService::cancel`, surfaces refund obligation)
- [ ] Emit `payment_reminder` before deadline (within this cron or separate `amenities:send-reminders`)
- [ ] Tests: cancels overdue pending_payment (and confirmed-with-overdue-balance), reverses confirmed payments, no-op for disabled tenants, idempotent
- [ ] Reviewers: `@tenancy-reviewer` (cron octane-safe), `@laravel-architecture-reviewer`

## S12a — Frontend admin amenity — list [M] (dep: S4, S5)
- [x] `frontend/src/modules/amenity/` list (schema, resource, store, hook, List)
- [x] Tests: Vitest list rendering; `pnpm lint` + `pnpm typecheck`
- [ ] Reviewers: `@react-frontend-reviewer`, `@infra-cost-reviewer`

## S12b — Frontend admin amenity — form [M] (dep: S4, S5)
- [x] Form: cost / deposit / destination account / rules / out-of-service fields (lazy catalogs per §2.12)
- [x] Tests: Zod schema + form submit
- [ ] Reviewers: `@react-frontend-reviewer`, `@infra-cost-reviewer`

## S12c — Frontend admin amenity — slots + blackouts + gallery [M] (dep: S4, S5)
- [x] Slots editor + blackout editor + gallery (Media upload)
- [x] Tests: slot/blackout CRUD UI; gallery upload
- [ ] Reviewers: `@react-frontend-reviewer`, `@infra-cost-reviewer`

## S13a — Frontend admin reservations — list + detail [M] (dep: S7, S8, S9)
- [x] `frontend/src/modules/reservation/` list + detail
- [x] Tests: list + detail rendering
- [ ] Reviewers: `@react-frontend-reviewer`, `@infra-cost-reviewer`

## S13b — Frontend admin reservations — lifecycle actions [M] (dep: S7, S8, S9)
- [x] approve / reject / cancel / complete (+ no-show) actions
- [x] Tests: action hooks + optimistic/refetch flow
- [ ] Reviewers: `@react-frontend-reviewer`

## S13c — Frontend admin reservations — validate payment + surcharge + return deposit [M] (dep: S7, S8, S9)
- [x] Validate/reject/cancel the earmark payment by reusing `usePaymentActions`/`PaymentActionDialogs`/`PaymentActionButtons` unmodified (fetched via `PaymentResource.search` filtered by `earmark_type`/`earmark_id`); register a new earmark payment reusing `PaymentResource.register` with the amenity's fixed destination account; add surcharge dialog
- [x] Tests: surcharge dual-422-shape handling + list/detail rendering (payment validate/reject/cancel reuse existing, already-tested `usePaymentActions`/`PaymentActionDialogs` — no new tests needed there)
- [ ] Reviewers: `@react-frontend-reviewer`, `@integration-reviewer` (FE ↔ payment contract)
- Note: "return deposit" has no distinct backend action (`ReservationService::complete()`/`noShow()` docblocks: deposit return/forfeiture is a status semantic, not a separate money-movement endpoint) — nothing further to build on the frontend for it beyond the FSM `complete`/`no_show` actions above.

## S13d — Frontend admin reservations — schedule-x calendar [M] (dep: S7, S8, S9)
- [x] Add dep `@schedule-x/react` + `@schedule-x/calendar` + `@schedule-x/theme-default` (free MIT core; `@schedule-x/events-service` installed then removed — unneeded, `CalendarApp.events.set()` already covers it) + runtime peers `preact`/`@preact/signals`/`temporal-polyfill` (not auto-installed under this repo's `legacyPeerDeps: true` pnpm setting)
- [x] Calendar (week/day/month) of ALL reservations as events, colored + filterable by amenity (NO premium resource-scheduler)
- [x] Tests: calendar renders events; amenity color/filter (mapping unit tests + page smoke test, schedule-x itself mocked)
- [ ] Reviewers: `@react-frontend-reviewer`, `@infra-cost-reviewer`

## S14 — Frontend resident — catalog [M] (dep: S4, S5)
- [x] Gallery (read-only) + restrictions/rules + availability picker (closed/blackout/occupied marked). DEVIATION: used a shadcn `<Calendar>` date→slot picker (`AmenityAvailabilityCalendar`, shared with S15) instead of a schedule-x availability calendar — orchestrator instructions explicitly called for "a clear date→slot picker" as the selection UX, schedule-x optional/context-only; not used to keep the picker unambiguous and to avoid a second Temporal/schedule-x integration surface for a single-resource view.
- [x] Tests: catalog rendering (`AmenityCatalogPage.test.tsx`) + availability day-map/reason/slot pure-logic tests (`amenityAvailability.test.ts`)
- [ ] Reviewers: `@react-frontend-reviewer`, `@infra-cost-reviewer`

## S15 — Frontend resident — reservation flow [M/L] (dep: S6, S8, S14)
- [x] Flow: pick date/slot in the availability calendar → rules (if `rules_text`) → cost/deposit review → reserve (`POST /amenity_reservation`) → **submit earmark payment + proof by reusing the `payment` module sheet** (new `SubmitReservationPaymentSheet`, mirrors `SubmitPaymentSheet`/`payment/submit` with `earmark_type: 'amenity_reservation'` + the amenity's own `bank_account_id`, read-only)
- [x] Tests: wizard step/validation pure logic (`reserveWizard.test.ts` — steps, slot-required-for-by_slot, rules-required, deposit-minimum hint, payload builder)
- [ ] Reviewers: `@react-frontend-reviewer`, `@integration-reviewer` (FE ↔ payment contract)

## S16 — Frontend resident — `/my-reservations` [M] (dep: S7, S8)
- [x] List (own units only, client-side `unit_id in [...]` filter — see apply-progress note on the backend gap) + cancel (reuses `useReservationActions`/`ReservationActionDialogs`) + pay balance (reuses `SubmitReservationPaymentSheet`)
- [x] Tests: list/empty-state + own-unit filter + pay button visibility (`MyReservationsPage.test.tsx`)
- [ ] Reviewers: `@react-frontend-reviewer`

## S17 — Menu + toggle (GA) [M] (dep: S12–S16) — LAST PR, exposes everything
- [x] Visibility in the "Fraccionamiento" system: `module_system.csv` rows for `amenity`/`amenity_reservation` (group "Amenidades") + `SystemTableSeeder` entity/route/icon mappings (`Amenidades` → `/amenity`, `Reservaciones` → `/amenity-reservation`, both plain-entity so residents' `read.*` also surfaces the menu item — visibility default `true`, not added to the hidden list)
- [x] `App.tsx` routes already existed behind `PermissionRoute` (S12–S16); dual-audience landing resolved via IN-PAGE role redirect (mirrors `PackageListPage` → `/my-packages`): `AmenityListPage`/`AmenityReservationListPage` `<Navigate>` a viewer lacking `update.amenity`/`update.amenity_reservation` to `/amenities`/`/my-reservations`
- [x] GA hardening (integration-reviewer W2): `AmenityReservationEarmarkHandler::validateSubmission` now rejects (422) when `amenity_reservations.enabled` is off, closing the gap where the shared `payment/submit`/`payment/register` routes bypassed `EnsureAmenityReservationsEnabled`
- [x] Verification: `AmenityMenuWiringTest` (admin + resident both see the menu entries under Fraccionamiento with the right route/icon); redirect covered by `AmenityListPage.test.tsx`/`AmenityReservationListPage.test.tsx`; flag-off earmark rejection covered by a new `AmenityReservationEarmarkTest` case. NOTE: per `AmenityReservationsSettings`' own docblock, the tenant flag is deliberately independent of menu visibility (only `module_tenant` billing opt-out controls that) — the sidebar entry stays visible even with the flag off; the underlying routes 403 via the existing middleware.
- [ ] Reviewers: `@react-frontend-reviewer`, `@integration-reviewer`

## Verification Gate (end-to-end)
- [ ] Backend (host php, Postgres host — NOT sail): `cd backend && php artisan migrate:fresh --seed` + `php artisan test` (do NOT run `_quarantine/`)
- [ ] Frontend: `cd frontend && pnpm build && pnpm lint` (+ `pnpm typecheck`)
- [ ] Manual: reservation flow (resident) + admin inbox (approve / validate earmark payment / surcharge / schedule-x calendar) with the flag on; menu hidden unless flag enabled
- [ ] `vendor/bin/pint --dirty` on touched backend files
