# Module 9 — Community Chat — Spec

## Requirements

### R1 — Chat channel
`chat_channel` has: `name`, FK `chat_channel_type_id`, `icon` (nullable), `description` (nullable),
`target_type` + JSON `target_selector`, `mode` (string: residents_read_only / everyone_writes),
`is_moderated` (boolean), `is_system` (boolean), `is_archived` (boolean).

### R2 — Chat channel member
`chat_channel_member` has: FK `chat_channel_id`, `user_id` (UUID), `notif_pref` (string,
default 'all'). Unique on `(chat_channel_id, user_id)`.

### R3 — Chat message
`chat_message` has: FK `chat_channel_id`, `user_id` (UUID, withoutGlobalScope), `body` (text),
`reply_to_id` (nullable, self-referencing FK nullOnDelete), `hidden` (boolean, default false),
`edited_at` (datetime, nullable).

### R4 — Chat reaction
`chat_reaction` has: FK `chat_message_id`, `user_id` (UUID), `emoji` (string).
Unique on `(chat_message_id, user_id, emoji)`.

### R5 — Chat message report
`chat_message_report` has: FK `chat_message_id`, `reporter_user_id` (UUID), `reason` (text),
`status` (string: pending / reviewed / dismissed). Default status: `pending`.

### R6 — System channel creation
`ChatService.createSystemChannels` creates two channels per tenant (idempotent via
`firstOrCreate` on `name`):
- "Avisos oficiales": type `official`, mode `residents_read_only`, `is_system=true`.
- "General": type `general`, mode `everyone_writes`, `is_system=true`.
Called during `TenantDatabaseSeeder`.

### R7 — System channel delete guard
System channels (`is_system=true`) cannot be deleted. The guard is enforced in
`ChatChannelController::beforeDestroy`.

### R8 — Post message with rate limit
`ChatService.postMessage(channel, userId, body, replyToId=null)`:
- Checks rate limit via Cache key `chat_rate:{tenantId}:{userId}` (max 30/min).
- If limit exceeded, throws `InvalidArgumentException`.
- Creates and returns a `ChatMessage`.

### R9 — Hide message
`ChatService.hideMessage(message)`: sets `hidden=true`, saves. Row is NOT deleted or
soft-deleted. Hidden messages remain in the DB.

### R10 — Reactions (idempotent, removable)
`ChatService.react(message, userId, emoji)`: `firstOrCreate` on `(chat_message_id, user_id, emoji)`.
`ChatService.unreact(message, userId, emoji)`: deletes the matching row.

### R11 — Report message
`ChatService.report(message, reporterUserId, reason)`: creates `ChatMessageReport` with
`status=pending`.

### R12 — Join channel
`ChatService.joinChannel(channel, userId, notifPref='all')`: `firstOrCreate` on
`(chat_channel_id, user_id)`.

## Scenarios (acceptance — from tests in ChatModuleTest.php)

- **A. Catalog seeding**: 4 channel types (official/general/custom/direct) per tenant.
- **B. System channels**: after seed, "Avisos oficiales" (`is_system=true`,
  `mode=residents_read_only`) and "General" (`is_system=true`, `mode=everyone_writes`) exist.
  Calling `createSystemChannels()` twice does not create duplicates.
- **C. System channel delete guard**: `is_system=true` flag is the guard signal;
  channel remains in DB after guard check.
- **D. Hide message**: after `hideMessage()`, `hidden=true`, `deleted_at IS NULL` (not
  soft-deleted).
- **E. Threading**: reply message has `reply_to_id` pointing to parent; `replyTo` relation
  loads the parent body.
- **F. Reactions**: same user + same emoji → idempotent (1 row); 3 users same emoji → 3 rows;
  `unreact` deletes the matching row.
- **G. Report**: creates `ChatMessageReport` with `status=pending`, correct `chat_message_id`
  and `reporter_user_id`.
- **H. Rate limit**: 30 messages succeed; 31st throws `InvalidArgumentException` matching
  "Rate limit exceeded".
- **I. Tenant isolation**: chat_channel, chat_message, chat_reaction, chat_channel_member —
  rows from tenant A invisible in tenant B (system channels are per-tenant).
