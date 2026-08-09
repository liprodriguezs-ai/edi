# Tasks — Module 1: Auth/Onboarding

## Phase 1 — user table changes
- [x] Create migration `2026_05_31_062907_make_user_credentials_optional_and_add_auth_fields`
  - [x] Drop CONSTRAINT `user_email_unique` (not DROP INDEX — Postgres constraint-backed)
  - [x] Make email NULLABLE via `->change()`
  - [x] Make password NULLABLE via `->change()`
  - [x] Create partial unique index `WHERE email IS NOT NULL`
  - [x] Add `auth_active BOOLEAN NOT NULL DEFAULT true`
  - [x] Add `last_active_tenant_id UUID NULLABLE FK→tenant nullOnDelete`
- [x] Update `User` model: `$fillable`, `casts()`, `lastActiveTenant()`, `profiles()`, `currentProfile()`

## Phase 2 — UserProfile module
- [x] `php artisan module:make UserProfile`
- [x] Register in `modules_statuses.json`
- [x] Create migration `create_user_profile_table` (tenant-scoped, no FK on user_id)
- [x] Create model `UserProfile` (BelongsToTenant, ObservedBy GlobalObserver)
- [x] Create factory `UserProfileFactory`
- [x] Create controller `UserProfileController` (keyForPermission='user_profile')
- [x] Create `UserProfileCreateRequest` + `UserProfileUpdateRequest` (scoped unique)
- [x] Update routes `api.php` (Orion resource, auth:sanctum + initializeTenancy)
- [x] Add module to `modules.csv`, `permissions.csv` (6 perms), `module_system.csv`
- [x] Update `SystemTableSeeder` (entity, route, icon)
- [x] Update `RolePermissionTableSeeder` (user_profile in commonPermissions)

## Phase 3 — Kivo7 roles
- [x] Add 10 new SLUG/UUID constants to `core/src/Models/Role.php`
- [x] Add 10 new rows to `database/seeders/csv/roles.csv`
- [x] Verify `ResetPermissionTableSeeder` exits 0 (legacy roles preserved)

## Phase 4 — Multi-cluster tenant selection
- [x] Rewrite `InitializeTenancyFromUser::handle()` with `resolveActiveTenant()`
- [x] Security: X-Tenant-Id validated against `user.tenants()->pluck('id')`
- [x] Persist valid selection to `last_active_tenant_id` via `updateQuietly()`
- [x] Update `AuthController::login()` — resolve active tenant + return `tenants` (all)
- [x] Update `AuthController::me()` — same pattern
- [x] Block `auth_active=false` users in `login()` with 403

## Phase 5 — Integration tests
- [x] `tests/Feature/Auth/AuthFlowTest.php` (12 tests)
  - [x] register creates user+tenant+role, returns token
  - [x] login returns token+abilities+tenants+tenant
  - [x] auth_active=false → 403
  - [x] wrong credentials → 401
  - [x] me returns user+tenants
  - [x] logout deletes token
  - [x] null-email user creation
  - [x] two null-email users coexist
  - [x] duplicate non-null email fails
  - [x] X-Tenant-Id (valid) scopes + persists
  - [x] X-Tenant-Id (invalid) ignored, no leak
  - [x] last_active_tenant_id used on subsequent request
- [x] `tests/Feature/UserProfile/UserProfileTest.php` (6 tests)
  - [x] profile isolation across tenants
  - [x] same user profile in 2 tenants
  - [x] duplicate in same tenant fails
  - [x] CRUD round-trip
  - [x] user() relation
  - [x] API search endpoint 200

## Phase 6 — Green gate
- [x] `php artisan migrate:fresh --seed` → all migrations pass, 15 modules seeded
- [x] `php artisan db:seed --class=ResetPermissionTableSeeder` → 17 roles, 90 permissions
- [x] `php artisan orion:specs` → exit 0, user_profile paths in spec
- [x] `php artisan test` → 66 passed (0 failures)
- [x] `vendor/bin/pint` → formatting applied
