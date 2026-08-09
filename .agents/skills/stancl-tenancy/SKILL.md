---
name: stancl-tenancy
description: Tenancy (multi-tenant) conventions for a Laravel application. SINGLE-DATABASE mode with Stancl Tenancy — one shared database, row-level isolation by a tenant_id column + TenantScope. Use when adding or changing tenant-scoped models, migrations, User/tenant scoping, middleware, or cross-tenant queries.
---

# Stancl Tenancy (Single-Database)

This codebase uses **Stancl Tenancy v3** in **single-database mode**: ALL tenants share **ONE** PostgreSQL database. Isolation is **row-level**, by a `tenant_id` column on every tenant-scoped table plus the `TenantScope` global scope (provided by the `BelongsToTenant` trait). There are **NO per-tenant databases** and **NO database-connection switching**.

> History: this codebase was migrated FROM multi-database (one DB per tenant) TO single-database. If you see references to per-tenant DBs, `DatabaseTenancyBootstrapper`, `HasDatabase`, `tenants:migrate`, or a `module.json` `"tenancy"` flag, that is the OLD model — it no longer applies.

---

## Architecture (resumen)

| Concept | Detail |
|--------|--------|
| **One database** | Everything lives in the default connection. Central data (users, roles, catalogs, tenants) and tenant data coexist in the same DB. |
| **Tenant isolation** | Each tenant-scoped row carries a `tenant_id`. The `TenantScope` global scope auto-adds `where tenant_id = <current tenant>` to every query on a tenant model. |
| **Current tenant** | Set per request by middleware via `tenancy()->initialize($tenant)`. This ONLY sets the current tenant (which drives the scope) — it does NOT switch databases. |
| **Bootstrappers** | `config/tenancy.php` keeps `CacheTenancyBootstrapper` + `QueueTenancyBootstrapper`. `DatabaseTenancyBootstrapper` is REMOVED (it was for multi-DB connection switching). |

---

## 1. Making a model tenant-aware (PRIMARY models)

A primary model is one that directly belongs to a tenant. Add Stancl's trait:

```php
use App\Core\Models\BaseModel;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class Deal extends BaseModel
{
    use BelongsToTenant;
    // ...
}
```

`BelongsToTenant` does two things automatically:
- **On create:** stamps `tenant_id` with the current tenant's key (`tenant()->getTenantKey()`), if tenancy is initialized.
- **On every query:** adds the `TenantScope` global scope → `Deal::all()` returns ONLY the current tenant's deals.

It also provides a `tenant()` relationship. The default foreign-key column is `tenant_id` (configurable project-wide via the static `BelongsToTenant::$tenantIdColumn`, but DO NOT change it here — everything uses `tenant_id`).

---

## 2. Central vs tenant models

| Kind | Trait | Examples |
|------|-------|----------|
| **Tenant-scoped** (business data) | `use BelongsToTenant;` + a `tenant_id` column | Deal, Client, Company, Ticket, Task, Campaign, Document, Collaborator, Tag, … (the business modules) |
| **Central / shared** (platform data) | NO `BelongsToTenant`, NO `tenant_id` | `User`, `Role`, `Permission`, `Tenant`, `SubscriptionPlan`, `System`, and catalogs (`Country`, `State`, `Municipality`, `Nationality`) |

Central models are shared across ALL tenants — never give them `BelongsToTenant` (their tables have no `tenant_id`; the scope would add a `where tenant_id = ?` against a non-existent column and error).

---

## 3. Migrations

Tenant tables are created with the project's `BaseModel::columns()` helper. The **second argument** controls `tenant_id`:

```php
// TENANT table → pass true. Adds tenant_id (NOT NULL) + FK to `tenant` + index.
Schema::create('deal', function (Blueprint $table) {
    \App\Core\Models\BaseModel::columns($table, true);
    $table->string('name');
    // ...
});

// CENTRAL table → pass false (the default). No tenant_id.
Schema::create('country', function (Blueprint $table) {
    \App\Core\Models\BaseModel::columns($table, false);
    // ...
});
```

`BaseModel::columns($table, true)` emits:
```php
$table->foreignUuid('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
$table->index('tenant_id');
```

**All migrations run on the single connection via `php artisan migrate`.** There is no `tenants:migrate`, no per-tenant migration pass, and no `module.json` `"tenancy"` routing — `TenancyServiceProvider::registerModuleMigrations()` loads every enabled module's migrations into the normal `migrate` run.

### Unique indexes MUST include tenant_id

Because all tenants share the table, a plain unique would block a value globally. Scope every business-unique by `tenant_id`:

