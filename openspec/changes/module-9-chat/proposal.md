# Module 9 — Community Chat (Chat Comunitario)

## Intent

Implement an in-app community messaging system that allows residents and board staff to
communicate in organized channels. The system provides targeted channels (by type or audience),
threading via message replies, emoji reactions, moderation capabilities (hide, report), and
built-in rate limiting.

## Scope

New tenant-scoped module `Chat` (`backend/Modules/Chat`):

- **Catalog** (tenant-scoped, string-key, seeded per tenant): `chat_channel_type`
  (4 keys: official/general/custom/direct).
- **Entities**: `chat_channel`, `chat_channel_member`, `chat_message`, `chat_reaction`,
  `chat_message_report`.
- **Services**: `ChatService` (createSystemChannels/postMessage/hideMessage/react/unreact/
  report/joinChannel; rate-limit via Cache; system channel guard).
- **Endpoints**: Orion CRUD for `chat_channel_type`, `chat_channel`, `chat_channel_member`,
  `chat_message`; custom actions (`hide`, `react`, `report`, `join`).
- Per-tenant seeding creates two system channels automatically: "Avisos oficiales"
  (official type, residents_read_only mode) and "General" (general type, everyone_writes mode).
- Menu/permission registration, per-tenant seeding, integration tests (12 scenarios).

## Approach

- System channels (`is_system = true`) are undeletable; the guard is checked in the controller
  `beforeDestroy` hook (and the `is_system` flag is the authoritative signal).
- Message hiding is a soft-hide (`hidden = true`) not a soft-delete. Hidden messages remain
  in the DB for moderation review.
- Rate limiting uses Laravel Cache keyed by `chat_rate:{tenantId}:{userId}` with a 60-second
  TTL and a cap of 30 messages per minute. Exceeding the cap throws `InvalidArgumentException`.
- Message threading uses a self-referencing `reply_to_id` FK (nullable, nullOnDelete).
- Reactions are idempotent via `firstOrCreate` on `(chat_message_id, user_id, emoji)`.

## Out of Scope (v1)

- Real-time WebSocket/Pusher/Soketi delivery (polling or long-poll from the client).
- Direct message channels (channel type `direct` is seeded but no dedicated DM flow exists).
- Message editing (column `edited_at` exists for future use but no edit endpoint).
- File/image attachments in messages.
- Channel-level permissions beyond `mode` string (residents_read_only / everyone_writes).
