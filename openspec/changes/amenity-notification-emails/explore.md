# Exploration — amenity-notification-emails

> SDD change: enable the EMAIL channel for 5 AmenityReservations notification events and ship their
> designed email templates (React Email → Blade → Mailpit E2E).
> Explored 2026-07-19 by notifications-expert. All paths verified on disk.

## TL;DR

The 5 events **already exist end-to-end** (central catalog + per-tenant settings + dispatch wiring +
in-app/push templates). Email is disabled **deliberately**: all 11 `amenity_reservation.*` events are
seeded with `supports_email=false` because their event keys carry a **dot** (`amenity_reservation.created`)
and the `BladeEmailChannel` view-naming contract only hyphenates underscores — the dot survives into the
Blade view name and Laravel's view resolver treats it as a directory separator, so the view would never
resolve and the channel would silently fall back to plain text. The change is therefore: (1) teach the
email slug to flatten dots, (2) flip `supports_email` for the 5 events, (3) author 5 React Email
templates, (4) decide + implement how existing tenants get the toggle, (5) test against Mailpit.

There are **no Laravel `Notification` classes** in this system. Notifications are events dispatched
through the custom engine: `NotifiesResidents::notifyEvent()` → `NotificationSettingService::resolve()`
→ `NotificationDispatcher` → per-channel queued `DeliverChannelMessageJob`.

---

## 1. The 5 events — exact keys, dispatch points, audiences

Central catalog rows: `backend/database/seeders/CatalogSeeder.php:596-781` (block "Amenity Reservations (S10)").
UUID constants: `backend/Modules/Notification/app/Models/NotificationEvent.php:78-108`.

| UI name (ES) | Event key | Audience | Fired from |
|---|---|---|---|
| Nueva reservación de amenidad | `amenity_reservation.created` | board | `ReservationService::notifyReservationCreated()` — `backend/Modules/AmenityReservations/app/Services/ReservationService.php:515` |
| Reservación aprobada | `amenity_reservation.approved` | resident | `ReservationService::notifyReservationApproved()` — `ReservationService.php:541` |
| Pago de reservación confirmado | `amenity_reservation.payment_confirmed` | resident | `ReservationService::notifyPaymentSettlement()` — `ReservationService.php:676` (event key decided at `:908`) |
| Pago de reservación rechazado | `amenity_reservation.payment_rejected` | resident | `AmenityReservationPaymentRejectionObserver::updated()` — `backend/Modules/AmenityReservations/app/Observers/AmenityReservationPaymentRejectionObserver.php:83` (Payment observer, registered in `AmenityReservationsServiceProvider.php:46`) |
| Cargo adicional a reservación | `amenity_reservation.surcharge_added` | resident | `AmenitySurchargeService::notifySurchargeAdded()` — `backend/Modules/AmenityReservations/app/Services/AmenitySurchargeService.php:118` |

All 5 currently: `supports_email=false`, `supports_push=true`, `default_channels=['log','firebase_push']`,
`is_active=true`, `is_mandatory=false`, `is_hidden=false`, `category=AMENITIES`.

Recipients:
- Resident events → `NotifiesResidents::unitResidentUserIds($unitId)` (`backend/app/Notifications/Concerns/NotifiesResidents.php:39` — ALL active `unit_user` rows for the unit, not just the billing owner).
- `created` (board) → configurable board-role picker (default President + Treasurer, seeded in
  `TenantNotificationSettingSeeder::seedAmenityReservationCreatedBoardRoles()`,
  `backend/Modules/Notification/database/seeders/TenantNotificationSettingSeeder.php:104`) **merged with**
  the fixed `administrator` Spatie role via `userIdsWithRole('administrator')` (`ReservationService.php:520-523`).

All dispatch sites already fire AFTER the domain `DB::transaction()` (S10 convention, e.g.
`AmenitySurchargeService.php:110-113`) — the hard rule is respected; nothing to change there.

## 2. Channel matrix — what gates "email" per event

Three layers:

1. **Central catalog** (`notification_event`, single source for capability):
   `supports_email` / `supports_push` / `supports_whatsapp` booleans + `default_channels` JSON.
   Seeded by `CatalogSeeder::seedNotificationEvents()` via `upsertCatalog()` (**upsert** — re-running
   `php artisan db:seed --class=CatalogSeeder` updates existing central rows in place).

