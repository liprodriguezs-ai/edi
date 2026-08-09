# Proposal — Amenity Reservations (Reservas de Amenidades)

> **v2 (earmark money model).** This proposal supersedes the v1 draft. The change
> in the project since v1 is HOW payments are recorded: Collections now ships a
> generic **earmarked-payment ("earmark") subsystem** (already on `main`, used by
> Projects/aportaciones). The reservation money model is redesigned around earmark,
> which **deletes almost all the bespoke money code** from v1 (own payment table,
> own folio sequence, manual treasury posting, new Collections primitive) and
> replaces it by **reusing the existing `Payment` engine through a handler**. The
> rest of the design (FSM, availability, notifications, menu, double-booking /
> timezone / auth mitigations) is unchanged.

## Intent
Build a NEW Kivo7 module that lets residents reserve cluster amenities / common
areas (alberca, salón de eventos, asador, cancha, etc.) with an **activable cost
per amenity**. Greenfield: amenities/common areas were explicitly deferred to
v1.1+/v1.3 in `analisis_funcional_*` and `.project-context/modules.md`, so there
is zero duplication risk.

The module is built by **reusing existing infrastructure** — the Collections
**earmark `Payment` engine** (`payment/submit` + `payment/register` +
`payment/{id}/validate`), Treasury income recognition, Notification, Spatie Media,
the Nwidart/Orion module pattern, and the `payment` frontend — instead of
reinventing, and is shipped **incrementally and reviewably**, entirely **behind
the per-tenant flag `amenity_reservations.enabled=false`** until complete.

## Problem / Need
- Residents have no way to book common areas; administration is done manually
  (phone/WhatsApp), with no availability control, no double-booking protection,
  no cost collection, and no treasury reconciliation.
- The cluster needs amenities to optionally carry a base fee + deposit, recognize
  that money as real treasury income, and bill incident surcharges (damages) as
  real collectable debt.
- Design and seams already verified against real code by 3 explorations + 1
  adversarial risk-review + 1 slicing plan, then re-baselined onto the earmark
  engine. Product/process decisions confirmed with the user (see Locked Decisions).

## Repo state
The v1 branch clash no longer exists — `backend` is on **`main`, clean** (Package
reception + the earmark subsystem are already committed). We start from a clean
base on a single branch `feat/amenity-reservations`.

## Scope

### In scope
- `amenity`, `amenity_slot`, `amenity_blackout` — amenity catalog + availability config + gallery (Spatie Media).
- `amenity_reservation` — reservation lifecycle (FSM) with central catalog `reservation_status`.
- **Money via earmark:** the reservation fee/deposit/balance are **earmarked `Payment`s**
  (`earmark_type='amenity_reservation'`, `earmark_id=<reservation>`) recorded by the
  EXISTING payment engine. On confirmation, a new **`AmenityReservationEarmarkHandler
  implements PaymentEarmarkHandler`** creates a standalone charge (`charge_batch_id=null`)
  **pinned and fully paid** by the payment amount. Registered via `PaymentEarmarkRegistry::register(...)`.
- `reservation_surcharge` — incident surcharges as REAL standalone **open** Collections charges (FIFO / delinquency).
- Backend services: `AmenityAvailabilityService`, `ReservationService`,
  `AmenityReservationEarmarkHandler` (+ provider registration),
  `AmenityChargeApplicationObserver`, `AmenitySurchargeService`,
  `CancelExpiredReservationsCommand`.
- 12-event per-tenant notification matrix (category `AMENITIES`).
- Frontend: admin amenity CRUD + admin reservation inbox (incl. a **schedule-x**
  calendar of all reservations colored/filterable by amenity) + resident catalog
  (with a **schedule-x** availability calendar) + resident reservation flow
  (reusing the `payment` module sheets for the earmark payment) + `/my-reservations`.
- Dynamic-menu wiring under the "Fraccionamiento" system + per-tenant flag toggle (GA slice).
- New frontend dependency (approved): **schedule-x free MIT core** —
  `@schedule-x/react` + `@schedule-x/calendar` + `@schedule-x/events-service`.

