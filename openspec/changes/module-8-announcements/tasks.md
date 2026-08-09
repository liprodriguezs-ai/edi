# Module 8 — Announcements — Tasks

## Status: COMPLETE (green gate passing)

### Migrations
- [x] `announcement_type` (tenant-scoped, key/name/icon/order/is_active)
- [x] `announcement_priority` (tenant-scoped, key/name/color/order/is_active)
- [x] `announcement_status` (tenant-scoped, key/name/color/order/is_active)
- [x] `announcement` (tenant-scoped; author_user_id nullable; target_selector JSONB;
      channel booleans; publish_at/expires_at/edited_at datetimes)
- [x] `announcement_read` (tenant-scoped; unique(announcement_id, user_id); read_at datetime)

### Models
- [x] `AnnouncementType` (KEY_GENERAL, KEY_ASSEMBLY, KEY_MAINTENANCE, KEY_FINANCIAL,
      KEY_SECURITY, KEY_SOCIAL; icon field)
- [x] `AnnouncementPriority` (KEY_NORMAL, KEY_IMPORTANT, KEY_URGENT)
- [x] `AnnouncementStatus` (KEY_DRAFT, KEY_PUBLISHED, KEY_ARCHIVED)
- [x] `Announcement` (BelongsToTenant; casts target_selector→array, channels→boolean,
      publish_at/edited_at→datetime, expires_at→date; relations type/priority/status/authorUser/reads)
- [x] `AnnouncementRead` (BelongsToTenant; read_at datetime)

### Services
- [x] `AnnouncementService.publish` (urgent channel override; status transition; push dispatch)
- [x] `AnnouncementService.archive` (status-only; no delete)
- [x] `AnnouncementService.markRead` (idempotent firstOrCreate)
- [x] `AnnouncementService.recordEdit` (fills attributes; sets edited_at)
- [x] `AnnouncementService.dispatchPushNotifications` (creates Notification + NotificationUser
      via unit_user pivot; resolves units via TargetResolver)

### Controllers + Routes
- [x] Orion resources: `announcement_type`, `announcement_priority`, `announcement_status`,
      `announcement` (all with withSoftDeletes + auth:sanctum + initializeTenancy)
- [x] Custom actions: `POST announcement/{id}/publish`, `POST announcement/{id}/archive`,
      `POST announcement/{id}/read`, `GET announcement/board`

### Seeding + Menu
- [x] `TenantDatabaseSeeder` seeds all 3 catalogs per tenant
- [x] `modules.csv`, `permissions.csv`, `module_system.csv` rows added
- [x] `SystemTableSeeder`: permission entities, routes, icons, hidden catalog entries
- [x] `RolePermissionTableSeeder`: announcement entities in `$commonPermissions`

### Tests (AnnouncementsModuleTest.php — 11 tests)
- [x] A. Catalog seeding (6 types / 3 priorities / 3 statuses per tenant)
- [x] B. Publish changes status to published; publish_at is set
- [x] C. Urgent forces channel_push and channel_sms to true
- [x] D. Non-urgent does not force channels
- [x] E. Archive sets status archived without soft-deleting the row
- [x] F1. markRead creates read record with correct fields
- [x] F2. markRead is idempotent (3 calls → 1 row)
- [x] F3. Multiple users each produce their own read row
- [x] G. Board filter: expired excluded, active included, draft excluded
- [x] H1. Tenant isolation on announcement table
- [x] H2. Tenant isolation on announcement_read table
- [x] H3. Catalog tenant isolation (each tenant gets own rows)

### Deferred
- [ ] Scheduled future publishing cron/queue job (publish_at mechanism)
- [ ] Real push gateway (FCM via Firebase PHP) connected to NotificationUser device tokens
- [ ] SMS gateway job for channel_sms dispatch
- [ ] Email channel job for channel_email dispatch
- [ ] Acknowledgment required / confirmation tracking
