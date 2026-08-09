# Spec — Amenity Reservations

> **v2 (earmark money model).** Behavioral requirements for the load-bearing
> behaviors. Each requirement is testable. Money is `bigInteger` minor units.
> Catalog `reservation_status` is CENTRAL (no `tenant_id`, `CatalogSeeder`, fixed
> UUID constants). Reservation fee/deposit/balance are **earmarked `Payment`s**
> (`earmark_type='amenity_reservation'`) recorded by the existing Collections
> payment engine.

## R1 — Reservation creation + availability
- A resident can create a reservation only for an amenity that is available on the
  chosen date: weekday in `available_weekdays`, no `amenity_blackout` covering it,
  amenity not `out_of_service`, and within slot/capacity rules.
- `by_slot` amenities require a valid `slot_id`; `by_day` amenities do not.
- On create, `start_at`/`end_at` are materialized in the **tenant timezone** then
  stored as UTC; `cost_amount`/`deposit_amount` are snapshotted onto the reservation.
- A reservation with `requires_approval` starts in `pending_approval`; otherwise a
  costed amenity starts in `pending_payment`, a free amenity goes straight to `confirmed`.

## R2 — Concurrency / no double-booking
- **`by_day`**: two parallel transactions reserving the same
  `(tenant_id, amenity_id, reservation_date)` while `holds_slot=true` → exactly ONE
  succeeds; the other catches `UniqueConstraintViolationException` → HTTP 409.
- **`by_slot` with capacity N**: N+1 parallel reservations for the same
  `(amenity_id, slot_id, reservation_date)` → exactly N succeed (FOR-UPDATE
  occupancy row + COUNT under lock before insert).
- A `cancelled`/`rejected` reservation (`holds_slot=false`) does NOT block re-booking.

## R3 — FSM transitions (and forbidden ones)
- Allowed: `pending_approval → confirmed|pending_payment|rejected`;
  `pending_payment → confirmed|cancelled|expired-as-cancelled`;
  `confirmed → completed|no_show|cancelled`.
- `pending_payment` and `pending_approval` HOLD the slot (`holds_slot=true`).
- FORBIDDEN: paying before approval when `requires_approval=true`; any transition
  out of a terminal state (`completed`, `cancelled`, `rejected`, `no_show`);
  confirming a full reservation while the balance is unpaid.
- `no_show` forfeits the deposit; `completed` returns the deposit (admin action).

## R4 — Earmark money flow (deposit-only / full / balance)
- A reservation fee/deposit/balance is recorded as a `Payment` tagged
  `earmark_type='amenity_reservation'`, `earmark_id=<reservation>`, via
  `payment/submit` (resident) or `payment/register` (admin); the amount and
  `bank_account_id` live on the `Payment`, NOT on a module-owned row.
- `AmenityReservationEarmarkHandler::validateSubmission` accepts the submission only
  when the reservation is in a payable state, the `unit_id` matches, and the amount
  does not exceed `amount_outstanding`.
- On `payment/{id}/validate` (admin, `pending_validation → CONFIRMED`),
  `AmenityReservationEarmarkHandler::resolveApplications` creates a **standalone charge**
  (`charge_batch_id = null`) **pinned and fully paid** by the payment amount, and the
  reservation advances (`pending_payment → confirmed`, or `amount_outstanding` recomputed).
- The confirmed `Payment` is recognized as **treasury income** by
  `TreasuryReportService::monthlyInflow()` (any CONFIRMED `Payment`, by `paid_at`) and
  raises the destination `bank_account.current_balance` — with NO Treasury code changes.
- Folio is the **unified `R-YYYY-NNNNNN`** (Collections `ReceiptSequence`), assigned by
  the payment engine on confirm. There is NO `AR-` sequence and NO module-owned payment/folio table.
- Deposit-only payment → reservation `amount_outstanding = base - deposit` and a
  `balance_due_date` is set; the reservation becomes `confirmed` with a pending balance;
  a second earmark payment settles the balance.
- The earmark charge is settled ONLY by its own payment — **no FIFO application against
  the unit's other open debt**.

## R5 — ≤1 pending payment per reservation (earmark guard)
- The `payment_one_pending_per_earmark` index guarantees at most ONE `pending_validation`
  `Payment` per `(earmark_type, earmark_id)`.
