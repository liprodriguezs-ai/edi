# Module 9 — Community Chat — Tasks

## Status: COMPLETE (green gate passing)

### Migrations
- [x] `chat_channel_type` (tenant-scoped, key/name/order/is_active)
- [x] `chat_channel` (tenant-scoped; target_selector JSONB; mode/is_moderated/is_system/is_archived booleans)
- [x] `chat_channel_member` (tenant-scoped; unique(chat_channel_id, user_id); notif_pref string)
- [x] `chat_message` (tenant-scoped; reply_to_id self-FK nullOnDelete; hidden boolean; edited_at nullable)
- [x] `chat_reaction` (tenant-scoped; unique(chat_message_id, user_id, emoji))
- [x] `chat_message_report` (tenant-scoped; reporter_user_id UUID; reason text; status string)

### Models
- [x] `ChatChannelType` (KEY_OFFICIAL, KEY_GENERAL, KEY_CUSTOM, KEY_DIRECT)
- [x] `ChatChannel` (BelongsToTenant; casts target_selector→array, is_* booleans; relations channelType/members/messages)
- [x] `ChatChannelMember` (BelongsToTenant)
- [x] `ChatMessage` (BelongsToTenant; hidden→boolean, edited_at→datetime; self-refs replyTo/replies; relations reactions/reports; user withoutGlobalScope)
- [x] `ChatReaction` (BelongsToTenant)
- [x] `ChatMessageReport` (STATUS_PENDING / STATUS_REVIEWED / STATUS_DISMISSED constants)

### Services
- [x] `ChatService.createSystemChannels` (idempotent; creates "Avisos oficiales" + "General")
- [x] `ChatService.postMessage` (rate-limit check via Cache; creates ChatMessage)
- [x] `ChatService.hideMessage` (sets hidden=true; no delete)
- [x] `ChatService.react` + `unreact` (firstOrCreate + delete)
- [x] `ChatService.report` (creates ChatMessageReport with status=pending)
- [x] `ChatService.joinChannel` (firstOrCreate on channel_member)
- [x] `ChatService.enforceRateLimit` (30/min via Cache, 60s TTL; throws InvalidArgumentException)
- [x] `ChatService.currentlyInside` (not directly tested; utility for guard dashboard)

### Controllers + Routes
- [x] Orion resources: `chat_channel_type`, `chat_channel`, `chat_channel_member`, `chat_message`
      (all with withSoftDeletes + auth:sanctum + initializeTenancy)
- [x] Custom actions: `POST chat-message/{id}/hide`, `POST chat-message/{id}/react`,
      `POST chat-message/{id}/report`, `POST chat-channel/{id}/join`

### Seeding + Menu
- [x] `TenantDatabaseSeeder` seeds `chat_channel_type` + calls `createSystemChannels`
- [x] `modules.csv`, `permissions.csv`, `module_system.csv` rows added
- [x] `SystemTableSeeder`: permission entities, routes, icons
- [x] `RolePermissionTableSeeder`: chat entities in `$commonPermissions`

### Tests (ChatModuleTest.php — 14 tests)
- [x] A. Catalog seeding (4 channel types per tenant)
- [x] B1. System channels created on seed (Avisos oficiales + General, correct modes)
- [x] B2. System channels idempotent on double seed
- [x] C. System channel delete guard (is_system flag verified; channel remains in DB)
- [x] D. Hide message sets hidden=true without soft-deleting
- [x] E. Threading via reply_to_id; replyTo relation loads parent body
- [x] F1. Reactions idempotent per user+emoji (3 calls → 1 row)
- [x] F2. Multiple users react with same emoji → multiple rows
- [x] F3. Unreact removes reaction
- [x] G. Report creates pending record with correct fields
- [x] H. Rate limit: 30 messages succeed; 31st throws InvalidArgumentException
- [x] I1. Tenant isolation: chat_channel (system channels per-tenant)
- [x] I2. Tenant isolation: chat_message
- [x] I3. Tenant isolation: chat_reaction
- [x] I4. Tenant isolation: chat_channel_member

### Deferred
- [ ] Real-time WebSocket delivery (Pusher / Soketi / Laravel Reverb)
- [ ] Direct message (DM) channel auto-creation flow
- [ ] Message editing endpoint + history
- [ ] File/image attachment support (MediaLibrary integration)
- [ ] Strict sliding-window rate limiting
