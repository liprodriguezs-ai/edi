---
name: amenity-reservation-events
description: The 11 amenity_reservation.* events (S10, 2026-07-07) — consts, fire points, recipient design, dotted-key email-template landmine, payment_rejected observer hook
metadata:
  type: project
---

## Events added (S10, 2026-07-07)

11 events, category `NotificationCategory::AMENITIES` (new const). Keys use a DOT
segment (`amenity_reservation.created`, not underscore) — this is DELIBERATE per
the task spec, but see the email landmine note below. UUID consts on
`NotificationEvent` (`AMENITY_RESERVATION_*`), seeded in
`CatalogSeeder::seedNotificationEvents()` (order 13-23), fallback title/body in
`Modules/Configuration/database/seeders/NotificationTemplateSeeder.php`
(`notification_template.amenity_reservation.*` Setting rows).

| Key | Audience | Fire point |
|---|---|---|
| `amenity_reservation.created` | board (cosmetic) | `ReservationService::create()` — always |
| `amenity_reservation.approved` | resident | `ReservationService::approve()` — always |
| `amenity_reservation.rejected` | resident | `ReservationService::reject()` |
| `amenity_reservation.payment_required` | resident | `create()`/`approve()` when the reservation lands/moves into `PENDING_PAYMENT` |
| `amenity_reservation.deposit_confirmed` | resident | `syncFromChargeApplication()` — PENDING_PAYMENT→CONFIRMED, outstanding>0 (deposit met) |
| `amenity_reservation.payment_confirmed` | resident | `syncFromChargeApplication()` — outstanding reaches 0 |
| `amenity_reservation.payment_rejected` | resident | NEW `AmenityReservationPaymentRejectionObserver` on `Payment` (see below) |
| `amenity_reservation.cancelled` | resident (+board) | `ReservationService::cancel()` — TWO `notifyEvent` calls |
| `amenity_reservation.surcharge_added` | resident | `AmenitySurchargeService::create()` |
| `amenity_reservation.payment_reminder` | resident | DEFINED only — public `ReservationService::notifyPaymentReminder(AmenityReservation)`, S11 cron calls it |
| `amenity_reservation.expired` | resident | DEFINED only — public `ReservationService::notifyExpired(AmenityReservation)`, S11 cron calls it |

No `deposit_returned` event — task explicitly said the deposit is an anticipo
(part of cost), never a refundable garantía. tasks.md S10 bullet originally said
"12 events" incl. deposit_returned — that was stale; corrected when marking S10 done.

## Recipient design — administrator is NOT a board_role

`administrator` is a Spatie role but NOT a row in the `board_role` catalog (only
president/treasurer/secretary/vocal/oversight_committee are). So it can never come
out of `boardRecipientsForEvent()`. Pattern used (mirrors
`BillingNotificationService::notifyTrialExpired`'s precedent of a cosmetic
`audience=board` with fixed-role resolution):

- **`created`**: recipients = `boardRecipientsForEvent('amenity_reservation.created', $resolved)` (CONFIGURABLE via the board-role picker, default seeded President+Treasurer in `TenantNotificationSettingSeeder::seedAmenityReservationCreatedBoardRoles()`) **UNION** `userIdsWithRole('administrator')` (FIXED, never board-role-configurable).
- **`cancelled`**: resident gets one `notifyEvent` call (`/my-reservations`); a SEPARATE `notifyEvent` call (same `$resolved`, avoids double-resolve) targets a FIXED role set `userIdsWithRole(['president','treasurer','administrator'])` with `/amenity-reservation` click_action — NOT the configurable board-role picker (this is a secondary heads-up, not the primary audience).

## Title collision gotcha — 'Pago confirmado' is taken

