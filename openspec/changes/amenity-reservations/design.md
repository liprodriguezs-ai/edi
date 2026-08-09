# Design — Amenity Reservations

> **v2 (earmark money model).** Backend root: `backend/Modules/AmenityReservations/`.
> Frontend: `frontend/src/modules/{amenity,reservation}/`. Everything behind the
> per-tenant flag `amenity_reservations.enabled` (default `false`) until the GA
> slice (S17).

## Money Model (the core) — earmark

The reservation's fee, deposit and balance are **earmarked `Payment`s**, recorded
by the EXISTING Collections payment engine. Nothing bespoke is built for money.

| Concept | How it is modeled | Reuses |
|---|---|---|
| Reservation fee / deposit / balance | `Payment` tagged `earmark_type='amenity_reservation'` (`earmark_id=<reservation>`) via `payment/submit` (resident) or `payment/register` (admin) → `payment/{id}/validate` (admin confirms) | folio `R-YYYY-NNNNNN` (unified), proof (Spatie Media on the Payment), payment FSM, bank credit, treasury income, reversal/cancel, deep-links |
| Reservation charge | Created by `AmenityReservationEarmarkHandler::resolveApplications` **on confirm**: standalone charge (`charge_batch_id=null`), **pinned and fully paid** by the payment amount | the already-proven DirectCharge/Projects capability |
| Surcharge (incident) | Standalone **OPEN** charge created directly by `AmenitySurchargeService` (concept + amount + account) → real debt (FIFO / delinquency) | existing `charge_concept` |
| Refund | `PaymentService::cancel` (reversal) + `ExpenseService::register` (cash-out) | — |

Money columns are `bigInteger` minor units (cents) and cross the API boundary ONLY
as serialized Money via `TenantMoneyCast` (never a raw int; currency resolved per
tenant — see laravel-backend-architecture §17). Aggregates stay as plain ints internally.

### `AmenityReservationEarmarkHandler implements PaymentEarmarkHandler`
`EARMARK_TYPE = 'amenity_reservation'`. Registered in `AmenityReservationsServiceProvider`
with `PaymentEarmarkRegistry::register(...)` (template: `ContributionEarmarkHandler`
+ `ProjectsServiceProvider`).

- **`validateSubmission(array $data)`**: resolve the reservation with **tenant-scoped
  Eloquent** (tenancy contract — NO `DB::` / `withoutGlobalScope`), assert `unit_id`
  matches, the reservation is in a payable state (`pending_payment`, or `confirmed`
  with an outstanding balance), the amount does not exceed `amount_outstanding`, and
  the "already has a pending payment" guard (backed by the `payment_one_pending_per_earmark`
  index → ≤1 pending payment per reservation).
- **`resolveApplications(Payment $payment)`**: `lockForUpdate` the reservation, re-check
  binding/state, create the standalone charge settled by the payment amount, and return
  `[['charge_id' => …, 'apply_amount' => …]]`.
- **Reservation FSM advance** (pending_payment→confirmed, or recompute `amount_outstanding`)
  via an **`AmenityChargeApplicationObserver`** (template: `ProjectChargeApplicationObserver`)
  or inside the handler — final placement pinned in sdd-design of S8 with `@collections-expert`.

## Data Model (tenant-scoped unless noted)

- **`amenity`** — name, description, restrictions, capacity, `reservation_mode`
  (`by_day`|`by_slot`), `available_weekdays` (json), has_cost, cost_amount (minor
  units), `bank_account_id` (destination account for the earmark payment, nullable),
  requires_approval, requires_deposit, deposit_amount, deposit_due_days, `payment_timing`
  (`pay_later`|`pay_upfront`), rules_text, out_of_service + reason. Media: `gallery` (multi).
