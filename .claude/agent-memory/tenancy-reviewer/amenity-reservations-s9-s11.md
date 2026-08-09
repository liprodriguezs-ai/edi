---
name: amenity-reservations-s9-s11
description: AmenityReservations module isolation — S9 (surcharges), S10 (notifications), S11 (expiry cron) — all SAFE, no findings
metadata:
  type: project
---

Reviewed 2026-07-07 on branch `feat/amenity-reservations` (uncommitted). Continues [[amenity-reservations-module-isolation]] (S1-S5, also SAFE). No CRITICAL/WARNING findings across S9-S11.

**S9 — reservation_surcharge (admin-imposed incident charges):**
- `reservation_surcharge` table: `BaseModel::columns($table, true)` + model `use BelongsToTenant` — correctly tenant-scoped.
- Deliberate design invariant (documented in both migration and model docblocks): the standalone `charge` a surcharge creates NEVER sets `amenity_reservation_id` (that column is reserved for the reservation's OWN cost/deposit charge, read by `AmenityReservationChargeApplicationObserver`). Traceability flows only through `reservation_surcharge.charge_id`. This is a data-modeling separation, not a tenancy concern, but it explains why two different FK paths exist on `charge` pointing at amenity data.
- `AmenitySurchargeService::create()`: row-locks the reservation via `AmenityReservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail()` (never trusts the caller's instance, never `withoutGlobalScope`), creates `Charge` with `unit_id` taken from that locked/tenant-scoped read (no cross-tenant unit reference possible), then creates `ReservationSurcharge`. All three models (`AmenityReservation`, `Charge`, `ReservationSurcharge`) are tenant-scoped via `BelongsToTenant`.
- `AmenityReservationSurchargeRequest`: `charge_concept_id` exists-rule correctly scoped `->where('tenant_id', tenant('id'))` (tenant-scoped catalog validated per stancl-tenancy §8).
- Controller's `surcharges()` action gates on `update.amenity_reservation` (board/admin only) before resolving the reservation via `findOrFail` (tenant-scoped).

**S10 — notifications:**
- All new events (`amenity_reservation.created/approved/rejected/payment_required/deposit_confirmed/payment_confirmed/payment_rejected/cancelled/surcharge_added/payment_reminder/expired`) added to `NotificationEvent` (CENTRAL, `CentralCatalogModel`, fixed UUID constants) via `CatalogSeeder` — correct classification, matches existing pattern.
- `TenantNotificationSettingSeeder` and `NotificationTemplateSeeder` both guard with `if (! tenancy()->initialized) { return; }` before touching tenant-scoped `notification_setting`/`Setting` rows — same guard pattern as prior phases.
- `AmenityReservationPaymentRejectionObserver` (registered globally on `Payment::observe(...)`) resolves `AmenityReservation::find($payment->earmark_id)` — tenant-scoped; a cross-tenant `earmark_id` (shouldn't happen, but defense-in-depth) simply returns null and no-ops rather than leaking.
- `ReservationService`'s notify* methods all reuse the already-audited `NotifiesResidents` trait primitives (`unitResidentUserIds` via tenant-scoped `UnitUser`, `userIdsWithRole`/`boardRecipientsForEvent` with the tenancy-initialized guard documented in [[notification-module-isolation]]). `notifyReservationCreated`'s merge of `userIdsWithRole('administrator')` alongside the configurable board-role picker is the same fixed-role-merge precedent as `BillingNotificationService::notifyTrialExpired`.
- Confirmed `Charge`/`UnitUser` both carry `BelongsToTenant` (grep-verified, not just assumed).

**S11 — CancelExpiredReservationsCommand (daily cron):**
- Canonical `Tenant::all()` + `$tenant->run(fn)` per-tenant iteration (matches `RefreshChargeStatusesCommand`/Package WU-4 precedent).
- `Setting::getValue(AmenityReservationsSettings::ENABLED)` is a fresh query every call (`Setting::getValue()` has no static memoization) — Octane-safe, no cross-tenant flag bleed in a long-running worker.
- All queries inside the tenant closure (`AmenityReservation::query()...`) are plain Eloquent, tenant-scoped, never `withoutGlobalScope`.
- The `->utc()` conversion on `payment_deadline` comparisons is a correctness fix for Carbon binding formatting (query bindings are NOT auto-normalized to UTC by Laravel), unrelated to and does not bypass `TenantScope` — confirmed by reading the surrounding code, this only affects the WHERE value on a datetime column, never touches `tenant_id`.
- `ReservationService::expire()` (called per candidate) re-locks and re-verifies both status AND deadline under lock before mutating — idempotent, tenant-scoped throughout (`lockReservation()` uses `AmenityReservation::where(...)->lockForUpdate()->firstOrFail()`).

**Verdict:** SAFE. Isolation health for S9-S11: no CRITICAL, no WARNING. Module classification, migrations, queries, notification wiring, and cron all comply with stancl-tenancy conventions.
