# Proposal: Amenity Payment Hardening

> Follow-up correction to the built `amenity-reservations` module. Motivated by a
> 5-expert audit (Engram topic `amenity-reservations/payment-hardening`, obs #1529).
> User decisions are LOCKED — formalize, do not redesign.

## Intent

Fix the amenity reservation payment lifecycle in the gap between "resident pays"
and "treasurer validates". Audit findings:

- **P0**: expiry cron cancels a reservation the resident paid on time — it ignores
  PENDING_VALIDATION earmark payments → lost spot + orphaned un-validatable payment.
- **P0**: "Pagar"/"Registrar pago" dead-end — a pending payment does not move
  `amount_outstanding`, so the button stays live and 422s; the resident cannot see
  or withdraw their own pending payment.
- **P1**: cash self-submit with no proof (phantom-payment root); dead `payment_timing`
  field; a no-deposit costed amenity confirms on ANY token partial (slot-squatting);
  `cancel()`/`expire()` reverse only CONFIRMED payments (pending ones orphaned);
  FE balance payment defaults to deposit instead of remaining outstanding (under-pay).
- **P2**: payment deadline invisible to resident; deposit floor not client-validated.

The rest of the module (tenancy, ledger, FSM/locks, authz, timezone, money
serialization) verified correct — only the payment lifecycle changes.

## Locked Decisions (user, 2026-07-10)

1. **No resident cash.** Resident sheet offers only transfer/deposit/check, all
   requiring a comprobante. Cash stays admin-register-only (born confirmed).
   Backend also rejects cash on resident-submitted amenity earmark payments.
2. **Remove `payment_timing` entirely** (column + FK, PaymentTiming catalog model,
   CatalogSeeder entry, mapperSelects entry, requests, FE schema/form/CatalogType).
   `pay_upfront` is NOT implemented.

## Scope

### In Scope
Slices (each a reviewable PR):
- **A — Backend money lifecycle**: expiry cron grants grace when a pending earmark
  payment exists; cancel/expire also reject pending earmark payments; a costed
  amenity with NO deposit confirms only at `outstanding == 0` (deposit-confirm
  branch gated on deposit > 0; with-deposit flow unchanged).
- **B — Pending-payment visibility**: computed `has_pending_payment` (backend) +
  FE gating of pay buttons + "Pago en revisión" state.
- **C — Cash restriction**: resident sheet methods + backend guard (decision 1).
- **D — Remove `payment_timing`** across the stack (decision 2), incl. its tests.
- **E — FE polish**: balance payment defaults to outstanding (deposit only on
  first payment); client-side deposit-floor validation; show `payment_deadline`.
- **Optional**: resident "withdraw my pending payment" — lower urgency now that
  cash is gone; design phase decides include/defer.

### Out of Scope (separate decisions)
- Refund egreso recording (cross-cutting Collections).
- Narrowing the occupancy endpoint audience.
- Amenity-level lock granularity.
- Late-cancellation penalty.

## Capabilities

### New Capabilities
None.

### Modified Capabilities
- `amenity-reservations`: payment lifecycle requirements change (pending-payment
  grace, confirm gate, cash restriction, `payment_timing` removal). No
  `openspec/specs/` exists yet — delta applies against
  `openspec/changes/amenity-reservations/spec.md`.

## Approach

Correct the lifecycle inside the existing earmark seam — no new payment engine,
no schema additions beyond dropping `payment_timing`. Backend gates are
authoritative; FE mirrors them (defense in depth). Design phase decides HOW,
consulting collections-expert on the money FSM.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `backend/Modules/AmenityReservations/app/Console/Commands/CancelExpiredReservationsCommand.php` | Modified | Grace for pending earmark payments (A) |
| `backend/Modules/AmenityReservations/app/Services/ReservationService.php` | Modified | Reject pending on cancel/expire; confirm gate; `has_pending_payment` (A, B) |
| `backend/Modules/Collections/app/Services/PaymentService.php` (or amenity seam) | Modified | Reject resident cash on amenity earmark (C) |
| `backend/Modules/AmenityReservations/app/Models/{Amenity,PaymentTiming}.php` + migration + `CatalogSeeder` + mapperSelects + requests + tests | Removed/Modified | `payment_timing` teardown, 24 files (D) |
| `frontend/src/modules/{amenity,reservation}/**` + payment sheets | Modified | Gating, "Pago en revisión", methods, defaults, deadline (B, C, E) |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Cron grace lets reservations linger if payment rejected | Med | Rejection resumes expiry (rejection observer already exists) |
| `payment_timing` drop is destructive | Med | Dedicated drop migration with working `down()`, isolated PR (D) |
| Confirm-gate change hits in-flight partially-paid no-deposit reservations | Low | Design phase defines transition rule for existing rows |

## Rollback Plan

Each slice is an independent PR behind the existing per-tenant flag
`amenity_reservations.enabled` — revert per PR. Slice D ships as its own PR with
a reversible migration.

## Dependencies

- Collections earmark engine (`PaymentService` submit/register/validate/cancel) — exists.
- collections-expert consultation during design (per approved plan).

## Success Criteria

- [ ] A reservation with a PENDING_VALIDATION earmark payment is never auto-expired.
- [ ] Cancel/expire leaves no orphaned pending earmark payment.
- [ ] Costed amenity without deposit confirms only when outstanding == 0.
- [ ] Resident cannot submit cash for an amenity (UI + API).
- [ ] `payment_timing` absent from schema, catalog, selects, requests, FE.
- [ ] Resident sees pending-payment state and payment deadline; pay buttons gated.
- [ ] Balance (2nd) payment defaults to remaining outstanding.