- **`amenity_slot`** — amenity_id, weekday, start_time, end_time, capacity_override. (`by_slot` only.)
- **`amenity_blackout`** — amenity_id, start_date, end_date, reason.
- **`amenity_reservation`** — amenity_id, unit_id, user_id, reservation_date, slot_id?,
  start_at/end_at (materialized in the tenant tz, stored UTC), `reservation_status_id`
  (FK to central catalog), `holds_slot` (bool, drives the double-booking index),
  approved/rejected/cancelled fields, cancellation_reason, rules_accepted_at,
  **snapshots** cost_amount/deposit_amount, **`amount_outstanding`** + **`balance_due_date`**
  (deposit paid, balance pending), payment_deadline. Payments link via `Payment.earmark_id`;
  charges link via those payments' applications.
- **`reservation_surcharge`** — reservation_id, `charge_id` (FK to the standalone Collections
  charge), charge_concept_id, amount.
- **CENTRAL CATALOG `reservation_status`** — `CentralCatalogModel`, fixed UUID constants,
  migration `BaseModel::columns($table, false)` (NO `tenant_id`, NO `BelongsToTenant`),
  seeded once by `CatalogSeeder::upsertCatalog`. States: `pending_approval`, `pending_payment`,
  `confirmed`, `cancelled`, `rejected`, `completed`, `no_show`.

### ❌ Removed from v1 (do NOT re-create)
- `reservation_payment` table — replaced by earmarked `Payment`.
- `reservation_sequence` table + `AR-YYYY-NNNNNN` folio — the unified Collections
  `ReceiptSequence` (`R-YYYY-NNNNNN`) is reused as-is.
- Manual `BankAccountService::adjustBalance` — bank credit is automatic through the payment engine.
- `TreasuryReportService` income change — `monthlyInflow()` already counts every CONFIRMED `Payment`.
- The proposed `ChargeService::createStandaloneCharge()` primitive — the capability already
  exists (DirectCharge / Projects) and is reused.

## Reservation FSM

```
crear ─ requiere aprobación? ─► pending_approval ─(admin)─► aprueba / rechaza(rejected*)
        └─ no ─┐
               ├─ con costo? ─► pending_payment ─(pago earmark confirmado)─► confirmed
               └─ sin costo ──────────────────────────────────────────────► confirmed
confirmed (amount_outstanding>0 si pagó solo depósito; saldo con balance_due_date)
        ─(pasa fecha)─► completed / no_show*
cualquier activa ─(motivo + notificación)─► cancelled*  → PaymentService::cancel + reembolso manual

(*) rejected/cancelled/cron reversan pagos confirmados vía PaymentService::cancel.
    no_show forfeitea depósito; completed lo devuelve.
```

- The `payment_one_pending_per_earmark` index guarantees **≤1 pending payment per
  reservation** (race-proof, free from the engine).
- **`pending_payment` and `pending_approval` HOLD the slot** (`holds_slot=true`); the
  cron is the only thing that releases abandoned holds.
- **Order with approval + pay_upfront:** approve first, pay after (avoids paid-then-rejected).
- FORBIDDEN transitions: no payment before approval when `requires_approval`; no
  transition out of a terminal state (`completed`, `cancelled`, `rejected`, `no_show`);
  no full confirmation while the balance is unpaid.

## Services (backend)

- `AmenityAvailabilityService` — real availability: weekdays + slots + capacity +
  blackout + out_of_service + overlap. **All date/time math in `tenant('timezone')`**
  (the app runs in UTC).
- `ReservationService` — create/approve/reject/cancel/complete + transition guards
  (lockForUpdate + recheck).
- **`AmenityReservationEarmarkHandler`** (new, `implements PaymentEarmarkHandler`) +
  registration in `AmenityReservationsServiceProvider`.
- **`AmenityChargeApplicationObserver`** — advances the reservation FSM when the earmark
  charge is applied (template: `ProjectChargeApplicationObserver`).