2. **Per-tenant settings** (`notification_setting`, one row per event per tenant):
   `is_active` + `channel_email` / `channel_push` / `channel_whatsapp`. Seeded on `TenantCreated` by
   `TenantNotificationSettingSeeder` using **`firstOrCreate`** (`TenantNotificationSettingSeeder.php:37`)
   — re-running it does **NOT** update rows that already exist. Runtime resolution:
   `NotificationSettingService::resolve()` (`backend/Modules/Notification/app/Services/NotificationSettingService.php:38`)
   → `channelsFromSetting()` (`:244`) maps `channel_email=true` → dispatcher channel `'blade_email'`
   (`'log'` always included). Note: `resolve()` does NOT consult `supports_email` — the setting row is
   authoritative at runtime.

3. **Admin config UI** (`GET/PUT /config/notifications`):
   - Backend: `ConfigurationController::getNotificationConfig()` / `updateNotificationConfig()`
     (`backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php:1971` / `:1987`)
     → `NotificationSettingService::allForTenant()` / `updateForTenant()`. `allForTenant()` exposes
     `supports_email` per event (`NotificationSettingService.php:141`); `updateForTenant()` stores
     whatever booleans arrive — it does **not** validate `channel_email` against `supports_email`.
   - Frontend: `frontend/src/modules/configuration/components/NotificationsConfigSection.tsx:145` renders
     the email `Switch` **only when `ev.supports_email`**. The section is fully data-driven — flipping
     `supports_email=true` in the catalog makes the toggle appear with **zero frontend changes**.
     Type contract: `frontend/src/modules/configuration/services/ConfigurationResource.ts:65,70,84`.

**So, to give an event the email option:** set `supports_email=true` (and, if email should be on by
default, add `'blade_email'` to `default_channels`) in `CatalogSeeder` + re-seed. New tenants then
inherit the default; **existing tenants keep their current `notification_setting` rows with
`channel_email=false`** (see §7 for the migration decision).

## 3. Email channel mechanics — and the dotted-key knot

`backend/app/Notifications/Channels/BladeEmailChannel.php`. No Mailable, no Notification class:

- `NotifiesResidents::notifyEvent()` **unconditionally** sets `$data['email_template'] = $eventKey` and
  `$data['email_context'] = $context` (`NotifiesResidents.php:235-236`).
- `BladeEmailChannel::renderContent()` (`BladeEmailChannel.php:64`) builds the view name as
  `'email::'.str_replace('_', '-', $templateSlug)` and renders it with
  `$context + ['emailBaseUrl' => ...]` via `Mail::html(...)` (subject = the notification title, i.e.
  the rendered `notification_template.{event_key}` title or the fallback title).
