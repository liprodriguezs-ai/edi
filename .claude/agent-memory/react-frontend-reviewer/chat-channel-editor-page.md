---
name: chat-channel-editor-page
description: Chat ChannelFormModal→ChannelEditorPage migration (2026-07-19) — editor routes read-gated (canon-wide gap shared with announcement), missing detail-key invalidation, cache-sharing-by-identical-queryKey pattern
metadata:
  type: pattern
---

# Chat channel editor-page migration (reviewed 2026-07-19)

Modal→page migration mirroring AnnouncementEditorPage. Quality high: no `any`, no as-never
(ChatChannelResource TForm = `Record<string, unknown>`, same avoidance as [[charge-policy-cockpit]]),
Spanish UI (Mexican tú-form), catalogs load-on-form-open justified (§2.12 — page IS the form).
ChannelFormModal fully deleted, zero dead references.

## Findings (flagged)

- **Editor routes gated only by `permissionEntity='chat_channel'`** (= read/report). Every resident
  with chat read access can deep-link `/chat-channel/new` and `/:id/edit` and see the full admin
  editor (backend rejects submit). PermissionRoute supports `permission={[...]}` arrays — nested
  guard with `['create.chat_channel','update.chat_channel']` is the fix. NOTE: `/announcement/new`
  - `/:id/edit` share the SAME gap (pre-existing canon); if one is fixed, fix both.
- **handleSaved invalidates `['chat','channels']` but NOT `['chat','channel', id]`** — re-entering
  edit within global staleTime (30s) shows pre-save values. Narrow window but real.

## Canon confirmations (do NOT re-flag)

- Plain `<p>Cargando…</p>` loading in editor pages IS the canon (AnnouncementEditorPage does the
  same) — not a boneyard violation for this page type.
- ChannelEditorPage duplicates ChatPage's channels query VERBATIM (same key + includes) on purpose:
  identical shape is required to share the `['chat','channels']` cache without poisoning it.
  Documented in a comment. The members/members.user over-fetch on cold deep-link is the accepted
  tradeoff — don't flag as eager-catalog.
- ChannelAdminModal: `onClose()` before `navigate()` so the Dialog overlay doesn't linger over the
  new route; "Nuevo canal" moved out of DialogHeader to avoid the shadcn absolute-positioned X.
- `single_unit`/`by_floor` remain legacy-only branches in SingleTargetSelector (hidden from
  CHANNEL_AVAILABLE_TARGET_TYPES); `singleUnitLabel` starts `''` on edit of legacy data — shared
  pre-existing selector behavior, not introduced here.