### Out of scope (YAGNI / deferred)
- Online/card payments for reservations (manual proof + admin validation only, via the earmark engine).
- Automatic refund reversal (refund cash-out is **manual assisted** via `ExpenseService::register`;
  the payment reversal itself is free via `PaymentService::cancel`).
- Recurring/series reservations, waitlists, calendar-sync/ICS export.
- Cross-tenant amenity sharing.
- The schedule-x **premium resource-scheduler** (paid) — we color/filter by amenity on the free core instead.
- **REMOVED from v1** (no longer needed): `reservation_payment` table, `reservation_sequence`
  table, the `AR-YYYY-NNNNNN` folio, manual `BankAccountService::adjustBalance`, the
  `TreasuryReportService` income change, and the proposed `ChargeService::createStandaloneCharge()`.
- Any feature not required to ship the booking + earmark-payment + surcharge + notification flow behind the flag.

## Locked Decisions (confirmed with the user — do NOT re-decide)
1. **Money model = earmark (reuse `Payment`).** A reservation's base fee, deposit
   and balance are recorded as **earmarked payments** (`Payment` with
   `earmark_type='amenity_reservation'`, `earmark_id=<reservation>`) by the existing
   engine. On **confirmation**, the handler creates a charge that is **pinned and
   already fully paid** by the payment amount. There is never open debt for the fee
   before it is paid. (This reverses v1's "payments only, never charges": now there
   IS a charge, but it is born on confirm and already settled.)
2. **Surcharges = real collectable debt** (unchanged): incident/damage → **standalone
   OPEN charge** created directly by `AmenitySurchargeService` (precedent
   `ProjectActivationService::createChargeForUnit` / `DirectChargeService`), which
   enters FIFO / delinquency / the account statement.
3. **Treasury income = automatic.** `TreasuryReportService::monthlyInflow()` already
   counts **every CONFIRMED `Payment`** by `paid_at` (coupled to Payment, not to
   charge). A confirmed reservation payment shows up as income + in the bank balance
   **without touching Treasury**.
4. **Refund = manual assisted** (UX unchanged): the payment reversal is done by
   `PaymentService::cancel` (bank + applications + folio) for free; the cash-out to
   the resident is an expense via `ExpenseService::register`.
5. **SDD artifacts = `hybrid`** (openspec files + engram for cross-session recovery).
6. **Delivery = single branch `feat/amenity-reservations`** (from clean `main`, no
   parallel branches), sequential PRs to main per slice, each ≤400 lines and behind
   the flag. SDD execution mode: **interactive**.

## Reuse Strategy
- **Collections earmark engine (core of v2):** `PaymentEarmarkHandler` (contract),
  `PaymentEarmarkRegistry` (registration), `ContributionEarmarkHandler` (handler
  template), `ProjectsServiceProvider` (registration site),
  `ProjectActivationService::createChargeForUnit` (standalone-charge creation),
  `ProjectChargeApplicationObserver` (observer template), `PaymentService`
  (register/submit/validate/cancel + `applyConfirmedPayment` +
  `resolveEarmarkApplications`), `DirectChargeService` (ad-hoc charge + earmark-vs-direct
  decision doc), the `payment_one_pending_per_earmark` index (≤1 pending payment per earmark).
- **Treasury**: `TreasuryReportService::monthlyInflow` (income already recognized — NOT
  touched), `ExpenseService::register`/`void` (refund cash-out).
- **Collections (other)**: `ChargeStatus` (central catalog template),
  `RefreshChargeStatusesCommand` (per-tenant cron template), `ReceiptSequence`
  (unified `R-YYYY` folio, reused as-is), `ScopesToResidentUnits` (resident scoping).
- **Notification**: `NotifiesResidents` trait + `notifyEvent()`, `notification_setting`
  per-tenant config, category `AMENITIES`.
- **Spatie Media**: `gallery` (amenity, multi). Payment proof rides on the existing `Payment` media.
- **Frontend `payment` module**: `SubmitPaymentSheet` / `RegisterPaymentSheet` /
  `PaymentResource` (reused with `earmark_type`), FormData upload, and `App.tsx`
  `PermissionRoute` routing.