- If the view does not exist → **silent plain-text fallback** (`Log::warning` + nl2br'd body). Runs
  inside `DeliverChannelMessageJob` (queued per recipient, `$tries=3`); transport errors propagate for retry.

**The knot (documented in `CatalogSeeder.php:597-604`):** `str_replace('_','-')` on
`amenity_reservation.created` yields view `email::amenity-reservation.created`, and Laravel resolves the
dot as a directory separator → `amenity-reservation/created.blade.php`, a nested path that does not
exist. Nothing renders; plain-text fallback forever.

**Recommended fix — flatten the dot in the slug**: change the channel's slug derivation to
`str_replace(['_', '.'], '-', $templateSlug)` → view `email::amenity-reservation-created` → flat file
`amenity-reservation-created.blade.php`. Backward compatible (no existing event key contains a dot) and
consistent with the flat-only `copy-emails` pipeline (see §5 — its `.html`→`.blade.php` rename loop only
touches top-level files, so nested view dirs are effectively unsupported). Update the channel docblock
AND replace the now-stale comment block in `CatalogSeeder.php:597-604`.

**Working precedents to copy:**
1. Collections `payment_rejected`: `frontend/emails/payment-rejected.tsx` →
   `backend/Modules/Email/resources/views/payment-rejected.blade.php`; dispatched via `notifyEvent` from
   `PaymentService::reject()` with `reason` in context. Closest analog for `amenity_reservation.payment_rejected`.
2. Announcements: `frontend/emails/announcement-published.tsx`; `AnnouncementService` sets
   `email_template`/`email_context` explicitly. Good example of a non-Collections designed email + generic preheader.

## 4. Available payload per event (`email_context` = the `$context` array)

Base context: `ReservationService::reservationContext()` (`ReservationService.php:707-717`) =
`{amenity_name, reservation_date}` (date already formatted `d/m/Y`; money already formatted as strings
via `TenantMoney::format`, e.g. `"$1,500.00"`).

| Event | Context available for the template |
|---|---|
| `created` | `amenity_name`, `reservation_date` — **thin** (no unit identifier or resident name; board readers will want them → consider enriching the emitter's context, harmless for the in-app template which only uses `{amenity_name}`/`{reservation_date}`) |
| `approved` | `amenity_name`, `reservation_date` |
| `payment_confirmed` | `amenity_name`, `reservation_date`, `amount_paid`, `amount_outstanding` (`ReservationService.php:678-680`; for this event outstanding is always the formatted `$0.00`-equivalent since it fires on full settle) |
| `payment_rejected` | `amenity_name`, `reservation_date`, `amount`, `reason` (Observer `:76-81`; reason = `payment.cancellation_reason`) |
| `surcharge_added` | `amenity_name`, `reservation_date`, `amount`, `concept_name`, `description` (`AmenitySurchargeService.php:123-129`) |

Also in `$data` (NOT in email context): `amenity_reservation_id`, `unit_id`, `click_action`
(`/my-reservations` resident / `/amenity-reservation` board) — usable to build a CTA URL if the emitter
copies it into context (the channel only injects `emailBaseUrl`, which is the **static-assets** base, not
the app URL; a CTA needs `config('app.frontend_url')` put into context by the emitter, as
Announcements does with `announcementUrl`).

In-app/push text templates for ALL 11 events already exist as `notification_template.amenity_reservation.*`
Setting rows: `backend/Modules/Configuration/database/seeders/NotificationTemplateSeeder.php:74+`. The email
subject will be the rendered title from these templates.

## 5. React Email pipeline (confirmed)

- Sources: `frontend/emails/*.tsx`. Shared components in `frontend/emails/_components/`:
  `EmailLayout`, `EmailHeader`, `EmailFooter`, `ButtonBase`, `Card`, `TextBase`, `Title`,
  `LaravelBlade` (`<Blade variable="x" />` → `{{ $x }}` in the compiled Blade / preview fallback in dev),
  plus `_theme.ts` and `_utils/`.
- Build: from `frontend/`, `pnpm copy-emails` (`frontend/package.json:11`) = `export:email:blade` →
  copies `out/*` to `backend/Modules/Email/resources/views/`, static assets to
  `backend/public/email/static/`, then renames **top-level** `*.html` → `*.blade.php` (the `for f in
  .../*.html` loop is flat-only — this is why nested view paths are a dead end).
- It regenerates **ALL** blades — after running, `git checkout` any blade not intended to change.
- Filename contract (with the §3 fix): tsx filename = flattened event key →
  `amenity-reservation-created.tsx`, `amenity-reservation-approved.tsx`,
  `amenity-reservation-payment-confirmed.tsx`, `amenity-reservation-payment-rejected.tsx`,
  `amenity-reservation-surcharge-added.tsx`. A mismatch = silent plain-text fallback (caught only in
  prod-like E2E, never by unit tests).
- Templates to crib from: `payment-rejected.tsx` (rejection + reason), `payment-accepted.tsx`
  (amount confirmation), `announcement-published.tsx` (recent, generic layout).
- Gotcha: `previewText`/preheader must stay GENERIC — interpolating PreviewProps bakes fallback demo data
  into the compiled Blade.

## 6. Testing & Mailpit

- Existing wiring suite: `backend/Modules/AmenityReservations/tests/Feature/AmenityReservationNotificationWiringTest.php`
  — asserts **in-app** `NotificationUser` rows (log channel is synchronous), one test per event. Does not
  cover email; extend it or add a sibling test for `blade_email` dispatch.
- Email/queue test patterns: `backend/tests/Feature/Notifications/NotificationQueueingTest.php`,
  `ResendEmailChannelTest.php`, `FirebasePushChannelTest.php`.
- Queue caveat: `QUEUE_CONNECTION=sync` runs `DeliverChannelMessageJob` inline **except** under
  `Queue::fake()`. Assert ENQUEUE with `Queue::fake()` + `assertPushed(DeliverChannelMessageJob)`;
  assert EFFECT (mail actually sent) by NOT faking the queue and using `Mail::fake()` +
  `Mail::assertSent`. To exercise `blade_email` in a test, set `channel_email=true` on the tenant's
  `notification_setting` row for the event (the runtime channel list comes from that row, not from
  `NOTIFICATION_CHANNELS`, which `phpunit.xml` pins to `log` only for the push gating).
- `Mail::fake()` does **NOT render Blade** — the event-key↔filename contract must be verified by real
  rendering: host `php artisan tinker` `view('email::amenity-reservation-created', [...])->render()`
  and/or a real send inspected in Mailpit.
- Mailpit (already running): SMTP `127.0.0.1:1025`, web UI `http://localhost:8025`. Backend `.env` uses
  `MAIL_HOST=127.0.0.1` (host process, NOT the docker `mailpit` hostname). Tests run on **host** php
  (`php artisan test`), host Postgres `127.0.0.1:5432`; only ONE test process at a time against the
  shared `testing` DB.

## 7. Risks / gotchas / open decisions

1. **Dotted key ↔ Blade filename contract (core risk).** Without the `BladeEmailChannel` dot-flattening
   fix, every amenity email silently degrades to plain text. Any solution must keep
   `str_replace('_','-')` behavior intact for the 20+ existing flat keys. Verify by rendering, not by
   `Mail::fake` tests.
2. **Existing tenants don't get the toggle value automatically.** `TenantNotificationSettingSeeder` is
   `firstOrCreate` — rows already exist with `channel_email=false`. Flipping `supports_email` only makes
   the UI switch APPEAR (off). **Decision needed:** (a) leave off, admins opt in per cluster (zero risk,
   no migration), or (b) default-on for existing tenants via a one-shot data migration/seeder that sets
   `channel_email=true` on `notification_setting` rows whose `notification_event_id` is one of the 5
   UUID consts (respecting soft-deleted/opted-out rows is not a concern here since the column already
   exists — but it DOES override nothing the admin could have chosen, because the toggle was never
   visible; default-on is defensible). Also update `default_channels` (+`'blade_email'`) so NEW tenants
   seed email-on.
3. **Stale comment block** `CatalogSeeder.php:597-604` explains why email is off — must be rewritten when
   the change lands, or it will mislead the next reader.
4. **`created` context is thin** for a board-facing email (no unit/resident). Enriching
   `reservationContext()` (e.g. `unit_label`, `resident_name`) is additive and safe for the in-app
   templates, but touches the emitters — keep it in scope consciously or design the email around
   amenity+date only.
5. **CTA URLs**: `emailBaseUrl` is for static assets only. If templates need a "Ver reservación" button,
   the emitters must add e.g. `reservation_url => config('app.frontend_url').'/my-reservations'` to
   context (Announcements precedent).
6. **`payment_confirmed` vs `deposit_confirmed`** share one emitter; only `payment_confirmed` is in
   scope. Leaving `deposit_confirmed` with `supports_email=false` is safe — its setting row simply never
   yields `blade_email`.
7. **Preheader**: keep `previewText` generic (compiled-Blade fallback-data bake gotcha).
8. **`copy-emails` regenerates all blades** — scope the git diff after running.
9. **QA on the dev tenant**: after flipping `supports_email`, someone must still enable the toggle in
   Configuración → Notificaciones (or run the §7.2 migration) before Mailpit shows anything.

## 8. Exact files to touch (implementation inventory)

Backend:
- `backend/app/Notifications/Channels/BladeEmailChannel.php` — flatten `.` in the view slug (+docblock).
- `backend/database/seeders/CatalogSeeder.php` — `supports_email=true` (+ `'blade_email'` in
  `default_channels` if default-on) for the 5 events; rewrite the `:597-604` comment.
- (decision §7.2) NEW one-shot migration/seeder to flip `channel_email` on existing tenants' rows.
- Tests: extend `backend/Modules/AmenityReservations/tests/Feature/AmenityReservationNotificationWiringTest.php`
  (or sibling) — email dispatch per event + slug flattening unit coverage.
- (optional, §7.4/7.5) `ReservationService.php`, `AmenitySurchargeService.php`,
  `AmenityReservationPaymentRejectionObserver.php` — enrich `email_context`.

Frontend:
- NEW `frontend/emails/amenity-reservation-created.tsx`, `amenity-reservation-approved.tsx`,
  `amenity-reservation-payment-confirmed.tsx`, `amenity-reservation-payment-rejected.tsx`,
  `amenity-reservation-surcharge-added.tsx` (crib `payment-rejected.tsx` / `payment-accepted.tsx`).
- Run `pnpm copy-emails` → 5 new `backend/Modules/Email/resources/views/amenity-reservation-*.blade.php`
  (generated; scope the diff).
- NO changes to `NotificationsConfigSection.tsx` / `ConfigurationResource.ts` — UI is data-driven.

Verification:
- Host `php artisan db:seed --class=CatalogSeeder` (central upsert), re-render via tinker, real send to
  Mailpit `localhost:8025`, feature tests on host php.
