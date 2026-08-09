---
name: soft-delete-unique-pattern
description: Codebase pattern for soft-delete-aware unique constraints; plain $table->unique() breaks name reuse after soft-delete; partial index is the fix
metadata:
  type: project
---

## Soft-delete + unique constraint pattern in this codebase

### The bug

`$table->unique(['tenant_id', 'name'])` includes soft-deleted rows. If a row with `(tenantA, 'DHL')` is soft-deleted, no new row with `(tenantA, 'DHL')` can ever be inserted — DB rejects it. Worse: app-level validation using `whereNull('deleted_at')` passes, then DB raises a constraint error → 500 instead of 422.

### Fixed on 2026-06-29 for vehicle.plate

`backend/Modules/Housing/database/migrations/2026_06_29_000001_scope_vehicle_plate_unique_to_live_rows.php` retroactively converted the plain unique to:
```sql
CREATE UNIQUE INDEX vehicle_plate_unique ON "vehicle" (tenant_id, plate) WHERE deleted_at IS NULL
```

### How to do it right from the start

Use `DB::statement` inside `up()`, and a matching `DROP INDEX IF EXISTS` in `down()`:

```php
use Illuminate\Support\Facades\DB;

DB::statement(
    'CREATE UNIQUE INDEX idx_name ON table_name (tenant_id, name) WHERE deleted_at IS NULL'
);

// down():
DB::statement('DROP INDEX IF EXISTS idx_name');
Schema::dropIfExists('table_name');
```

### When to use plain $table->unique() vs partial unique

- Plain `$table->unique()` is fine when: the column is never nullable AND the entity is never soft-deleted AND name reuse after deletion is not needed.
- Partial `WHERE deleted_at IS NULL` is needed when: the entity uses SoftDeletes AND names should be reusable after deletion (e.g., human-readable names like carrier names, bank account names).

### Codebase examples

- `bank_account.name`: plain unique `$table->unique(['tenant_id', 'name'])` — accepted limitation
- `bank_account.clabe`: partial unique `WHERE deleted_at IS NULL AND clabe IS NOT NULL` — correct for nullable reusable field
- `visitor.qr_token`: plain unique — acceptable since token is generated and not human-readable
- `vehicle.plate`: was plain, fixed to partial on 2026-06-29
- `package.pickup_code`: correctly uses partial unique from the start (correct)
- `package_carrier.name`, `package_type.name`: uses plain unique — should be partial (reported as W1 in WU-1 review)

**How to apply:** Always use partial unique index for human-readable name columns on soft-deletable tenant-scoped catalog tables.
