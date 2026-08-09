---
name: unread-badge-seam
description: Notification unread badge — no dedicated endpoint, derived from Orion search meta.total (2026-06-28 review)
metadata:
  type: project
---

Reviewed 2026-06-28.

## Convention

There is NO `/notification_user/unread-count` endpoint. The badge count is derived client-side:

1. On mount: `POST /notification_user/search?limit=1` with `filters: [{field:'is_read', operator:'=', value:false}]` — reads `meta.total` (NotificationUserResource.ts:29-34).
2. On push received: incremented in-cache via `queryClient.setQueryData(UNREAD_COUNT_KEY, incrementUnread)` (useFirebaseMessaging.ts:139).
3. On "mark all read": reset to 0 via `resetUnread` (notification-bell.tsx:45).
4. On single read: decremented via `decrementUnread` (notification-bell.tsx:53).
5. On window focus: refetch from server reconciles any drift.

React Query keys: `UNREAD_COUNT_KEY = ['notifications', 'unread']`, `RECENT_KEY = ['notifications', 'recent']`.

The Notification api.php explicitly comments this at line 22-23: "The unread badge is derived client-side from POST notification_user/search (meta.total) — there is no dedicated badge-count endpoint."

## Status: ALIGNED — zero orphaned frontend calls to any unread-count endpoint.