- `AmenitySurchargeService` — creates the standalone OPEN surcharge charge + `reservation_surcharge` link.
- `CancelExpiredReservationsCommand` — daily per-tenant cron (template `RefreshChargeStatusesCommand`),
  respects the flag; cancels expired `pending_payment` and `confirmed`-with-overdue-balance
  reservations (reversal via `PaymentService::cancel`).
- ❌ Removed from v1: `ReservationPaymentService`, `ReservationSequenceService`, and the
  `ChargeService::createStandaloneCharge()` primitive (capability already exists).

## Notification Matrix (owner: `@notifications-expert`)

All per-tenant configurable (via `notification_setting`), category
`NotificationCategory::AMENITIES`, dispatched with `NotifiesResidents` + `notifyEvent()`
**after** the commit. `click_action`: resident → `/my-reservations`, admin →
`/amenity-reservation`. Recipients via `unitResidentUserIds($unitId)` (resident) or
`userIdsWithRole([...])` / `boardRecipientsForEvent()` (admin/board). 12 events:

| Event | Recipient | When |
|---|---|---|
| `amenity_reservation.created` | Admin/board (president, treasurer, administrator) | new request (esp. if requires_approval) |
| `amenity_reservation.approved` | Resident | admin approves |
| `amenity_reservation.rejected` | Resident | admin rejects (+ reason) |
| `amenity_reservation.payment_required` | Resident | enters pending_payment (deadline + amount) |
| `amenity_reservation.deposit_confirmed` | Resident | deposit earmark validated (pending balance + due date) |
| `amenity_reservation.payment_confirmed` | Resident | full/balance earmark validated → reservation confirmed |
| `amenity_reservation.payment_rejected` | Resident | admin rejects the payment/proof (re-submit) |
| `amenity_reservation.payment_reminder` | Resident | reminder before the deadline (via cron) |
| `amenity_reservation.cancelled` | Resident (+ board) | admin cancellation (+ reason) |
| `amenity_reservation.expired` | Resident | cron auto-cancels for non-payment |
| `amenity_reservation.surcharge_added` | Resident | admin generates a surcharge ($ + concept) |
| `amenity_reservation.deposit_returned` | Resident | admin returns the deposit on completion |

## Calendar / scheduler (frontend)

New approved dependency: **schedule-x free MIT core** — `@schedule-x/react` +
`@schedule-x/calendar` (week/day/month views) + `@schedule-x/events-service`.
React 19 compatible. The **premium `resource-scheduler` is NOT used** (paid, like
FullCalendar); instead events are **colored/filtered by amenity**.

- **Admin — "see schedules and spaces":** a schedule-x calendar (week/day/month) with
  ALL reservations as events, **colored and filterable by amenity**. Covers schedules +
  spaces without the premium row-per-space layout.
- **Resident — availability:** when reserving, the resident sees the amenity's calendar
  (closed/blackout/occupied dates marked) and picks a date/slot → reservation sheet
  (template `SubmitPaymentSheet`) → earmark payment.
- **Timezone:** availability is DECIDED backend-side (anchored to `tenant('timezone')`);
  the frontend only paints free/occupied/blocked from the endpoint. The calendar shows
  local browser time (the app assumes browser = tenant tz, a convention documented in
  `dateHelper.ts`). Residual risk low and consistent with the rest of the app.

## Baked-in Risk Mitigations (from the risk register)

- **Double-booking (CRITICAL):** `by_day` → partial unique index over
  `(tenant_id, amenity_id, reservation_date) WHERE holds_slot AND deleted_at IS NULL`
  (catch `UniqueConstraintViolationException` → 409). `by_slot` with capacity →
  `SELECT … FOR UPDATE` on an occupancy row `(amenity_id, slot_id, reservation_date)`
  + COUNT under lock before insert. Lock the `amenity` row at the start of create.
- **Timezone (HIGH):** materialize slots with `Carbon::parse("$date $time", tenant('timezone'))->utc()`;
  "today" always `now(tenant('timezone'))->toDateString()`; deadlines built in tz. Never
  bare `Carbon::today()`/`now()`.
