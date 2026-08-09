---
name: stack-confirmed
description: Stancl single-DB tenancy confirmed for residia/backend — key files and architecture notes
metadata:
  type: project
---

Residia backend uses Stancl Tenancy v3 in **single-database mode** (one shared PostgreSQL, row-level isolation via `tenant_id` + `BelongsToTenant` trait + `TenantScope`). NO per-tenant DBs, NO `DatabaseTenancyBootstrapper`, NO `tenants:migrate`.

**Key files:**
- `app/Http/Middleware/InitializeTenancyFromUser.php` — resolves tenant from auth user; super-admin uses `X-Tenant-Id` header.
- `config/tenancy.php` — bootstrappers: Cache + Queue only.
- `core/src/Models/BaseModel.php` — `columns($table, true)` adds `tenant_id` FK + index.
- `app/Models/Scopes/UserTenantScope.php` — restricts `User` queries to current tenant's users.

**User model is CENTRAL** (no `tenant_id`). Accessed cross-tenant via `User::withoutGlobalScope(UserTenantScope::class)`.

**QueueTenancyBootstrapper** carries `tenant_id` into job payloads and re-initializes tenancy when the job runs in the worker.

**Why:** Single-DB design means all isolation is application-level — a missing `BelongsToTenant` or raw `DB::` query silently leaks data across tenants.

**How to apply:** Every new tenant-scoped model must have `BelongsToTenant` + `BaseModel::columns($table, true)`. Every manual query on a tenant table in a non-Eloquent context must include `->where('tenant_id', tenant('id'))`. Payment/idempotency lookups must always be tenant-scoped.
