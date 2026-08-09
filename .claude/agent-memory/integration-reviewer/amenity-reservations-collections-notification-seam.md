---
name: amenity-reservations-collections-notification-seam
description: AmenityReservations module S9-S11 cross-module seam into Collections (surcharges, payment reversal) and Notification (payment_rejected scoping); all verified clean, 0 criticals
metadata:
  type: pattern
---

Reviewed 2026-07-07, branch `feat/amenity-reservations` (uncommitted, new module `Modules/AmenityReservations/`), scope S9-S11. This is a cross-MODULE backend seam (AmenityReservations ↔ Collections/Notification), not the frontend↔backend contract this agent usually reviews — delegated explicitly by the orchestrator for this task.

**S9 — AmenitySurchargeService (admin incident charges):**
- Creates a real OPEN `Charge` on the reservation's unit via `Charge::create([...])`, deliberately WITHOUT `amenity_reservation_id` (stays null). Linked to the reservation only via a new `reservation_surcharge` join row (reservation_id, charge_id, charge_concept_id, amount, description, created_by).
- `AmenityReservationChargeApplicationObserver::sync()`/`deleted()` early-return on `$charge->amenity_reservation_id === null` — confirmed this correctly EXCLUDES surcharge charges, so a normal FIFO payment against a surcharge never touches the reservation's `amount_outstanding`. Verified in code AND by test `AmenityReservationSurchargeTest::test_a_plain_fifo_payment_can_pay_off_the_surcharge_charge`.
- `ChargeCreateRequest`/`ChargeUpdateRequest` add `'amenity_reservation_id' => ['prohibited']` (API-facing guard against spoofing) — the Service bypasses FormRequest validation by design (internal `Charge::create()`), same established pattern as `community_project_id`.

**S10 — AmenityReservationPaymentRejectionObserver:**
- Registered via `Payment::observe(...)` in `AmenityReservationsServiceProvider::boot()` (mirrors `ProjectChargeApplicationObserver` precedent) — coexists fine with Payment's `#[ObservedBy([GlobalObserver::class])]` attribute; Laravel supports both mechanisms on the same model without conflict.
- Scoped via a DOUBLE guard: `wasChanged('payment_status_id') && new===CANCELLED` AND `getOriginal('payment_status_id')===PENDING_VALIDATION`. `PaymentService::reject()` does exactly that transition (fires the observer); `PaymentService::cancel()` (used by reservation reversal, CONFIRMED→CANCELLED) does NOT match `getOriginal===PENDING_VALIDATION` → observer never fires on reversal. Confirmed by test `AmenityReservationNotificationWiringTest::test_direct_payment_cancel_reversal_does_not_fire_a_settlement_notification` (asserts 0 notifications on a CONFIRMED→CANCELLED direct cancel).
- Early-return on `$payment->earmark_type !== AmenityReservationEarmarkHandler::EARMARK_TYPE` isolates it from all non-amenity payments (Collections' own reject/cancel flow untouched).

**S11 — ReservationService::expire() (cron auto-cancel):**
- Mirrors `cancel()`'s exact re-fetch-after-reversal pattern already established in S8: `reverseConfirmedEarmarkPayments()` → re-`lockReservation()` → THEN write `CANCELLED` — so the final CANCELLED write always wins over `AmenityReservationChargeApplicationObserver`'s transient recompute (which can set `PENDING_PAYMENT`/`CONFIRMED` mid-reversal). No stale-instance bug — this concern was already solved when S8 was built and S11 correctly reused the same fix, not a new risk.
- Explicitly tested: `CancelExpiredReservationsCommandTest` case (B) — a CONFIRMED reservation with a confirmed deposit earmark payment expires: final state asserted CANCELLED (not the observer's transient status), `amount_outstanding` back to full cost, `Payment` row CANCELLED, bank balance reverted to 0.

**Test suite status (all run against host php + Postgres `testing` DB, single process):**
- `Modules/AmenityReservations/tests` → 137 passed (538 assertions).
- `Modules/Collections/tests` + `Modules/Notification/tests` → 97 passed (385 assertions).
- Root `tests/Feature/Notification/NotificationSettingSeedingTest.php` (asserts central `NotificationEvent` catalog count, bumped 10→22 for the 11 new `amenity_reservation.*` events) → 11 passed.

**S13 frontend contract (surcharges endpoint, for whoever builds it):**
`POST /api/amenity_reservation/{id}/surcharges` — auth: Sanctum + tenant init + `update.amenity_reservation` (board/admin only, enforced explicitly in the controller via `$this->authorize(...)`, in addition to route middleware).
- Body: `{ charge_concept_id: string (uuid, must be an active, non-deleted charge_concept of the CURRENT tenant), amount: integer (minor units, min 1, max 999999999 = MoneyLimit::MAX_MINOR_UNITS), description?: string|null (max 500) }`.
- 422 business-rule gate: reservation must be CONFIRMED or COMPLETED, else `{message: "Solo se puede aplicar un cargo adicional a una reservación confirmada o completada."}` — NOTE this 422 has NO `errors` key (not a FormRequest validation error), while charge_concept_id/amount validation failures DO return the standard `{message, errors: {field: []}}` shape — frontend must handle BOTH 422 shapes on this one endpoint.
- 404 on cross-tenant or nonexistent reservation id (`findOrFail`).
- 201 success: `{ data: ReservationSurcharge }` with `amount` as a Money object (`TenantMoneyCast` — `{amount, currency, formatted}`, never a raw int) and nested `charge` + `charge_concept` relations pre-loaded (`->fresh(['charge', 'charge_concept'])`).
- The reservation itself is intentionally UNCHANGED by this call (no `amount_outstanding`/status mutation) — the frontend must NOT expect "my reservations" balance to reflect a surcharge; it shows up as a normal charge in the unit's Collections statement instead. To list a reservation's surcharges, request `include=surcharges,surcharges.charge,surcharges.charge_concept` on the existing `GET /amenity_reservation/{id}` (already whitelisted in `AmenityReservationController::includes()`) — there is no separate list endpoint.

Overall: 0 criticals, 0 warnings on this seam — all three raised concerns were already correctly handled by the implementation, and each has a dedicated regression test. This is a rare "clean" review; worth noting the module consistently follows the established S8 precedent (reversal + re-lock pattern, PaymentEarmarkRegistry, observer registration in the module's own ServiceProvider) rather than inventing new patterns — that discipline is what kept S9-S11 free of the classic stale-instance/double-notification bugs seen in similar cross-module accounting bridges.
