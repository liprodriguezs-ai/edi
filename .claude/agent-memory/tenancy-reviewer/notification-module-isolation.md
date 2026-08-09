---
name: notification-module-isolation
description: Notification module Phase 1 tenancy review — notification_event (central), notification_setting + pivot (tenant-scoped), seeder isolation pattern
metadata:
  type: project
---

Reviewed 2026-06-27. Notification module Phase 1 data layer.

**Classification decisions:**
- `notification_event` → CENTRAL (`CentralCatalogModel`, `BaseModel::columns($table, false)`, UUID constants, seeded by `CatalogSeeder::seedNotificationEvents()`). No `BelongsToTenant`.
- `notification_setting` → TENANT (`BaseModel` + `BelongsToTenant`, `BaseModel::columns($table, true)`, unique `['tenant_id', 'notification_event_id']`). Seeded per-tenant by `TenantNotificationSettingSeeder`.
- `notification_setting_board_role` → TENANT pivot with own `tenant_id` via direct `BelongsToTenant` (not `BelongsToPrimaryModel`). Choice is deliberate: avoids the one-level-deep limit of `BelongsToPrimaryModel` and gives an explicit TenantScope guard.

**Cross-scope FK — documented and intentional:**
- `notification_setting.notification_event_id` → FK to `notification_event` (CENTRAL). Established pattern (same as `board_member.board_role_id`). Safe: central rows are never deleted in production.
- `notification_setting_board_role.board_role_id` → FK to `board_role` (CENTRAL). Same pattern. `onDelete('cascade')` is theoretical since central catalog rows are never removed.

**Unique index on pivot — per-convention:**
`unique(['notification_setting_id', 'board_role_id'])` on `notification_setting_board_role` omits `tenant_id`. Safe per convention: `notification_setting_id` FK to a UUID-keyed tenant table already provides global uniqueness (stancl-tenancy §3 confirms this is acceptable).

**Seeder isolation pattern:**
- `TenantNotificationSettingSeeder` guards with `if (! tenancy()->initialized) { return; }` at line 27.
- `NotificationEvent::all()` in the seeder correctly returns all central events (no `TenantScope` on central model). Each `NotificationSetting::firstOrCreate(...)` has `tenant_id` stamped by `BelongsToTenant` + scoped by `TenantScope` in the SELECT.
- `TenantDatabaseSeeder` wraps the call inside `if (tenancy()->initialized)` (redundant but correct).

**`upsertCatalog` + `default_channels` JSON:**
`CatalogSeeder::upsertCatalog` uses `Model::upsert()` which bypasses Eloquent casts. The seeder passes `json_encode([...])` raw string — correct. Model cast reads it back as array. No double-encoding.

**WARNING (latent, not a current breach):**
`NotificationEvent::settings()` is a `hasMany` from CENTRAL to TENANT (`NotificationSetting`). When tenancy IS initialized `TenantScope` limits the result to current tenant (safe). When NOT initialized (super-admin without `X-Tenant-Id`, background command), it returns all tenants' settings. No docblock warns callers. Phase 2 must ensure `notifyEvent()` calls this relation only inside initialized tenant context. See [[collections-cross-connection-cases]] for the pattern of documenting deliberate cross-context calls.

**Verdict (Phase 1):** SAFE. All classification, migrations, seeding, and FK patterns are compliant. One latent WARNING on `settings()` relation — must be guarded before Phase 2 wires `notifyEvent()`.

---

## Phase 2+3 review — wiring (NotifiesResidents + PaymentService + SendChargeRemindersCommand)

Reviewed 2026-06-27.

**NotificationSettingService::resolve() guard (COMPLIANT):**
- Tenancy guard at line 54 correctly short-circuits to event defaults (no `NotificationSetting` query) when `! tenancy()->initialized || ! tenant()`. Never queries tenant-scoped table without context. The latent WARNING from Phase 1 is resolved.

**NotifiesResidents::userIdsWithRole() (COMPLIANT):**
- Explicit `! tenancy()->initialized || ! tenant()` guard at line 83. Returns `[]` (not all-platform users) when uninitialized.
- Calls `NotificationDispatcher::tenantRoleUserIds($tenantKey, $roleNames)` with explicit tenant key — correctly scopes Spatie global roles to the cluster's `user_tenant` membership.

**NotifiesResidents::boardRecipientsForEvent() (COMPLIANT):**
- Chains through guarded `resolve()` → guarded `userIdsWithRole()`. BoardRole lookup is central (no BelongsToTenant needed). Safe.

**NotifiesResidents::currentBoardMemberUserIds() — WARNING (latent, line 101-113):**
- NO tenancy guard. Relies solely on `BoardPeriod` and `BoardMember` having `BelongsToTenant`. Unlike `userIdsWithRole()`, will NOT fail safe (return []) if called without tenant context — could return a period from ANY tenant. Not called in PaymentService/SendChargeRemindersCommand but latent risk in the trait.

**notifyUsers() dispatch timing (COMPLIANT):**
- All `notifyEvent()`/`notifyUsers()` calls in PaymentService happen OUTSIDE the DB transaction block. `DB::afterCommit()` therefore runs immediately (no open transaction). No risk of notification error rolling back a payment.

**SendChargeRemindersCommand::handle() tenant loop (COMPLIANT):**
- `Tenant::all()` on central model (safe), then `$tenant->run(fn)` per tenant — canonical Stancl pattern. Tenancy is initialized for all work in `sendForTenant()`. Charge, Setting, and UnitUser queries all run within tenant context.

**User::withoutGlobalScopes() — SUGGESTION (PaymentService:889, SendChargeRemindersCommand:89):**
- Uses `withoutGlobalScopes()` (plural — strips ALL scopes including SoftDeletes) instead of canonical `->withoutGlobalScope(UserTenantScope::class)` from stancl-tenancy §9. User is central so no cross-tenant leak, but deviates from convention and can return soft-deleted users. Low severity.

**No raw DB:: queries on tenant tables:** Confirmed. `DB::afterCommit()` is a lifecycle hook (not a query), `DB::transaction()` orchestrates but all data goes through Eloquent.

**Phase 2+3 Verdict:** SAFE. No cross-tenant breach. One latent WARNING on `currentBoardMemberUserIds()` (missing guard), one SUGGESTION on User scope bypass style.
