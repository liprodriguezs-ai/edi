---
name: chat-channel-seam
description: Chat channel seam — TWO reviews merged 2026-07-20 (were split across root/backend agent-memory copies). Fase 1 contract review (2026-06-28: can_read/can_write via search, composer gating, auto-join removal) + target selector multiselect migration review (2026-06-30: by_type/tower/feature/floor/manual/board selectors, read+write scope separation, TargetResolver key consumption).
metadata:
  type: project
---

# Chat Fase 1 Contract (reviewed 2026-06-28)

### search() → afterIndex() chain confirmed
Orion `search()` delegates to `index()` (see vendor `HandlesStandardOperations::search` — it literally calls `$this->index($request)`). So `buildIndexFetchQuery` and `afterIndex` are ALWAYS called for POST /chat_channel/search. The `can_read`/`can_write` flags stamped in `afterIndex` → `stampAccessFlags` ARE present in the search payload.

### Flags in payload
`stampAccessFlags` uses `$channel->setAttribute('can_read', true)` and `$channel->setAttribute('can_write', ...)`. These land in `$attributes` (not `$appends`) and are included in `toArray()` / JSON output because `ChatChannel` has no `$hidden` list. Always serialized.

`can_read` is always `true` — correct, because channels absent from the response were filtered by `scopeToAccessibleChannels`.

### Frontend IChatChannel alignment
`schema.ts` has `can_read?: boolean` and `can_write?: boolean` — matches backend names/types. `mode` is typed as `'everyone_writes' | 'residents_read_only' | null` matching backend values. `target_type` is `string | null`. All aligned.

### Composer gate: no 403 path from normal flow
`ChatPage` passes `canWrite={channel?.can_write ?? false}` to `MessageComposer`. MessageComposer renders a read-only banner if `channelId && !canWrite`. Backend double-guards in `postMessage` → `assertCanWriteToChannel`. If `can_write=true` from backend, backend also allows posting — no mismatch path.

### Auto-join elimination
`useSendMessage.ts` has no join logic whatsoever. `ChatChannelResource` still exposes `join()` method (correct path: POST `/chat-channel/{id}/join`) but no caller invokes it in the active send flow. Intentionally kept for Fase 2 explicit join UX.

### Auth middleware gap (WARNING — not fixed by this phase)
`chat_channel` and `chat_message` Orion resources use `['auth:sanctum', 'initializeTenancy']`, omitting `rejectRefreshToken` and `authActive` present on the custom action routes. A user with `auth_active=false` can still read channels/messages via Orion search/index. Flag for Fase 2 or security review.

### Middleware path: custom routes use hyphen, Orion resources use underscore
- Backend: `Route::post('chat-channel/{id}/join', ...)` (hyphen) ↔ Frontend: `/chat-channel/${id}/join` (hyphen) — MATCH
- Backend: `Orion::resource('chat_channel', ...)` (underscore) ↔ Frontend: `basePath: '/chat_channel'` (underscore) — MATCH
- Backend: `Route::post('chat_channel/direct', ...)` (underscore) ↔ Frontend: `${basePath}/direct` = `/chat_channel/direct` — MATCH

# Chat Channel Target Selector Seam (reviewed 2026-06-30)

## Context
Frontend migrated `by_type` (and peer targets) from single-id to multiselect array in
`frontend/src/modules/chat/schema.ts`. Backend NOT changed. Review confirmed backend
already handled arrays via `TargetResolver::resolve()` → `whereIn()` pattern.

## ALIGNED (all active UI paths pass)

- **Selector keys**: `unit_type_ids`, `tower_ids`, `unit_feature_ids`, `unit_ids`, `unit_id`,
  `floors` — exact match frontend builder (`targetSelector.ts`) ↔ TargetResolver PHP reads.
- **by_type multi/OR**: TargetResolver `byType()` uses `whereIn('unit_type_id', $unitTypeIds)`.
  Sending 2+ ids gives OR semantics. ✓
- **board**: builder returns `null` selector for `board`. ChatService handles before TargetResolver.
  ADMIN_TARGET_TYPES (backend 8) = CHANNEL_TARGET_TYPES (frontend 8). ✓
- **by_floor (Chat, floorIncludesTowers=true)**: emits `{tower_ids:[...], floors:[...]}`.
  TargetResolver `byFloor($towerIds, $floors)` reads both. ✓
- **read/write scope separation**: `target_selector` ← `buildChannelTargetSelector` (reads
  `target_*`); `write_target_selector` ← `buildChannelWriteTargetSelector` (reads
  `write_target_*`). Model fillable and ChatService reads are symmetric. ✓
- **FormRequests**: `target_selector: ['sometimes','nullable','array']` — accepts any array.
  No inner-key validation (acceptable, Zod + FE superRefine is the gate). ✓
- **Shared builder not changed**: `targetSelector.ts` `toIdArray()` handles both scalar and array
  inputs — zero regression on charges side. ✓
- **Backward-compat hydration**: `selectorIds()` in schema.ts wraps legacy scalar → 1-element array
  so old channels hydrate the multiselect. ✓

## WARNING (legacy by_floor re-edit broken)

`SingleTargetSelector.tsx:332` — `by_floor` branch uses `register(names.floor)` on HTML Input
(always a string). For Chat, `names.floor = 'target_floors'` which has type
`z.array(z.string())` in the chat schema. A legacy `by_floor` channel cannot be re-saved
without first switching target_type — Zod rejects `string` where `string[]` expected.

**Fix**: replace `register(names.floor)` with `<Controller>` + array coercion:
```tsx
value={Array.isArray(field.value) ? (field.value[0] ?? '') : (field.value ?? '')}
onChange={(e) => field.onChange([e.target.value])}
```

This was introduced by the migration that renamed `target_floor` (string) → `target_floors`
(array) without updating the Input binding. Charges unaffected (DEFAULT_SINGLE_TARGET_NAMES
still uses `target_floor` singular → string schema).

## SUGGESTION

- Stale comment in `schema.ts:145-146`: says "by_type still carries a single id" — now wrong,
  it's a proper array from the multiselect.
- `ChannelFormModal.tsx:75-81`: `useCatalogs` before `if (!isOpen) return null` → catalogs load
  on mount, not on open. Gate with `{ enabled: isOpen }`.

## Key backend constants (for future reviews)
- `ChatChannel::ADMIN_TARGET_TYPES = ['all','by_type','by_tower','by_floor','by_feature','single_unit','manual','board']`
- `TargetResolver::TARGET_TYPES` does NOT include `board` (handled before resolver in ChatService)
- `by_share` excluded from ADMIN_TARGET_TYPES (charge-only concept, FormRequest rejects it)
- `byFeature([])` → `allActive()` if empty — FE superRefine prevents empty submission
