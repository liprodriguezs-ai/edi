# Module 8 — Announcements — Spec

## Requirements

### R1 — Announcement entity
An `announcement` has: `title`, FK `announcement_type_id`, FK `announcement_priority_id`,
FK `announcement_status_id`, `content` (text), `target_type` + JSON `target_selector`
(Collections targeting schema), channel booleans (`channel_push`, `channel_board`,
`channel_email`, `channel_sms`), `publish_at` (datetime, nullable), `expires_at` (date,
nullable), `edited_at` (datetime, nullable), `author_user_id` (nullable, withoutGlobalScope).

### R2 — Announcement read tracking
`announcement_read` has: FK `announcement_id`, `user_id` (UUID), `read_at` (datetime).
Unique on `(announcement_id, user_id)` — one row per user per announcement.

### R3 — Publish
`AnnouncementService.publish(announcement)`:
1. Loads the priority record.
2. If priority key is `urgent`: sets `channel_push = true`, `channel_sms = true`.
3. Sets `announcement_status_id` to `published`.
4. Sets `publish_at = now()` if not already set.
5. Saves the announcement.
6. If `channel_push = true`: dispatches in-app push via `dispatchPushNotifications`
   (creates `Notification` + `NotificationUser` rows for all users resolved from target units).

### R4 — Archive
`AnnouncementService.archive(announcement)`: sets status to `archived` and saves. The row is
NEVER deleted; it remains accessible via `withTrashed` or status filter.

### R5 — Mark read
`AnnouncementService.markRead(announcement, userId)`: idempotent — uses `firstOrCreate` on
`(announcement_id, user_id)`. Returns the `AnnouncementRead` record. Multiple calls produce
exactly one row per user.

### R6 — Edit recording
`AnnouncementService.recordEdit(announcement, attributes)`: fills the given attributes, sets
`edited_at = now()`, saves. Allows post-publication edits while tracking that a change occurred.

### R7 — Board endpoint
Returns announcements where `status = published AND (expires_at IS NULL OR expires_at >= today)`.
Draft and archived announcements do not appear. Expired published announcements do not appear.

### R8 — Push dispatch
`dispatchPushNotifications` creates a `Notification` record (using the existing Notification
module) with `click_action = 'announcement'`, then resolves target units via
`Collections\TargetResolver`, queries `unit_user` pivot for user IDs, and creates
`NotificationUser` rows (idempotent via `firstOrCreate`).

## Scenarios (acceptance — from tests in AnnouncementsModuleTest.php)

- **A. Catalog seeding**: 6 announcement types (general/assembly/maintenance/financial/
  security/social), 3 priorities (normal/important/urgent), 3 statuses (draft/published/archived)
  per tenant.
- **B. Publish changes status**: draft announcement after `publish()` has `status=published` and
  `publish_at` is set.
- **C. Urgent forces channels**: announcement with `urgent` priority and `channel_push=false`,
  `channel_sms=false` → after `publish()`, both are `true`.
- **D. Non-urgent does not force channels**: normal priority, channels disabled → remain disabled
  after publish.
- **E. Archive**: published announcement after `archive()` has `status=archived` and still exists
  in DB (`deleted_at` is NULL, row not soft-deleted).
- **F. Read tracking**:
  - `markRead` creates a read record with `announcement_id`, `user_id`, and `read_at`.
  - Three calls from the same user → exactly 1 row (idempotent).
  - Three different users each marking read → 3 rows.
- **G. Board expiry filter**: published+expired announcement excluded; published+non-expired
  included; draft excluded. Board query returns exactly the active set.
- **H. Tenant isolation**: announcement and announcement_read rows from tenant A invisible
  in tenant B; catalog rows per-tenant.