- **Auth (HIGH):** reserving requires active `UnitUser` membership (pattern
  `ScopesToResidentUnits::ownUnitIds()`); payment validation is **admin only** (the
  `payment/{id}/validate` endpoint is already a treasurer action); reads scoped to the
  own unit (foreign show → 404); lifecycle actions gated by Orion policies. **The handler
  resolves the reservation ONLY with tenant-scoped Eloquent** (earmark tenancy contract).
- **Already FREE via the Payment engine (bespoke v1 mitigations dropped):** credit/debit
  symmetry on cancel, folio assigned only on confirm, no phantom debt before payment,
  ≤1 pending payment per reservation (`payment_one_pending_per_earmark`), and treasury
  income recognition — all inherited from `PaymentService`, not re-implemented.

## Reviewer Routing (per slice, via `/review-stack`)
- `@tenancy-reviewer`: S1, S2, S3–S8 (esp. the handler: tenant-scoped contract), S11.
- `@integration-reviewer` **primary on S8** (earmark: settled charge, no cross-FIFO,
  handler tenancy, ≤1 pending) and S13/S15 (FE ↔ payment contract).
- `@collections-expert`: consult in sdd-design of S8/S9 (earmark contract + observer +
  charge creation details).
- `@laravel-architecture-reviewer`: all backend slices.
- `@react-frontend-reviewer` + `@infra-cost-reviewer`: S4/S5 and all frontend.
- `@notifications-expert` **owner** of S10.

## Critical Reuse Files (by path)
- **Earmark (core of v2):** `backend/Modules/Collections/app/Contracts/PaymentEarmarkHandler.php`,
  `backend/Modules/Collections/app/Support/PaymentEarmarkRegistry.php`,
  `backend/Modules/Projects/app/Handlers/ContributionEarmarkHandler.php` (handler template),
  `backend/Modules/Projects/app/Providers/ProjectsServiceProvider.php:43` (registration),
  `backend/Modules/Projects/app/Services/ProjectActivationService.php` (`createChargeForUnit` ~:334 — standalone charge),
  `ProjectChargeApplicationObserver` (observer template).
- `backend/Modules/Collections/app/Services/PaymentService.php` (register/submit/validate/cancel
  + `applyConfirmedPayment` + `resolveEarmarkApplications`),
  `backend/Modules/Collections/app/Services/DirectChargeService.php` (ad-hoc charge template + earmark-vs-direct decision doc).
- `backend/Modules/Collections/app/Http/Controllers/PaymentController.php` (proof attach `receipt`),
  migration `add_earmark_columns_to_payment_table` + index `payment_one_pending_per_earmark`.
- `backend/Modules/Treasury/app/Services/TreasuryReportService.php` (`monthlyInflow` — already
  recognizes the income; NOT touched).
- `backend/Modules/Collections/app/Models/ChargeStatus.php` (central catalog template),
  `backend/database/seeders/{CatalogSeeder,SystemTableSeeder,RolePermissionTableSeeder}.php`
  + `csv/{modules,permissions,module_system}.csv` (dynamic menu — CLAUDE.md checklist).
- `backend/Modules/Collections/app/Console/Commands/RefreshChargeStatusesCommand.php` (per-tenant cron),
  `backend/Modules/Collections/app/Http/Controllers/Concerns/ScopesToResidentUnits.php` (resident scoping).
- Frontend: `frontend/src/modules/payment/` (`SubmitPaymentSheet` / `RegisterPaymentSheet` /
  `PaymentResource` — reuse with earmark), `frontend/src/App.tsx` (`PermissionRoute` routing).
  New dep: `@schedule-x/react` + `@schedule-x/calendar` + `@schedule-x/events-service` (free MIT core).
  Reuse `frontend/src/shared/utils/dateHelper.ts` (dates/tz) and `frontend/src/components/ui/calendar.tsx`
  (shadcn) where applicable.
