# Module 8 — Announcements — Design Decisions

## Archive vs delete

Announcements are never soft-deleted via standard Orion destroy. `archive()` sets the status
to `archived` and saves. This preserves the complete publication history for audit purposes.
The `withTrashed` model scope in the test verifies the row still exists with `deleted_at = NULL`.

## Urgent channel override

The urgent override is enforced inside `AnnouncementService.publish` before saving, not in
the FormRequest or the controller. This ensures the invariant holds regardless of how the
publish action is triggered (API, artisan, test). The channels are mutated on the in-memory
model instance and persisted in the same `save()` call.

## Push dispatch via Notification module

Rather than creating a parallel notification system, push dispatch writes to the existing
`Notification` and `NotificationUser` tables (Notification module). The announcement content
is truncated to 250 characters (after strip_tags) for the notification body. User resolution
queries `unit_user` directly via `DB::table` to avoid an Eloquent dependency on a relation
that may not exist as a model association.

If no unit_user rows exist (e.g., residents not yet linked to units), the `Notification` record
is still created with zero `NotificationUser` rows. The notification is not lost; it can be
retargeted once users are linked.

## Target resolution reuse

`AnnouncementService` depends on `Collections\TargetResolver` via constructor injection,
following the same pattern as `ProjectActivationService`. This ensures one canonical
implementation of the 8-type targeting logic.

## Read tracking scope

`AnnouncementRead` is tenant-scoped (BelongsToTenant). The `user_id` column is a plain UUID
not a FK to the `users` table — this avoids cross-tenant FK complications and allows read
tracking for users whose tenant context may differ. The unique constraint on
`(announcement_id, user_id)` enforces idempotency.

## Expiry semantics

`expires_at` is a date (not datetime). The board filter uses `>= now()->toDateString()` so an
announcement expires at end of its `expires_at` date, not at midnight UTC.

## Accepted limitations / deferrals

- Scheduled future publishing (publish_at in the future): column and UI wire are ready, but
  no cron/queue job runs `publish()` automatically at `publish_at`. Operator must manually
  trigger publish.
- Real push delivery (FCM/APNs): `NotificationUser` rows are created but no Firebase dispatch
  job exists yet. The notification pipeline is set up but not connected to device tokens.
- SMS and email channels: `channel_sms` and `channel_email` are stored and forced for urgent,
  but no gateway jobs send actual messages.
- Acknowledgment required / tracking of whether all residents acknowledged urgent announcements
  is not implemented.
