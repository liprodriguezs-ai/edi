---
name: amenity-reservation-pending-payment-gap
description: Earmark payment lists/buttons must not gate on amount_outstanding alone — pending payments are invisible until confirmed, causing re-offered "Pagar" 422 dead-ends
metadata:
  type: pattern
---

Audit of `frontend/src/modules/amenity/**` + `frontend/src/modules/reservation/**` (2026-07-10, audit-only, no edits).

**Core FE logic trap (P0):** For earmark payments (amenity_reservation, and by symmetry project_contribution), a PENDING_VALIDATION payment does NOT change `amount_outstanding` — that field is recomputed only from CONFIRMED `PaymentApplication` rows (`AmenityReservationChargeApplicationObserver` fires on application `created`, which only exists post-confirmation). So any UI that gates a "Pagar"/"Registrar pago" button on `status ∈ payable && amount_outstanding > 0` will KEEP offering the button after a submit, and the backend one-pending guard (`AmenityReservationEarmarkHandler::validateSubmission`) rejects the 2nd submit with 422 "Ya hay un pago pendiente de validación". `MyReservationsPage` never fetches payments and the reservation entity exposes NO pending flag (model has no appends, controller `includes()` has no payments) → resident is stuck with zero UI feedback. Fix pattern: backend should append `has_pending_payment` and FE must gate on `!has_pending_payment` + show a "Pago en validación" state.

**Resident payment sheet cash trap (P1):** `SubmitReservationPaymentSheet` defaults method=CASH and only requires a comprobante for non-cash. A resident cash-with-no-proof self-report is unverifiable AND consumes the one-pending slot → manufactures the P0 dead-end. Resident self-service sheets should not offer CASH (that's the admin `RegisterReservationPaymentSheet`'s job) or must require proof for all methods.

**Amount default asymmetry (P2):** resident sheet defaults amount to `min(deposit, outstanding)` (good for FIRST/deposit payment, wrong for the balance payment where it under-fills); admin sheet correctly defaults to full outstanding. Distinguish first-installment (`outstanding === cost` snapshot) from balance. Also: resident sheet validates the ceiling (`> outstanding`) but not the deposit FLOOR on first payment → preventable 422.

**Deadline visibility (P2):** `payment_deadline` shown on admin detail but NOT on `MyReservationsPage`; resident has no detail view, so a PENDING_PAYMENT reservation silently expires via cron.

**Verified-good patterns (already fixed in prior E2E rounds, don't re-flag):** `resolvePostCreateOutcome` branches on the backend-returned status (not `amenity.has_cost`); `displayableOutstandingAmount` + `RESERVATION_PAYABLE_STATUSES` suppress "Pagar" and pin $0 on terminal statuses with frozen outstanding; wizard→payment chain always has `bank_account` (browse-detail DETAIL_INCLUDE); cancel dialog gates reason ≥5 chars matching backend `min:5`; money uses `Math.round`/minor-units consistently.
