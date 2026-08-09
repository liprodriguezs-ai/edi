---
name: amenity-reservations-earmark-seam
description: S8 earmark payment plug-in (AmenityReservations -> Collections Charge/PaymentEarmarkHandler) - verified 2026-07-06, 199 tests green
metadata:
  type: pattern
---

Reviewed feat/amenity-reservations S8 (uncommitted, backend-only). AmenityReservations
plugs into Collections' earmark subsystem exactly like Projects/ContributionEarmarkHandler
(the established template): PaymentEarmarkHandler contract, PaymentEarmarkRegistry::register
from the consumer module's ServiceProvider::boot(), a consumer-owned ALTER migration adding
a nullable+nullOnDelete FK column to `charge` (mirrors add_community_project_id_to_charge_table).
Zero Collections files touched except Charge.php ($fillable + belongsTo relation) - the
plug-in pattern is genuinely additive.

**Live-verified 2026-07-06**: `php artisan test Modules/Collections/tests
Modules/AmenityReservations/tests` -> 199 passed, 789 assertions, 0 failures (365s, host php,
single process). Collections test-method count (97) matched the apply agent's claim exactly
via static grep; AmenityReservations was 102 (not the claimed "206" - unreconciled but
harmless, live run is the ground truth).

**Real drift found (WARNING, not exploitable today)**: when a consumer module adds an
earmark FK column to `charge` and puts it in `$fillable`, the convention (established by
`community_project_id`) is to ALSO add `'{col}' => ['prohibited']` to
`ChargeCreateRequest`/`ChargeUpdateRequest` (both in Collections) as defense-in-depth
documentation. This PR's `amenity_reservation_id` skipped that step. Not live-exploitable
because `ChargeController::performStore`/`performUpdate` use explicit `Arr::only()`
allowlists (not `$fillable`-driven), and Laravel's `$request->validated()` silently drops
any key absent from `rules()` regardless of `prohibited` — so today it's purely a
consistency/hardening gap, worth flagging but not blocking. Check for this same drift
pattern (`{new_fk} => prohibited` missing on Charge's create/update requests) on any FUTURE
module that plugs a new earmark FK into `charge`.

**Real forward-looking gotcha for S13/S15 (frontend, not built yet)**: unlike
`ContributionEarmarkHandler` (Projects), `AmenityReservationEarmarkHandler::validateSubmission`
enforces `bank_account_id === $amenity->bank_account_id` (backend/Modules/AmenityReservations/
app/Handlers/AmenityReservationEarmarkHandler.php:51). But `PaymentController::submit()`
(backend/Modules/Collections/app/Http/Controllers/PaymentController.php:215-227)
unconditionally defaults `bank_account_id` to the cluster's PRIMARY account when the field is
absent — and the current `SubmitPaymentPayload` TS type
(frontend/src/modules/payment/services/PaymentResource.ts:44-55) has NO `bank_account_id`
field at all (by design, since resident self-capture normally doesn't pick an account). A
naive S13/S15 build that copies the existing project-contribution submit UI will silently
send no bank_account_id, get defaulted to the wrong account, and 422-reject every resident
amenity payment. Fix path: extend `SubmitPaymentPayload` with an earmark-only
`bank_account_id`, auto-filled read-only from `amenity.bank_account_id` when
`earmark_type === 'amenity_reservation'`. Also: `EARMARK_TYPE` const
(frontend/src/modules/payment/schema.ts:21-23) only has `PROJECT_CONTRIBUTION` today —
`amenity_reservation` needs to be added when the FE module is built.

Tenancy: confirmed clean (AmenityReservation, Charge, PaymentApplication, ChargeConcept all
`BelongsToTenant`; handler/observer never use raw DB:: or withoutGlobalScope).
Error-shape: `\InvalidArgumentException` -> 422 `{message}`, identical across
register/submit/validatePayment/reject — same contract as the Contribution flow.

**FE SEAM AUDIT 2026-07-10 (S13/S15/S16 frontend, dead-end focus).** Most FSM action
gating is EXACT-mirror-aligned: approve/reject (PENDING_APPROVAL), cancel (ACTIVE_STATUSES),
complete/no_show (CONFIRMED), surcharge (CONFIRMED|COMPLETED) all match the service guards;
reject/cancel reason min:5 both sides; surcharge amount integer via `pesosToMinorUnits`
(Math.round, always int); money all `TenantMoneyCast`->IMoney read via moneyToMinorUnits;
reserve wizard reads the BACKEND-returned status (no PENDING_APPROVAL pay dead-end); occupancy
shape is an exact PII-free mirror (event id derived from amenity+date+slot, never reservation
id/unit/user); status labels come from the backend catalog (no drift), tone default->red
covers cancelled/rejected/no_show. formatApiErrorMessage surfaces the 422 `{message}` as the
toast title, so no untranslated leak.

THE dead-end (P0, calibration case CONFIRMED): the earmark pay path has FIVE backend guards
in `AmenityReservationEarmarkHandler::validateSubmission` (module-enabled, unit match,
assertPayable status+outstanding, bank_account==amenity.bank_account, total<=outstanding,
first-payment>=deposit, **and hasPendingReport -> 422 "Ya hay un pago pendiente de
validación..."**). The FE `canPay`/`canRegisterPayment` only replicate assertPayable
(RESERVATION_PAYABLE_STATUSES + outstanding>0) — NOT the pending-payment guard. A
PENDING_VALIDATION payment does NOT move amount_outstanding (only PaymentApplication on
VALIDATE does), so the reservation still reads PENDING_PAYMENT/outstanding>0 and "Pagar"
stays live -> full sheet -> 422 dead-end. Worse for residents: MyReservationsPage
(frontend/src/modules/reservation/MyReservationsPage.tsx) only includes amenity+
reservation_status (no payments), residents lack `read.payment` (/payment/search 403), and
the PaymentsCard is admin-only — so a resident has ZERO visibility of their own pending
payment and NO path to cancel it. Admin detail (AmenityReservationDetailPage) at least
renders PaymentsCard, so the admin has context + can validate/cancel. Fix path: expose a
`has_pending_payment` computed bool on the AmenityReservation resource; FE hides Pagar and
shows a "Pago en revisión" pill when true.

Secondary (P1, overlaps MoneyLogicAudit): resident can `cancel` a PENDING_PAYMENT reservation
that has a PENDING_VALIDATION payment; `reverseConfirmedEarmarkPayments` only reverses
CONFIRMED payments, so the pending one is orphaned; if an admin later validates it,
`syncFromChargeApplication` early-returns (terminal reservation) — money is collected into a
standalone paid charge against a dead reservation with no refund link. P2: SubmitReservation
PaymentSheet guards the outstanding MAX client-side but not the deposit MIN on a first
payment (recoverable 422, clear message).
