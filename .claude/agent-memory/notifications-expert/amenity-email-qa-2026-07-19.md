---
name: amenity-email-qa-2026-07-19
description: E2E QA vs Mailpit of the 5 amenity_reservation email events (change amenity-notification-emails) — slug fix verified, settings-row gap on old tenants, QA data landmines
metadata:
  type: project
---

## amenity-notification-emails — QA E2E result (2026-07-19): PASS

The dotted-key landmine documented in [[amenity-reservation-events]] is RESOLVED:
`BladeEmailChannel::renderContent()` now flattens BOTH `_` and `.` to `-`
(`str_replace(['_', '.'], '-', $slug)`), so `amenity_reservation.created` →
`email::amenity-reservation-created` (flat file). 5 flat blades exist in
`Modules/Email/resources/views/`: amenity-reservation-{created,approved,
payment-confirmed,payment-rejected,surcharge-added}.blade.php.

5 events now have `supports_email=true` + `blade_email` in `default_channels`
(CatalogSeeder upsert): created, approved, payment_confirmed, payment_rejected,
surcharge_added. The other 6 amenity events remain email=false. All 5 verified
end-to-end in Mailpit: styled HTML, Spanish copy, money formatted from minor
units ($500.00/$150.00/$75.00), no raw `{{`, `created` shows unit_label +
resident_name, `payment_rejected` shows the reject reason. Subjects: "Nueva
reservación de amenidad" (board), "Reservación aprobada", "Reservación
liquidada", "Pago de reservación rechazado", "Cargo adicional aplicado".

## GAP found: pre-amenity tenants have NO notification_setting rows for amenity events

Migration `2026_07_19_000001_enable_amenity_reservation_emails_on_existing_settings`
flips `channel_email=true` on EXISTING rows — but every tenant created BEFORE the
amenity events were cataloged (S10, 2026-07-07) has ZERO notification_setting rows
for the 11 amenity events (settings are only seeded on TenantCreated; the config
UI upserts on save). The migration was a NO-OP on the whole dev DB (10 tenants ×
11 rows each, all pre-amenity).

Consequences (not a delivery blocker, but asymmetric behavior):
- `resolve()` falls back to event defaults → email still sends (defaults now
  include blade_email). is_active=true.
- BUT `board_role_ids` falls back to `[]` → the `created` email reaches ONLY the
  fixed `administrator` union, NOT President/Treasurer (the picker default lives
  in tenant rows that don't exist).
- Fix per tenant: `TenantNotificationSettingSeeder` is idempotent (firstOrCreate)
  and backfills the missing rows + the President/Treasurer pivot for `created`.
  Ran it manually for the dev tenant during QA.

## QA data landmines (dev tenant Privada Los Encinos a1b2c3d4-…-0001)

- `amenity_slot.weekday` must match the reservation_date's weekday (0=Sun,6=Sat);
  the amenity's `available_weekdays` alone is not enough.
- Dev Salón has blackouts (2026-07-25, 07-27..29) and QA reservations already
  hold slots — check `AmenityReservation` occupancy before picking date+slot.
- Earmark payment submit: must use the AMENITY's bank_account_id, non-cash
  method, amount ≤ outstanding, first payment ≥ deposit snapshot; module
  kill-switch Setting `amenity_reservations.enabled` must be true.
- Tinker E2E: `Auth::login()` per actor works for `currentUser()`; afterCommit
  notifications queue to the `database` driver → `php artisan queue:work
  --stop-when-empty` then read Mailpit API (`/api/v1/messages`, full ID needed
  for `/api/v1/message/{ID}`).

## Pre-existing issues seen in the same run (NOT this change)

- `email::project-contribution-reminder` template does NOT exist → 22 plaintext
  fallbacks ("Recordatorio de aportación", ~700-byte emails). Community Projects
  owns it; candidate for a follow-up template.
- Legacy `resend_email` channel still fires for some backlog events and warns
  "API key not configured — skipping".
- Dev `.env` MAIL_FROM_ADDRESS is `hello@example.com` (Mailpit shows it as the
  From); cosmetic in dev.
