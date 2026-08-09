---
name: chat-channel-editor-2026-07
description: Chat channel editor refactor (modal→page) audit — page-as-form eager catalogs sanctioned; copy-config channels query over-fetches members on direct URL entry; duplicated queryFn literal risk.
metadata:
  type: pattern
---

# Chat ChannelEditorPage refactor — cost audit (2026-07-19)

## Confirmed clean / sanctioned

- **Page-as-form = load-on-form-open.** `ChannelForm` (mounted only inside the
  dedicated route `/chat-channel/new|/:id/edit`) calls `useCatalogs` at mount with
  5 SMALL catalogs (CHAT_CHANNEL_TYPE, UNIT_TYPE, TOWER, SECTION, UNIT_FEATURE) in
  ONE batched `/selects`. Navigating to the page IS opening the form → §2.12 satisfied.
  UNIT (heavy) correctly absent (SingleTargetSelector uses remote typeahead).
  Do NOT re-flag this as eager list-mount loading.
- **Cache sharing works:** `ChannelEditorPage` reuses queryKey `['chat','channels']`
  with a byte-identical queryFn to `ChatPage` → coming from chat within staleTime (30s)
  = zero extra fetch. `ChannelAdminModal` no longer mounts the form (navigates instead)
  — net cost reduction vs the old always-mounted modal.

## Findings (open as of 2026-07-19)

- **WARNING — copy-config over-fetch on direct entry.** ChannelEditorPage:27-38 fires
  the FULL chat query (100 channels + `members` + `members.user`) on every editor
  mount; on deep-link/refresh that's a cold heavy fetch solely to feed the rarely-used
  "Copiar config de…" dropdown, which only needs id/name + target\_\* fields.
  Recommended fix: lazy + light query (`['chat','channels','copy-sources']`, no
  includes) enabled on `SearchSelect onMenuOpen` (the prop exists since the
  MultiSelectFilter→SearchSelect rewrite), optionally seeded from
  `queryClient.getQueryData(['chat','channels'])`.
- **SUGGESTION — duplicated queryFn literal.** The channels queryFn is copy-pasted in
  ChatPage and ChannelEditorPage under the SAME key. If params drift, two different
  payload shapes write the same cache entry (chat could render channels missing
  `members`). Extract a shared `queryOptions` helper in the chat services file.

## Reusable heuristic

When a page shares a queryKey with another page "to reuse cache", check the DIRECT-URL
path: cache sharing only saves cost on warm navigation; cold entry pays the heaviest
consumer's includes. If the sharing page needs a subset, prefer a lazy light query over
inheriting the fat one.