```php
$table->unique(['tenant_id', 'slug']);
$table->unique(['tenant_id', 'folio']);
$table->unique(['tenant_id', 'email']);
```

(Relationship/pivot uniques like `unique(['client_id', 'key'])` are already tenant-isolated through the FK to a tenant-scoped parent — leave those as-is.)

---

## 4. Querying

| Goal | How |
|------|-----|
| Current tenant's rows (default) | `Deal::all()` — `TenantScope` adds `where tenant_id = <current>` automatically |
| Bypass the scope (super-admin, cross-tenant) | `Deal::withoutGlobalScope(\Stancl\Tenancy\Database\TenantScope::class)->...` (verified in this codebase). Stancl docs also reference a `Model::withoutTenancy()` helper. |
| Run across ALL tenants | iterate: `foreach (Tenant::all() as $t) { tenancy()->initialize($t); /* ... */ tenancy()->end(); }` |

- **NEVER use the `DB::` facade for tenant data.** Raw queries bypass `TenantScope` → cross-tenant leak. Always go through Eloquent models.
- Isolation is **application-level only** (no DB-level separation). A forgotten `BelongsToTenant` on a tenant model, or a raw query, silently exposes other tenants' data. When in doubt, verify the generated SQL contains `tenant_id`.

---

## 5. Secondary / pivot models (BelongsToPrimaryModel)

A **secondary** model belongs to a tenant INDIRECTLY, through a parent (e.g. a comment belongs to a post which belongs to a tenant). It usually does NOT carry its own `tenant_id`. To scope it safely, Stancl provides `BelongsToPrimaryModel`:

```php
use Stancl\Tenancy\Database\Concerns\BelongsToPrimaryModel;

class TicketMessage extends BaseModel
{
    use BelongsToPrimaryModel;

    public function getRelationshipToPrimaryModel(): string
    {
        return 'ticket'; // the relation to a BelongsToTenant parent
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
```

This scopes `TicketMessage::all()` through the (already-scoped) parent. Limitation: it only works one level deep; for deeply nested models, prefer giving the model its own `tenant_id` + `BelongsToTenant`.

### Pivot/polymorphic tables in THIS codebase (current state)

