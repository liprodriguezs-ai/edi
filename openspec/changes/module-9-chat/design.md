# Module 9 — Community Chat — Design Decisions

## Soft-hide vs soft-delete

Messages use a `hidden` boolean flag instead of `deleted_at` for moderation. The rationale:
soft-delete removes the row from default query scopes, losing moderator visibility. A hidden
flag keeps the message visible to admins/moderators while filtering it from resident-facing
queries. This is the same pattern used by `ProjectComment`.

## Rate limiting via Laravel Cache

The 30/min/user cap is implemented with a single Cache key per `{tenantId}:{userId}`.
The counter increments on each `postMessage` call and expires after 60 seconds via
`Cache::put($key, $count + 1, 60)`. Tests clear the key via `Cache::forget` before each
rate-limit test to ensure clean state.

This is a lightweight best-effort guard, not a strict sliding-window. A user who sends
30 messages in the last second of the minute window can send 30 more in the first second of
the next window. Acceptable for v1 community use.

## System channel guard placement

The `is_system` check is enforced in `ChatChannelController::beforeDestroy`. The model itself
does not throw on `delete()` (the test uses `assertDatabaseHas` to verify the guard logic via
the flag, not via an actual delete attempt). This follows the Orion hook convention used for
other immutability guards in the project (e.g., `TowerController::beforeDestroy` for
tower-with-units).

## Threading: self-referencing FK

`chat_message.reply_to_id` is a nullable UUID FK to `chat_message.id` with `nullOnDelete`.
This means if a parent message is hard-deleted, replies lose their `reply_to_id` reference
(set to NULL) rather than cascade-deleting, preserving reply content.

The `replyTo()` (belongsTo) and `replies()` (hasMany) relations support loading the parent
and listing all replies. Nesting depth is not limited at the DB level; the frontend should
render at most one or two levels.

## Reaction uniqueness

`unique(chat_message_id, user_id, emoji)` at the DB level, enforced additionally via
`firstOrCreate`. Multiple users can react with the same emoji (each gets their own row).

## Message report status

`chat_message_report.status` is a plain string column with constants
`ChatMessageReport::STATUS_PENDING`, `STATUS_REVIEWED`, `STATUS_DISMISSED`. Not a catalog
table — the three values are stable and do not need tenant-level customization.

## Channel mode as plain string

`mode` ('residents_read_only' / 'everyone_writes') is a plain string, not a catalog table.
Mode enforcement (preventing residents from posting in `residents_read_only` channels) is
a frontend concern for v1; the backend stores the mode and returns it on channel reads.

## Accepted limitations / deferrals

- No real-time delivery (no WebSocket/Pusher). Clients must poll `GET /api/chat_message` with
  filters.
- Direct message flow (channel type `direct` seeded but no dedicated endpoint or
  auto-channel-creation for DMs).
- Message editing (`edited_at` column exists, no edit endpoint or history).
- File/image attachments (no MediaLibrary integration on messages).
- Strict sliding-window rate limiting (current implementation is per-minute, not sliding).
