# Delta for Amenity Reservations — Payment Hardening

> Base: no `openspec/specs/` baseline exists yet; this delta applies against
> `openspec/changes/amenity-reservations/spec.md` (R1–R11 + Scenarios table).
> Only the payment lifecycle changes; R1, R2, R3, R5, R7, R8, R10, R11 are unaffected.

## MODIFIED Requirements

### Requirement: Earmark Money Flow — Submission Method and Confirm Gate

A reservation fee/deposit/balance is still recorded as a `Payment` tagged
`earmark_type='amenity_reservation'` via `payment/submit` (resident) or
`payment/register` (admin). The submission MUST reject `method=cash` when
initiated by a resident; only `transfer`/`deposit`/`check` are accepted, each
requiring a comprobante. Admin-registered payments MAY use cash (born
`confirmed`). A costed amenity with `deposit_amount = 0` MUST confirm only
when `amount_outstanding` reaches zero; the deposit-confirm branch (partial
payment confirms, balance stays due) applies ONLY when `deposit_amount > 0`.
The first payment (deposit or full) MUST NOT be below `deposit_amount` when
one is configured. A second earmark payment on an already-confirmed reservation
MUST default its suggested amount to `amount_outstanding`, not `deposit_amount`.
(Previously: any resident method including cash was accepted with no comprobante
requirement; a no-deposit costed amenity confirmed on ANY partial payment
regardless of size, enabling slot-squatting; the balance payment's suggested
amount was the deposit amount.)

#### Scenario: Resident payment via comprobante method (unchanged)
- GIVEN a reservation in `pending_payment` with `amount_outstanding > 0`
- WHEN the resident submits transfer/deposit/check with amount ≤ `amount_outstanding`
- THEN it is created `pending_validation`; once validated it produces a standalone fully-paid charge and advances the reservation

#### Scenario: Resident attempts cash — rejected
- GIVEN a reservation payable by a resident
- WHEN the resident submits with `method=cash`
- THEN `validateSubmission` rejects it; only transfer/deposit/check are accepted

#### Scenario: Admin registers cash — allowed
- GIVEN a reservation payable by admin action
- WHEN the admin calls `payment/register` with `method=cash`
- THEN the payment is created and born `confirmed`

#### Scenario: No-deposit amenity, token partial does not confirm
- GIVEN `deposit_amount = 0`, `cost_amount = $150`
- WHEN a $10 payment is validated
- THEN the reservation stays `pending_payment`; `amount_outstanding = $140`

#### Scenario: No-deposit amenity, paid in full confirms
- GIVEN the same reservation
- WHEN the remaining $140 is validated (`amount_outstanding` reaches 0)
- THEN the reservation becomes `confirmed`

#### Scenario: With-deposit amenity (unchanged)
- GIVEN `deposit_amount > 0`
- WHEN the deposit payment is validated
- THEN the reservation becomes `confirmed`, `amount_outstanding = cost − deposit`, `balance_due_date` set

#### Scenario: Balance payment defaults to outstanding, not deposit
- GIVEN a confirmed reservation with a pending balance
- WHEN the resident opens the second (balance) payment
- THEN the suggested amount equals `amount_outstanding`, not `deposit_amount`

#### Scenario: Deposit floor enforced on first payment
- GIVEN `deposit_amount = $50`
- WHEN a first payment of $10 is submitted (client) or registered (admin)
- THEN it is rejected client-side and server-side (below deposit floor)

#### Scenario: Pre-existing confirmed reservations are not retroactively affected
- GIVEN a reservation already `confirmed` under the old no-deposit-any-partial rule before this change ships
- WHEN the new confirm gate goes live
- THEN it remains `confirmed`; the stricter gate applies only to reservations not yet confirmed at cutover

### Requirement: Cancellation Reversal — Rejects Pending Payments Too

Cancelling/rejecting/auto-cancelling a reservation MUST reject any
`pending_validation` earmark payment (no orphans) IN ADDITION TO reversing
every `confirmed` earmark payment via `PaymentService::cancel`, exactly as before.
(Previously: cancel/expire reversed only `confirmed` payments, leaving any
`pending_validation` payment orphaned and un-validatable.)

#### Scenario: Cancel with a pending payment
- GIVEN a reservation being cancelled/expired with a `pending_validation` earmark payment
- WHEN cancellation/expiry runs
- THEN that pending payment is rejected, not left orphaned

#### Scenario: Cancel with a confirmed payment (unchanged)
- GIVEN a reservation being cancelled with one or more `confirmed` earmark payments
- WHEN cancellation runs
- THEN each is reversed via `PaymentService::cancel` (bank debit, folio bookkeeping)

### Requirement: Expiry Cron — Grace for Pending-Validation Payments

The daily cron MUST NOT auto-cancel a `pending_payment` reservation past its
`payment_deadline` while it has a `pending_validation` earmark payment (grace).
Once that payment is rejected, the reservation becomes eligible for
auto-cancellation again on the next run.
(Previously: the cron cancelled any reservation past `payment_deadline`
regardless of an in-flight payment, orphaning a payment the resident made on time.)

#### Scenario: Pending-validation payment holds off expiry
- GIVEN a `pending_payment` reservation whose `payment_deadline` has passed AND it has a `pending_validation` payment
- WHEN the daily cron runs
- THEN the reservation is NOT auto-cancelled

#### Scenario: Rejection resumes expiry
- GIVEN the same reservation
- WHEN the admin rejects that pending payment
- THEN the reservation is eligible for auto-cancellation on the next cron run once past its deadline

#### Scenario: No pending payment, past deadline (unchanged)
- GIVEN a reservation past `payment_deadline` with no pending payment
- WHEN the cron runs
- THEN it is auto-cancelled and any confirmed payment is reversed

## ADDED Requirements

### Requirement: Pending Payment Visibility and Action Gating

The system MUST expose a computed `has_pending_payment` state per reservation
and gate payment-initiating actions while one exists.

#### Scenario: Resident sees "Pago en revisión"
- GIVEN a reservation with a `pending_validation` earmark payment
- WHEN the resident views it
- THEN `has_pending_payment = true` and the UI shows "Pago en revisión" instead of the amount owed

#### Scenario: Pay actions are hidden while pending
- GIVEN the same reservation
- WHEN the resident or admin views available actions
- THEN "Pagar" (resident) and "Registrar pago" (admin) are not offered; any repeat submission attempt is still rejected server-side by the existing `payment_one_pending_per_earmark` guard

#### Scenario: Payment deadline is visible
- GIVEN a reservation in `pending_payment`
- WHEN the resident views it
- THEN `payment_deadline` is displayed

### Requirement: No Payment Timing Selector

The system MUST NOT expose or persist a payment-timing selection anywhere in the stack.

#### Scenario: Field absent end-to-end
- GIVEN this change has shipped
- WHEN inspecting the reservation schema, the catalog list, Create/Update requests, and the FE form
- THEN no `payment_timing` column, `PaymentTiming` catalog rows, request field, or FE field exists

#### Scenario: Legacy value is not processed
- GIVEN a client sends a legacy `payment_timing` value
- WHEN the request is processed
- THEN the value is ignored (field not accepted) — it never influences reservation status