- A second submission for a reservation that already has a pending payment is rejected
  (the handler's guard, backed by the index).

## R6 — Cancellation reversal via PaymentService
- Cancelling / rejecting / auto-cancelling a reservation reverses **every** CONFIRMED
  earmark `Payment` via `PaymentService::cancel` — bank debit + payment applications +
  folio bookkeeping, using the payment's own stored amount and `bank_account_id`.
- Each reversal lowers `bank_account.current_balance` by exactly the credited amount;
  the treasury report stays reconciled (income on confirm, reversal on cancel — both
  free from the payment engine).
- The cash-out to the resident is **manual assisted**: the admin registers an **expense**
  via `ExpenseService::register()` — there is no automatic refund expense.

## R7 — Surcharges as standalone OPEN Collections charges
- An admin-generated surcharge creates a REAL Collections `charge` via
  `AmenitySurchargeService` (precedent `ProjectActivationService::createChargeForUnit` /
  `DirectChargeService`) with `charge_batch_id = null`, **open** (not pre-paid).
- The new charge recomputes its own `charge_status` and the unit aggregate in one tx,
  appears in the account statement, enters FIFO, and can become "overdue".
- `reservation_surcharge` links `reservation_id → charge_id` (+ charge_concept_id, amount).
  FIFO/aggregate coupling with normal charges is INTENDED.

## R8 — Handler tenancy contract
- `AmenityReservationEarmarkHandler` resolves the reservation ONLY via tenant-scoped
  Eloquent — never `DB::` and never `withoutGlobalScope`.
- An `earmark_id` belonging to another tenant is not resolvable by the handler
  (the submission is invalid → 404/rejected); no cross-tenant charge or payment is created.

## R9 — Expiry cron (idempotent)
- `amenities:cancel-expired-reservations` runs daily per tenant; for each tenant it
  uses `lockForUpdate` + rechecks `pending_payment` + `payment_deadline` (and
  `confirmed` with an overdue balance) before acting.
- It auto-cancels overdue reservations, reverses any CONFIRMED earmark payment via
  `PaymentService::cancel`, and surfaces the refund obligation.
- It is a **no-op** for tenants with `amenity_reservations.enabled=false` and is
  idempotent (re-running causes no further changes).
- A reminder (`payment_reminder`) is emitted before the deadline (same mechanism as
  `collections:send-reminders`).

## R10 — Notification events
- The 12 events in the design matrix fire **after commit**, are per-tenant configurable
  via `notification_setting`, and use category `AMENITIES`.
- Recipients: resident events → `unitResidentUserIds`; admin/board events →
  `userIdsWithRole`/`boardRecipientsForEvent`. `click_action`: resident →
  `/my-reservations`, admin → `/amenity-reservation`.

## R11 — Authorization
- Creating a reservation requires active `UnitUser` membership for the target unit
  (pattern `ScopesToResidentUnits::ownUnitIds()`).
- Payment validation is **admin only** (the `payment/{id}/validate` treasurer action);
  reads are scoped to the resident's own unit (a foreign `show` returns 404); lifecycle
  actions are gated by Orion policies.

## Scenarios

| # | Scenario | Expected |
|---|----------|----------|
| 1 | Create reservation on a valid available date | 201, status = pending_approval / pending_payment / confirmed per amenity config |
| 2 | Create on a blackout / out_of_service / wrong weekday | rejected (422/409) |
| 3 | Two parallel `by_day` reservations, same date | exactly 1 succeeds, other → 409 |
| 4 | N+1 parallel `by_slot` reservations, capacity N | exactly N succeed |
| 5 | Cancelled reservation re-booked | succeeds (hold released) |
| 6 | Pay before approval when requires_approval | forbidden |
| 7 | `payment/submit` earmark → validate | reservation confirmed; standalone charge (batch_id null) **pinned & fully paid**; payment counts as treasury income; bank balance +exact; unified `R-` folio; NO cross-FIFO |
| 8 | Deposit-only earmark payment | confirmed + `amount_outstanding = base − deposit` + `balance_due_date`; second earmark payment settles balance |
| 9 | Second pending payment for the same reservation | rejected (`payment_one_pending_per_earmark`) |
| 10 | Cancel reservation with confirmed deposit + balance | every confirmed earmark payment reversed via `PaymentService::cancel` (bank −exact); refund obligation surfaced |
| 11 | Admin adds surcharge | real standalone OPEN Collections charge (batch_id null), in account statement + FIFO + can go overdue |
| 12 | Cron over overdue pending_payment | auto-cancelled, confirmed payments reversed, idempotent |
| 13 | Cron for tenant with flag disabled | no-op |
| 14 | Timezone: tenant America/Mexico_City books 19:00 local | "today" correct, not rejected as past |
| 15 | Foreign-unit reservation show | 404 |
| 16 | Earmark `earmark_id` from another tenant | handler does not resolve it (404/invalid); no cross-tenant charge/payment |
| 17 | Resident validates a payment | forbidden (admin only) |
| 18 | Notification events fire after commit, per-tenant config | correct recipients + click_action |
