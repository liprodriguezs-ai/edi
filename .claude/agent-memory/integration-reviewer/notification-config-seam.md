---
name: notification-config-seam
description: GET/PUT /config/notifications contract review (Phase 5 — notification config API + UI)
metadata:
  type: project
---

Reviewed 2026-06-27. Files: ConfigurationController.php, NotificationSettingService.php, api.php, ConfigurationResource.ts, useConfiguration.ts, NotificationsConfigSection.tsx.

## Contract is ALIGNED — no critical violations.

### Key verified facts

1. **GET response envelope**: backend `['data' => $service->allForTenant()]` consumed correctly by `getConfig<INotificationConfig>()` which extracts `data.data` (Axios response body → `.data`). ✅

2. **All 12 event fields** present and correctly typed in `INotificationEventConfig`. `description?: string | null` is safely wider than backend (backend always includes it). ✅

3. **board_roles** shape `{id, name}` matches `IBoardRoleOption` exactly. ✅

4. **PUT body**: frontend sends `{events: [{key, is_active, channel_email, channel_push, channel_whatsapp, board_role_ids}]}`. Backend validates the exact same shape with `required` on all channel booleans. ✅

5. **board_role_ids for resident events**: frontend sends `[]` (server default); backend silently ignores for `audience !== 'board'`. Consistent. ✅

6. **PUT response**: backend returns `{message: '...'}`. Frontend discards the response body; mutation uses own toast string. ✅

### Warnings found

- **notificationConfig GET query has no onError handler** in useConfiguration.ts:29. A 403 (user lacks `read.configuration`) makes `notificationConfig` stay undefined with no user feedback.

- **`audience` typed as `'resident' | 'board'` union** but backend sends raw DB string. If a third audience type is ever seeded, TS will accept it at runtime (component falls through to resident UI silently).

### Auth pattern

`requireAbility()` uses Sanctum token abilities (`$user->tokenCan($ability)`), NOT Spatie permissions. `canUpdate` prop in NotificationsConfigSection comes from parent — source not reviewed. Token must be issued with `read.configuration` / `update.configuration` abilities (or `*`).

### Invalidation

Query key `['config', 'notifications']` — mutation invalidates the exact same key. ✅
