---
name: communication-area-audit-2026-08
description: Verified inventory + defects of the COMUNICACIÓN area (Avisos, Chat, Notificaciones) found while writing end-user docs on 2026-08-06
metadata:
  type: project
---

Audit done 2026-08-06 for the end-user manual (read-only, nothing changed).

**Why:** Pedro asked for literal UI strings of the whole Comunicación area; the pass surfaced
several frontend/backend misalignments that are NOT bugs anyone had filed.

**How to apply:** treat these as known gaps — do not "fix" them incidentally; each needs its own
decision. Re-verify before acting (this is a snapshot).

Defects found (file:line at audit time):
1. `channel_push` / `channel_board` checkboxes ("Push"/"Tablero") of the aviso form are stored but
   NEVER read. `AnnouncementService::channelsFor()` (:497-517) only honors `channel_email` (+ auto
   email for prioridad Importante/Urgente). Push arrives anyway because `notifyUsers` defaults
   `augmentPush: true` → `withConfiguredPushChannel()` appends `firebase_push` globally.
   `channel_sms` exists in the model/migration but the SMS branch is commented out on purpose.
2. `publish_at` (programar publicación) has NO input in `AnnouncementForm.tsx`. The backend flow is
   complete (publish() keeps DRAFT when publish_at is future; `announcements:process-scheduled`
   activates + notifies), but the UI cannot set it → scheduling is unreachable from the app.
3. `chat_message_report` has NO module row and NO permissions in `database/seeders/csv/`. The
   "Reportes" button in `ChatPage.tsx` is gated by `update.chat_message_report` and
   `ChatMessageReportController` by `read.chat_message_report` → the moderation inbox is
   unreachable for every role except a wildcard `*` token. Reporting a message still works
   (POST /chat-message/{id}/report is member-gated only) so reports pile up unreviewed.
4. `NOTIFICATION_CATEGORIES` in `useNotificationPreferences.ts` lists 7 categories; the backend
   `NotificationCategory` has 9 (missing `amenities` and `complaints`) → those notifications cannot
   be toggled per-user (they default to enabled).
5. Personal preferences page shows the SMS column, but no event routes through SmsChannel today
   (Twilio creds unset + announcement SMS branch commented out).
6. `NotificationPreferencesPage.tsx:31` uses voseo ("tenés") — project standard is Mexican Spanish.
7. Aviso attachment picker accepts only `image/*,application/pdf` and its help text says "PDF o
   imagen", but the backend whitelist (`MediaAttachmentPolicy`) allows 12 extensions incl.
   doc/docx/xls/xlsx/mp4/mp3 — UI is narrower than the API.
8. `is_mandatory` is `false` for ALL 26 seeded notification events. The mechanism works
   (badge "Obligatorio" + forced is_active in `NotificationSettingService::resolve/allForTenant`)
   but nothing uses it. `trial_expired` is the only `is_hidden=true` event (never shown in config).
9. Config tab "Plantillas" (notification text templates) is hidden behind
   `const SHOW_NOTIFICATION_TEMPLATES = false` in `configSections.tsx:73`.
10. `update.configuration` is Administrator/Account Owner only. President and Treasurer hold just
    `read.configuration` → they SEE the Notificaciones tab read-only.
11. Announcement ownership gate (`AnnouncementController::assertCanManage`): author, or
    President / Administrator / Account Owner / Super Admin. So Treasurer, Secretary AND Vocal are
    all own-announcements-only — the seeder comment frames it as a Vocal nuance but it is wider.
    The same comment claims "marcar urgente stays out of reach" for the Vocal — NOT implemented
    anywhere (any author can pick prioridad Urgente).
