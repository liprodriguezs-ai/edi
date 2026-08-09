# Spec — Module 1: Auth/Onboarding

## Requirements

### R1 — Nullable credentials
- `user.email` NULLABLE, partial unique (only non-null emails must be unique in Postgres).
- `user.password` NULLABLE.
- Multiple users with `email = NULL` can coexist without violating unique constraint.

### R2 — auth_active flag
- New column `user.auth_active BOOLEAN NOT NULL DEFAULT true`.
- `AuthController.login()` rejects users with `auth_active = false` with HTTP 403.

### R3 — last_active_tenant_id
- New column `user.last_active_tenant_id UUID NULLABLE FK → tenant(id) NULL ON DELETE`.
- Used by `InitializeTenancyFromUser` as the first-priority fallback tenant.

### R4 — UserProfile module (tenant-scoped PII)
- Table `user_profile` with `BaseModel::columns($table, true)`.
- Unique constraint `['tenant_id','user_id']` — one profile per user per tenant.
- No FK from `user_id` to `user` table (cross-connection boundary safety).
- Soft deletes inherited from BaseModel.

### R5 — Kivo7 roles (Spatie catalog)
- 10 new roles added to `roles.csv` and `core/src/Models/Role.php`:
  administrator, president, treasurer, secretary, vocal, oversight_committee,
  security, operations_staff, resident, renter.
- `admin.super` reused as the 11th "platform admin" role.
- Legacy roles preserved (account.owner, company.admin, etc.) — no breakage.

### R6 — Multi-cluster tenant selection
- Regular users (not super_admin/lawyer) may send `X-Tenant-Id` header.
- Middleware MUST validate the header value is in `user.tenants()` — never trust blindly.
- Valid header: initialize to that tenant + persist to `last_active_tenant_id`.
- Invalid header: fall back (no error, no leak).
- Fallback order: valid `last_active_tenant_id` → `tenants()->first()`.

### R7 — AuthController response shape
- `login()` and `me()` return `tenants` (all user tenants) + `tenant` (resolved active).
- Back-compat: `tenant` key preserved at same position.

## Scenarios

| # | Scenario | Expected |
|---|----------|----------|
| 1 | Register new user | 200 + token + tenant + role = account.owner |
| 2 | Login valid credentials | 200 + access_token + tenants[] + tenant |
| 3 | Login with auth_active=false | 403 |
| 4 | Login wrong password | 401 |
| 5 | GET /auth/me | 200 + user.id + tenants + tenant |
| 6 | POST /auth/logout | 200 + token deleted |
| 7 | Create user with email=null | Succeeds |
| 8 | Two users with email=null | Both coexist |
| 9 | Duplicate non-null email | UniqueConstraintViolationException |
| 10 | X-Tenant-Id for tenant user belongs to | Scoped, persisted |
| 11 | X-Tenant-Id for tenant user doesn't belong to | Ignored, no leak |
| 12 | last_active_tenant_id used when no header | Active tenant = B |
| 13 | UserProfile visible only in its tenant | Cross-tenant = 0 |
| 14 | Same user profile in 2 tenants | Both exist |
| 15 | Duplicate user_profile same tenant | UniqueConstraintViolationException |
| 16 | UserProfile CRUD round-trip | Full lifecycle works |
| 17 | UserProfile user() relation | Resolves correctly |
| 18 | GET /api/user_profile/search | 200 |