Three tables were left WITHOUT `tenant_id` on purpose — they are reached only via a tenant-scoped parent (FK cascade), and giving them `tenant_id` would break `attach()` (direct inserts bypass model `creating` events, so the trait can't stamp it):
- `taggable` (Tag ↔ any entity)
- `campaign_contact` (Campaign ↔ Contact)
- `media` (Spatie media, attached to a tenant-scoped owner)

If you ever query these DIRECTLY (not via the parent relation), add `BelongsToPrimaryModel` (pointing at the tenant-scoped parent) or a real `tenant_id` set explicitly on insert.

---

## 6. Tenant resolution (middleware)

`app/Http/Middleware/InitializeTenancyFromUser.php` (alias `initializeTenancy`) sets the current tenant from the authenticated user. Runs AFTER `auth:sanctum`. Single-DB: it only calls `tenancy()->initialize($tenant)` (sets scope) — no DB switching, no migration.

- **Regular user:** tenant resolved from `$user->tenant_id ?? $user->tenants()->first()?->id` (the `user_tenant` pivot).
- **Super admin / lawyer:** if the `X-Tenant-Id` header is present, the request is scoped to that tenant. Without the header they run unscoped (use `withoutGlobalScope(TenantScope::class)` for cross-tenant reads).
- Tenant routes use middleware `['auth:sanctum', 'initializeTenancy']` (in that order).

Context helpers: `tenancy()->initialized`, `tenant()` / `tenancy()->tenant`, `tenant()->getTenantKey()`, `tenant('id')`.

---

## 7. Creating a tenant

```php
$tenant = \Modules\Tenant\Models\Tenant::create(['name' => $slug]);
```

No database is created. The `TenantCreated` event (in `TenancyServiceProvider`) runs `tenants:seed` for the new tenant after commit, which seeds its default rows (work shifts, nationalities, document types, etc.) — `BelongsToTenant` stamps `tenant_id` on each because tenancy is initialized during the seeder. The database-creation jobs (`CreateDatabase`, `MigrateDatabase`) are NOT wired to `TenantCreated`.

The `Tenant` model is a plain central record: `extends Stancl\Tenancy\Database\Models\Tenant` (NO `HasDatabase`, NO `TenantWithDatabase`).

---

## 8. Validation rules (unique/exists are NOT auto-scoped)

The string/`Rule::unique`/`Rule::exists` validators query the table directly and IGNORE `TenantScope`. Scope them manually so a value used by another tenant does not cause a false rejection:

```php
'slug' => [Rule::unique('client', 'slug')->where('tenant_id', tenant('id'))],
```

(Stancl also offers `HasScopedValidationRules` on the Tenant model: `$tenant->unique('client')`.) Note: the central `user.email` uniqueness is intentionally GLOBAL (users are central), so it is NOT tenant-scoped.

---

## 9. User references from tenant tables

The `users` table now lives in the SAME database, so a FK to it is technically possible — but the codebase keeps user references as **bare UUID columns** (no FK), matching `BaseModel`'s audit columns (`user_created_id`, `user_updated_id`, …). 

`User` has its OWN scope, `UserTenantScope` (restricts users to the current tenant via `user_tenant`). Therefore any `belongsTo(User::class, …)` on a tenant model MUST strip it so the relation resolves:

```php
public function assigned_user(): BelongsTo
{
    return $this->belongsTo(User::class, 'assigned_to')
        ->withoutGlobalScope(UserTenantScope::class);
}
```

For audit-actor relations (e.g. `user_created`) also add `->withTrashed()`.

---

## 10. Gotchas

- **App-level isolation only.** No DB-level separation — a missing `BelongsToTenant` or a raw `DB::` query leaks data. Verify generated SQL has `tenant_id`.
- **`tenant_id` is NOT NULL.** A tenant model created WITHOUT tenancy initialized fails the not-null constraint. Super-admin writes that need a specific tenant must initialize it first (or set `tenant_id` explicitly).
- **PHP 8.4 deprecation (cosmetic):** Stancl v3.10's `BelongsToTenant` accesses `BelongsToTenant::$tenantIdColumn` statically, which PHP 8.4 deprecates. It is noise only — the auto-stamp and scoping work correctly. A Stancl patch / minor upgrade removes the warning.
- **No `module.json` tenancy flag.** It was removed (it only mattered for multi-DB migration routing). Whether a table is tenant-scoped is now expressed ONLY by `BaseModel::columns($table, true)` + the `BelongsToTenant` trait.
- **Cache & Queue tenancy stay on.** `CacheTenancyBootstrapper` prefixes cache per tenant; `QueueTenancyBootstrapper` carries `tenant_id` into job payloads and re-initializes tenancy when the job runs.

---

## 11. Checklist: new tenant-scoped model/table

- [ ] Migration: `BaseModel::columns($table, true)` (adds `tenant_id` + FK + index).
- [ ] Every business-unique index includes `tenant_id`: `unique(['tenant_id', 'col'])`.
- [ ] Model: `use Stancl\Tenancy\Database\Concerns\BelongsToTenant;`.
- [ ] Relations to `User`: `->withoutGlobalScope(UserTenantScope::class)` (+ `->withTrashed()` for audit actors).
- [ ] Secondary/pivot model queried directly? add `BelongsToPrimaryModel` (or its own `tenant_id`).
- [ ] `Rule::unique`/`exists` on tenant tables scoped by `->where('tenant_id', tenant('id'))`.
- [ ] No `DB::` facade for tenant data.

---

## Key files

| File | Role |
|------|------|
| `config/tenancy.php` | Bootstrappers (Cache + Queue only); NO `database` section, NO `DatabaseTenancyBootstrapper`. |
| `core/src/Models/BaseModel.php` | `columns(Blueprint $table, bool $withTenant = false)` — `true` adds the `tenant_id` FK + index. |
| `app/Http/Middleware/InitializeTenancyFromUser.php` | Resolves tenant from user; `tenancy()->initialize()` (scope-only, no DB switch); super-admin via `X-Tenant-Id`. |
| `app/Providers/TenancyServiceProvider.php` | Loads ALL module migrations into `migrate`; `TenantCreated` → `tenants:seed`; no DB creation/deletion. |
| `app/Models/Scopes/UserTenantScope.php` | The User-only scope (separate from `TenantScope`); bypass with `User::withoutTenancy()`. |
| `Modules/Tenant/Models/Tenant.php` | Plain central tenant record (no `HasDatabase` / `TenantWithDatabase`). |

---

## References

- [Stancl Single-Database Tenancy](https://tenancyforlaravel.com/docs/v3/single-database-tenancy) — `BelongsToTenant`, `TenantScope`, `BelongsToPrimaryModel`, unique constraints, caveats.
- [Stancl Tenancy Docs](https://tenancyforlaravel.com/docs/v3/)
