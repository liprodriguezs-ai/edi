# Design — Module 1: Auth/Onboarding

## Architecture Decisions

### D1 — Partial unique index (Postgres-specific)
**Decision**: Replace the `UNIQUE` constraint on `user.email` with a Postgres partial index
`CREATE UNIQUE INDEX user_email_unique ON "user" (email) WHERE email IS NOT NULL`.

**Why**: A standard UNIQUE constraint treats all NULLs as distinct in SQL standard but
Postgres honours this — however Laravel's `->unique()` creates a CONSTRAINT not a bare index,
so we must `ALTER TABLE "user" DROP CONSTRAINT "user_email_unique"` before creating the partial index.

**Files**: `database/migrations/2026_05_31_062907_make_user_credentials_optional_and_add_auth_fields.php`

### D2 — UserProfile as a dedicated Nwidart module
**Decision**: New `Modules/UserProfile` module (not placed under `Modules/User` which is central).

**Why**: `User` is a central model (CentralConnection); `UserProfile` is tenant-scoped.
Mixing tenant-scoped tables inside a central module would be architecturally wrong.
A separate module keeps the tenancy contract explicit (`module.json` without `"tenancy"` key,
which defaults to `"central"` — so migrations run in the shared DB, which is correct for
single-DB tenancy where `BelongsToTenant` handles isolation by row).

**Important**: This project uses SINGLE-DATABASE tenancy (row-level isolation via `tenant_id`),
NOT per-tenant database. `BelongsToTenant` + `BaseModel::columns($table, true)` is the
isolation mechanism, not `module.json "tenancy": "tenant"`. The `stancl-tenancy` skill
describes the per-tenant-DB pattern — that skill is a legacy reference for a different setup.

### D3 — No FK from user_profile.user_id to user
**Decision**: `user_profile.user_id` is a plain UUID column with an index, no FK constraint.

**Why**: In single-DB Postgres the FK would technically work, but it creates a conceptual
cross-boundary dependency (user is central, user_profile is tenant-scoped). Keeping it
without an FK is the safer, more portable choice. The unique constraint `['tenant_id','user_id']`
enforces the one-profile-per-user-per-tenant business rule at the DB level.

### D4 — Multi-cluster security: X-Tenant-Id validation is membership-gated
**Decision**: In `InitializeTenancyFromUser::resolveActiveTenant()`, the `X-Tenant-Id` header
is ONLY honoured if `$user->tenants()->pluck('tenant.id')` contains the requested ID.

**Why**: This is security-critical. A malicious client could pass any tenant UUID to try to
access another tenant's data. The membership check prevents that. The check is done in
application code (not middleware-level policy) so it can be easily tested.

### D5 — `last_active_tenant_id` persisted quietly
**Decision**: Use `$user->updateQuietly()` inside a `withoutEvents()` closure when persisting
the cluster selection.

**Why**: Avoids triggering `Updated` model events (which would fire GlobalObserver audit,
creating noise for a bookkeeping-only operation). The `updateQuietly` ensures no observers
run for this specific update.

### D6 — Roles are Spatie GLOBAL (not tenant-scoped)
**Decision**: All 10 new Kivo7 roles are added to the global Spatie `roles` table with stable UUID constants.

**Why**: The existing system uses global Spatie roles throughout. Tenant-scoped role assignment
(a role meaning different things per tenant) is not needed at this stage. The 10 UUIDs are stable
constants in `core/src/Models/Role.php` for code-level referencing.

## Files Modified / Created

| File | Change |
|------|--------|
| `database/migrations/2026_05_31_062907_*.php` | NEW: email/password nullable + partial index + auth_active + last_active_tenant_id |
| `app/Models/User.php` | auth_active/last_active_tenant_id in $fillable + casts; lastActiveTenant(), profiles(), currentProfile() |
| `app/Http/Controllers/AuthController.php` | login() + me() return tenants[]; auth_active=false → 403 |
| `app/Http/Middleware/InitializeTenancyFromUser.php` | Multi-cluster resolution with membership validation |
| `core/src/Models/Role.php` | 10 new SLUG/UUID constants |
| `database/seeders/csv/roles.csv` | 10 new rows |
| `database/seeders/csv/modules.csv` | UserProfile module row |
| `database/seeders/csv/permissions.csv` | 6 permissions for user_profile |
| `database/seeders/csv/module_system.csv` | UserProfile → Fraccionamiento system |
| `database/seeders/SystemTableSeeder.php` | entity, route, icon for Perfil de Residente |
| `database/seeders/RolePermissionTableSeeder.php` | user_profile permissions in commonPermissions |
| `Modules/UserProfile/` | NEW module: model, controller, requests, factory, routes, migration |
| `modules_statuses.json` | UserProfile: true |
| `tests/Feature/Auth/AuthFlowTest.php` | NEW: 12 auth integration tests |
| `tests/Feature/UserProfile/UserProfileTest.php` | NEW: 6 UserProfile tests |
