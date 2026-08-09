---
name: nightwatch-observability-events
description: How the custom engine now feeds Nightwatch's Notifications panel — DispatchedNotification + one empty class per event key, emitted around the send in DeliverChannelMessageJob
metadata:
  type: project
---

**2026-07-30 — implemented (supersedes the "always 0" conclusion in [[nightwatch-notifications-blindspot]]).**

Camino A: the custom engine now emits Laravel's `NotificationSending` / `NotificationSent` pair around every
queued channel delivery, so Nightwatch's Notifications panel populates — WITHOUT re-hosting any channel.

- `backend/app/Notifications/DispatchedNotification.php` — extends `Illuminate\Notifications\Notification`,
  ctor `public readonly string $eventKey`, `via()` returns `[]` (Laravel NEVER delivers it; it exists only to
  give the events an instance + a class NAME). Static resolver `DispatchedNotification::for(string $eventKey)`:
  explodes on `.`, `Str::studly()`s each segment, appends `Notification`, prefixes `App\Notifications\Events\`.
  **Dots must be split manually** — `Str::studly()` folds only `-`/`_`, so `amenity_reservation.approved`
  would otherwise become `AmenityReservation.approved`. Unknown key → returns the BASE class (mandatory
  fallback: a new event must never fatal the delivery).
- `backend/app/Notifications/Events/*.php` — 28 empty `final` subclasses, one per event key. WHY a class per
  event: `vendor/laravel/nightwatch/src/Sensors/NotificationSensor.php` persists ONLY `channel`, `class`,
  `duration`, so the business event has to be materialised as a class to be visible per event.
- `Modules/Notification/app/Jobs/DeliverChannelMessageJob::handle()` — builds an `AnonymousNotifiable`
  routed to `$this->recipients[0] ?? 'unknown'`, emits `NotificationSending` **before** the send
  (`NotificationSensor:45` throws `RuntimeException` on a `Sent` without its `Sending`), sends, then emits
  `NotificationSent(..., response: null)` (channel contract returns `void`). **No try/catch**: a transport
  failure emits Sending but NOT Sent, so the count reflects real deliveries and `$tries` retries unchanged.
  Job signature/properties untouched (in-flight serialized payloads stay compatible).
- Event identity comes from `$this->data['email_template']` (set by `notifyEvent()` to the templateKey),
  falling back to the literal `'notification'`. So `notifyUsers()`-only emitters (Chat, Access, Package,
  Reports, Projects, Board) all report as the generic `DispatchedNotification` — that is expected; give them
  a real event key if you want per-event rows.
- The inline `log` channel is NOT instrumented (dev noise, never queued). Legacy `NotificationJob` /
  `NotificationUserJob` untouched.

**Event-key catalog materialised (28)** = 26 `notification_event` keys from
`database/seeders/CatalogSeeder::seedNotificationEvents()` (11 core + 11 `amenity_reservation.*` + 4
`complaint_*`) + the copy variant `charge_created_batch` (`templateKey:` 8th arg in
`ChargeGenerationService:598`) + `announcement-published` (the only hard-coded `email_template` outside
`notifyEvent`, `AnnouncementService:391` — hyphenated, `Str::studly` handles it).
**When you add an event key or a copy variant, add the matching `App\Notifications\Events\*Notification`**,
otherwise it silently degrades to the generic class in the panel.

Test: `backend/tests/Feature/Notifications/NotificationObservabilityEventsTest.php` (5 cases: pair+channel+
specific class, dotted key, unknown key → base, missing key → `'notification'`, failure → Sending w/o Sent).
It calls the job DIRECTLY and swaps `SmsChannel` via `$this->app->instance()` — the suite pins
`NOTIFICATION_CHANNELS=log` and `log` is delivered inline, so the full dispatcher never reaches the job.
No DB needed → it passes even when the kivo7 Postgres container is down.
