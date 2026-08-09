---
name: chat-module-isolation
description: Chat module (Phase 1) access-by-target_type isolation; all chat models tenant-scoped; WARNING global-role bleed via userCanModerate/board target
metadata:
  type: project
---

Chat module access-control review (Fase 1 — access moved from `chat_channel_member` rows to computed `target_type`, reusing TargetResolver logic).

**Classification:** all Chat models are TENANT-SCOPED (BelongsToTenant): `ChatChannel`, `ChatChannelMember`, `ChatMessage`. `chat_channel_member` has `unique(['tenant_id','chat_channel_id','user_id'])`. `UnitUser` (Housing) also tenant-scoped — used by by_tower/by_section matching.

**SAFE patterns (do NOT re-flag):**
- `ChatService::accessibleChannelIds()` (ChatService.php:334) — `ChatChannel::query()->get()->filter(userMatchesChannelTarget)`; TenantScope bounds to current tenant. Drives channel + message read scopes.
- `userHasUnitIn()` (:703) — `UnitUser::where(user_id)->whereNull(end_date)->whereHas('unit', whereIn(tower_id/section_id))`; both UnitUser and Unit tenant-scoped → by_tower/by_section correctly only match units in current tenant.
- `addMember()` firstOrCreate (:627) — lookup TenantScope-bounded, create auto-stamps tenant_id via BelongsToTenant. Lazy membership only carries per-user state (last_read_at/notif_pref), never gates access.
- `User::withoutGlobalScopes()->find($userId)` in userHasAnyRole/userCanModerate/writeModeAllows — `$userId` is always the AUTHENTICATED CALLER's own id; User is central; correct (same pattern as [[notification-module-isolation]]).
- Moderator read bypass in ChatMessageController::scopeToAccessibleChannels (callerCanModerate via tokenCan) and ChatChannelController — returns query with only TenantScope → moderator sees CURRENT tenant only. Tenant-bounded.

**WARNING — global-role bleed (not new root cause, but diff removed containment):**
board/role target matching (`userMatchesChannelTarget` 'board'/'role') and the access bypass `userCanModerate()` (:424, `hasRole(ADMIN_ROLES)`) rely on GLOBAL Spatie roles. A user who is admin/board in tenant A but only a resident in tenant B gains board/admin chat access in B (incl. via `canAccessChannel` → post/react/report to any B channel). Root = accepted global-role limitation (domain.md "el rol Spatie es global... Aceptado"). The OLD code gated access behind explicit chat_channel_member rows (which contained the bleed); the NEW code computes access from global roles alone. Same class as currentBoardMemberUserIds() WARNING in [[notification-module-isolation]]. account.owner managing multiple clusters is the realistic trigger.

**SUGGESTION:** two divergent "moderator" definitions — ChatService::userCanModerate uses hasRole/can; ChatMessageController::callerCanModerate uses tokenCan('*'). Both tenant-bounded, but read-scope vs access-gate can disagree. firstOrCreate in addMember is non-atomic but unique index makes worst case a caught violation, not a duplicate.

---

## Fase 2 review (2026-06-28) — channel admin by tenant

**New in scope:** `copyConfig` endpoint, `ADMIN_TARGET_TYPES`/`MODES` constants, `notify_on_post`, `scopeToAccessibleChannels` replaces `scopeDirectChannels`.

**Board global-role bleed (Fase 1 WARNING): FIXED in Fase 2.**
`userIsCurrentBoardMember()` (ChatService:472) now uses `NotifiesResidents::currentBoardMemberUserIds()` backed by tenant-scoped `BoardPeriod`/`BoardMember` (BelongsToTenant). The old `BOARD_ROLES` global-Spatie-role constant was removed. A president/treasurer of tenant A no longer gains board-channel access in tenant B. Admin bypass (`ADMIN_ROLES`) still uses global Spatie roles — accepted limitation.

**copyConfig isolation (SAFE):**
- Source channel: `Rule::exists('chat_channel','id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')` in controller — tenant-scoped at validation time.
- Target channel: `ChatChannel::findOrFail($id)` under BelongsToTenant global scope — returns 404 for cross-tenant IDs.
- Permission: `$this->authorize('update.chat_channel', ChatChannel::class)` — residents get 403.
- Endpoint on `$customMiddleware` (auth:sanctum + rejectRefreshToken + authActive + initializeTenancy).

**WARNING — copyConfig has no `$target->is_system` guard (ChatChannelController:168 / ChatService:413):**
`copyChannelConfig` silently writes `mode`/`target_type`/`target_selector` into a system channel if one is passed as `$id`. The regular PATCH has the same gap (UpdateRequest allows changing those fields on system channels). Same surface area, not a new cross-tenant breach, but system-channel integrity is unprotected from within the tenant. Low-severity within tenant; do NOT re-flag as cross-tenant.

**WARNING — `accessibleChannelIds` vs `canAccessChannel` discrepancy (ChatService:340-347):**
`accessibleChannelIds` filters by `userMatchesChannelTarget`, NOT `canAccessChannel`. `scopeToAccessibleChannels` bypasses filtering for `tokenCan('update.chat_channel')` only, not `tokenCan('update.chat_message')`. A message moderator (update.chat_message but NOT update.chat_channel) cannot LIST channels outside their target audience even though `assertChannelAccess` would let them interact. Not a tenancy breach — a UX inconsistency in the listing scope.

**`target_selector` depth (SUGGESTION):**
`['sometimes', 'nullable', 'array']` only. For `target_type=manual`, user_ids are not validated for tenant membership. Runtime is safe (by_tower/by_section queries go through BelongsToTenant-scoped Unit/UnitUser; manual user_ids require the user to authenticate in the current tenant). Stale IDs create silent empty channels.

**`currentBoardMemberUserIds()` inherited WARNING (same as [[notification-module-isolation]]):**
No guard when called outside HTTP context. In Chat's HTTP context (with initializeTenancy) BelongsToTenant on BoardPeriod/BoardMember makes it safe. Risk only if ChatService methods are invoked from queue jobs without tenant init.

**notify_on_post migration (COMPLIANT):** `->comment(...)` present, `default(true)`, correct ALTER on `chat_channel` (tenant-scoped parent table). No table-level comment needed for ALTER.

**Verdict Fase 2:** SAFE (no cross-tenant isolation breach). One WARNING on system-channel config mutability (same gap as regular update, not new) + one WARNING on listing-scope/access-gate discrepancy for message moderators. Board global-role bleed from Fase 1 is resolved.
