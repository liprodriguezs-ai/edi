# notifications-expert — MEMORY

Tenant-configurable notification system (built 2026-06). Engine in `backend/app/Notifications/` +
`backend/Modules/Notification/`; admin config in `Modules/Configuration` + `frontend/src/modules/configuration`.
Architecture + hard rules are in my agent prompt. Full design also in engram (`notifications/configurable-system`).

## Topic files (read the one matching the task)

- [Event catalog](event-catalog.md) — full catalog (core 11 + 11 `amenity_reservation.*` S10 + trial/project events), audiences, dispatch points, deferred/uncabled events, **dotted-key email knot**, supports_* semantics, firstOrCreate seeder gotcha.
- [Queued delivery + click_actions](queued-delivery-and-click-actions.md) — DeliverChannelMessageJob per-recipient queueing (Cambio 6), Queue::fake test caveat, full click_action→route map.
- [Announcements email](announcements-email.md) — blade_email for announcements, email_context shape, SMS disabled, preview endpoint, TargetResolver::resolveUnitIds (2026-07-04).
- [Firebase push + center UI](firebase-push-and-center-ui.md) — kivo7-475fa env wiring, PWA/SW coexistence, token_sender, unread badge/toast/sound (Cambios 1-2).
- [Nightwatch shows 0 notifications](nightwatch-notifications-blindspot.md) — the engine is 100% custom, never fires NotificationSending/Sent; Nightwatch only sees Mail/jobs/HTTP (2026-07-30). FIXED same day ↓
- [Nightwatch observability events](nightwatch-observability-events.md) — DispatchedNotification + 28 empty per-event classes; job emits Sending/Sent; add a class for every NEW event key or copy variant (2026-07-30).
- [Copy variants via $templateKey](copy-variant-template-key.md) — notifyEvent's 8th arg: one event, many templates; the leaked-`{token}` bug class; how to backfill a new template slug without wiping tenant customizations.
- [Comunicación area audit 2026-08](communication-area-audit-2026-08.md) — 11 verified gaps across Avisos/Chat/Notificaciones (dead channel_push+channel_board toggles, no publish_at UI, unreachable chat report inbox, missing pref categories).

## Channels (NotificationDispatcher 3 maps)

| dispatcher name | class | address (CHANNEL_ADDRESS_MAP) | pref (PREFERENCE_CHANNEL_MAP) |
|---|---|---|---|
| `log` | LogChannel | — (user ids, in-app, SYNC) | — (never filtered) |
| `blade_email` | BladeEmailChannel | `email` | `email` |
| `firebase_push` | FirebasePushChannel | `token_sender` | `push` |
| `whatsapp` | WhatsAppChannel (NO-OP) | `phone` | `whatsapp` |
| `sms` | SmsChannel (Twilio) | `phone` | `sms` |
| `resend_email` | ResendEmailChannel (legacy plain-text) | `email` | `email` |

Config bool → name: `channel_email→blade_email`, `channel_push→firebase_push`, `channel_whatsapp→whatsapp`;
`log` always included. WhatsApp UI toggle permanently `disabled` ("Próximamente").
`supports_email/push/whatsapp` on the CENTRAL event gate ONLY the config-UI switches; the per-tenant
`notification_setting` row is authoritative at runtime (`resolve()` doesn't check supports_*).

## Designed email templates (event-key ↔ filename MUST match, hyphenated)

`frontend/emails/*.tsx` → `backend/Modules/Email/resources/views/*.blade.php` via `pnpm copy-emails`
(rename loop is FLAT-ONLY — nested view dirs unsupported): `payment-registered`, `payment-accepted`,
`payment-rejected`, `payment-due-reminder`, `payment-registered-direct`, `announcement-published` (+ auth/
loyalty/invitation ones). In-app/push TEXT from `notification_template.{event_key}` Setting rows
(NotificationTemplateSeeder) via TemplateRenderer. Body vars snake_case (`resident_name`, `unit_label`,
`amount`, `reason`, `days_left`, `url`…).

## Admin config UI

`GET/PUT /config/notifications` (ConfigurationController ~:1971/:1987, gated by existing `configuration`
permission). Tab "Notificaciones" → `NotificationsConfigSection.tsx` (per event: is_active Switch, channel
toggles shown iff `supports_*`, board-role MultiSelect for board events). Data-driven — new catalog events
appear automatically. Type contract `NotificationEventPatch` in `ConfigurationResource.ts`.

## Gotchas (caught in live E2E, NOT by unit tests)

- **event-key ↔ blade filename**: template must be `{event_key_with_hyphens}.blade.php` or BladeEmailChannel
  silently falls back to plain text. Dots in keys (amenity) break it worse: view resolver treats `.` as a
  directory separator → see [event-catalog](event-catalog.md).
- **preheader**: `previewText` must be GENERIC — interpolating PreviewProps bakes fallback data into the compiled Blade.
- **dispatch outside money txn** (`DB::afterCommit`) — non-negotiable.
- **tenant scope**: `resolve()` guards uninitialized tenancy → event defaults. `NotificationEvent::settings()`
  leaks across tenants without tenant context — read `NotificationSetting` directly.
- **signed days**: `Carbon::diffInDays(..., false)` is SIGNED — clamp `max(0,…)`; use `days_past_due` for overdue.
- Backend tests on HOST php (`php artisan test`), not sail. Mailpit: SMTP 127.0.0.1:1025, UI localhost:8025,
  `MAIL_HOST=127.0.0.1`. `Mail::fake()` does NOT render Blade — verify via tinker `->render()` or Mailpit.
- `QUEUE_CONNECTION=sync` runs jobs inline EXCEPT under `Queue::fake()` — see [queued-delivery](queued-delivery-and-click-actions.md).
- CORRECTION 2026-07-30: `SendPushNotificationJob` NO LONGER EXISTS. `FirebasePushChannel` now sends FCM
  SYNCHRONOUSLY (`sendMulticast`) inside `DeliverChannelMessageJob`; the dispatcher chunks tokens ≤500
  (`NotificationDispatcher::PUSH_CHUNK_SIZE`) and queues one job per chunk. Legacy admin-broadcast push
  still lives in `Modules/Notification/app/Jobs/NotificationUserJob.php` (its own Kreait call).
- FIXED (Cambio 4): `NotificationSettingSeedingTest` count was stale (10→11 with `payment_registered_direct`);
  catalog has since grown again (amenity S10) — recheck count asserts when adding events.

## Topic files recovered 2026-07-20 (from stray `backend/.claude/agent-memory` copy)

- [Architecture overview](arch-overview.md) — event catalog, per-tenant config, dispatch model, channel mapping
- [Email channel template contract](email-template-contract.md) — naming convention, silent fallback gotcha, copy-emails workflow
- [Firebase push wiring](firebase-push-wiring.md) — token_sender, env setup, withConfiguredPushChannel gate
- [Trial expired notification (2026-07-01)](trial-expired-event.md) — event TRIAL_EXPIRED, BillingNotificationService, cron hook, test
- [Amenity reservation events (2026-07-07)](amenity-reservation-events.md) — 11 events, admin-role-not-board-role recipient merge, 'Pago confirmado' title collision, payment_rejected Payment-observer hook, dotted-key email landmine (RESUELTO 2026-07-19), S11 hooks
- [Amenity email QA (2026-07-19)](amenity-email-qa-2026-07-19.md) — PASS E2E vs Mailpit; slug flattens dots now; pre-amenity tenants lack settings rows (created→solo admin); QA data landmines; project-contribution-reminder template missing
