# Module 8 — Announcements (Comunicación / Avisos)

## Intent

Implement a structured announcement system that allows board members and administrators to
publish communications to residents with multi-channel dispatch (in-app board, push
notification, email, SMS). Announcements support scheduling, expiry, priority levels, and
per-resident read tracking.

## Scope

New tenant-scoped module `Announcements` (`backend/Modules/Announcements`):

- **Catalogs** (tenant-scoped, string-key, seeded per tenant): `announcement_type`
  (6 keys: general/assembly/maintenance/financial/security/social), `announcement_priority`
  (3 keys: normal/important/urgent), `announcement_status` (3 keys: draft/published/archived).
- **Entities**: `announcement`, `announcement_read`.
- **Services**: `AnnouncementService` (publish/archive/markRead/recordEdit; dispatches via
  Notification module for push channel; urgent priority forces push+sms).
- **Endpoints**: Orion CRUD for `announcement` + catalog resources; custom actions
  (`publish`, `archive`, `read`, `board`).
- Target resolution via Collections `TargetResolver` for push dispatch.
- Menu/permission registration, per-tenant seeding, integration tests (7 scenarios).

## Approach

- Announcements are never hard-deleted. Archiving sets status to `archived` without calling
  `delete()`. The `board` endpoint filters by `status=published AND (expires_at IS NULL OR
  expires_at >= today)`.
- Push dispatch creates a `Notification` record in the existing Notification module and
  per-user `NotificationUser` rows resolved via the `unit_user` pivot table. This reuses the
  existing Notification module without duplicating tables.
- Urgent priority overrides `channel_push` and `channel_sms` to `true` regardless of what
  the operator set, enforcing guaranteed delivery for critical communications.
- Read tracking is idempotent via `AnnouncementRead::firstOrCreate` on
  `(announcement_id, user_id)`.
- `edited_at` is a timestamp set by `recordEdit` to allow residents to see that content
  changed after publication.

## Out of Scope (v1)

- Scheduled publishing (publish_at in the future): column exists and is set on publish, but
  the cron job to auto-publish at publish_at is deferred.
- Real push gateway (FCM/APNs): the Notification module inserts DB rows; actual device push
  is deferred to a queue job not yet implemented.
- Real SMS gateway: `channel_sms = true` is stored and forced for urgent; actual SMS dispatch
  is deferred.
- Email channel: `channel_email` column exists; email dispatch deferred.
- Resident-acknowledgment confirmation flow (acknowledgment_required) is not implemented.