Collections' `PaymentService::validatePayment()` fires the generic `payment_accepted`
event titled **"Pago confirmado"** on EVERY payment validation, INCLUDING amenity
earmark payments (it doesn't check `earmark_type`). The amenity
`payment_confirmed` event therefore uses a DIFFERENT title, **"Reservación
liquidada"**, so residents (and tests) can tell the two notifications apart. Learned
the hard way: a test asserting "no 'Pago confirmado' on deposit-only settle" failed
because the GENERIC Collections notification fires regardless of deposit vs full —
unrelated to the amenity-specific logic. `deposit_confirmed`'s title ("Depósito
confirmado") has no such collision.

## payment_rejected hook — Payment observer, Collections untouched

`PaymentService::reject()` (PENDING_VALIDATION→CANCELLED) already fires the generic
`payment_rejected` event to the billing owner (financial-language copy) — NOT
earmark-aware. Rather than touch Collections, added
`Modules/AmenityReservations/app/Observers/AmenityReservationPaymentRejectionObserver.php`,
registered via `Payment::observe(...)` in `AmenityReservationsServiceProvider::boot()`
(mirrors the existing `PaymentApplication::observe(AmenityReservationChargeApplicationObserver::class)`
pattern for S8). Scoped to `earmark_type==='amenity_reservation'` AND a STRAIGHT
reject (`getOriginal('payment_status_id') === PENDING_VALIDATION`, not
CONFIRMED→CANCELLED which is a reservation-driven reversal already covered by
`amenity_reservation.cancelled` or has no event). Residents get BOTH the generic
Collections notification AND this amenity-specific one on rejection — deliberate,
independently configurable per tenant, not a bug.

## Email landmine — dotted event keys break BladeEmailChannel's naming contract

`BladeEmailChannel::renderContent()` does `str_replace('_','-', $eventKey)` only —
a `.` in the key (`amenity_reservation.created`) survives untouched into the Blade
VIEW NAME (`email::amenity-reservation.created`), and Laravel's view resolver treats
`.` as a DIRECTORY separator, so it would look for
`amenity-reservation/created.blade.php`, NOT a flat
`amenity-reservation.created.blade.php`. **All 11 events therefore ship with
`supports_email=false` and no `blade_email` in `default_channels`** (push+in-app
only) — deliberately avoiding the landmine rather than hitting it. If email
templates are ever added for these events: either the dotted-key convention needs
an exception in `BladeEmailChannel`, or the Blade file must live at the NESTED path
Laravel's dot-resolution expects.

**RESUELTO 2026-07-19 (change amenity-notification-emails):** `BladeEmailChannel`
now flattens `.` too (`str_replace(['_', '.'], '-')`) and 5 of the 11 events
(created, approved, payment_confirmed, payment_rejected, surcharge_added) ship
`supports_email=true` + `blade_email` in defaults, with flat blades compiled.
E2E-verified vs Mailpit — see [[amenity-email-qa-2026-07-19]].

## Tests

`Modules/AmenityReservations/tests/Feature/AmenityReservationNotificationWiringTest.php`
— 11 tests, all passing standalone AND as part of the full `Modules/AmenityReservations/tests`
suite (129 passed total, 2026-07-07). Covers: created→board+admin (not resident),
payment_required on create of a costed amenity, approved/rejected/cancelled→resident
(+board for cancelled, distinct click_actions), surcharge_added, deposit_confirmed
vs payment_confirmed (title collision-aware), a direct-payment-cancel reversal NOT
re-firing a settlement notification, payment_rejected via the new observer, and the
per-tenant `is_active` gate.

## S11 handoff (cron — not yet implemented as of 2026-07-07)

`ReservationService::notifyPaymentReminder(AmenityReservation $reservation)` and
`::notifyExpired(AmenityReservation $reservation)` are PUBLIC, already wired to
their catalog events/templates/recipients (`unitResidentUserIds`). S11's cron
(`amenities:cancel-expired-reservations` / a reminder command) should just query the
due/expired reservations and call `app(ReservationService::class)->notifyPaymentReminder($r)`
/ `->notifyExpired($r)` per row — `notifyExpired()` only fires the notification, S11
owns the actual FSM transition (auto-cancel + earmark reversal via
`PaymentService::cancel()`) and decides the call order relative to that.
