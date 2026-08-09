---
name: package-module-schema
description: Schema review findings for Package module (WU-1): carrier/type unique bug, missing index on received_at, FK onDelete gaps
metadata:
  type: project
---

## WU-1 Schema Review — Key Findings (2026-06-29)

4 migrations: `package_status` (central), `package_carrier` (tenant), `package_type` (tenant), `package` (tenant).

### CRITICAL: None

### WARNINGS

**W1 — carrier/type plain unique ≠ soft-delete-aware** (same bug fixed for vehicle.plate same day)
- Files: `*_create_package_carrier_table.php:21`, `*_create_package_type_table.php:21`
- `$table->unique(['tenant_id', 'name'], ...)` includes soft-deleted rows. Admin cannot re-add "DHL" after deleting it; worse, app-level validation passing + DB rejecting = 500 error.
- Fix: `DB::statement('CREATE UNIQUE INDEX pkg_carrier_name_tenant_unique ON package_carrier (tenant_id, name) WHERE deleted_at IS NULL')`

**W2 — `recipient_user_id` FK has no `onDelete`**
- File: `*_create_package_table.php:21`
- Nullable, informational FK to `user`. Default NO ACTION means hard-delete of user would fail. Should be `nullOnDelete()`.
- Practical risk: near-zero (users never hard-deleted). But intent should be explicit.

**W3 — Missing index on `received_at`**
- File: `*_create_package_table.php`
- Aging report + retention cron both filter `status=READY` then sort/group by `received_at`. Orion `filterableBy(['received_at'])` creates date-range queries without an index.
- Fix: `$table->index(['package_status_id', 'received_at'], 'pkg_status_received_idx');`

**W4 — `\DB::statement` without import**
- File: `*_create_package_table.php:46`
- Uses `\DB::statement` relying on global alias; sibling migrations (`bank_account`, vehicle fix) use explicit `use Illuminate\Support\Facades\DB;`.

### SUGGESTIONS

**S1 — No explicit onDelete on structural FKs** (`unit_id`, `package_status_id`, `package_carrier_id`, `package_type_id`)
- All default to NO ACTION (PostgreSQL). `charge.unit_id` uses explicit `->restrictOnDelete()` as the model. Practical risk: zero.

**S2 — Composite index `(package_status_id, received_at)` beats two separate indexes**
- Covers the primary aging/retention query pattern optimally.

**S3 — Missing index on `package_carrier_id`**
- Orion filterableBy includes it; without an index, carrier-based filtering seq scans.

### What is correct

- Partial unique index on `(tenant_id, pickup_code) WHERE deleted_at IS NULL` — correct syntax + correct NULL handling ✓
- `pickup_code` string(9) for `XXXX-XXXX` format ✓
- `received_by_user_id` / `picked_up_by_user_id` as raw UUIDs (no FK) — correct guard pattern ✓
- `BaseModel::columns($table, false)` for central catalog, `($table, true)` for tenant-scoped ✓
- `->comment()` on every own column and first line of closure ✓
- Migration ordering (status→carrier→type→package) correctly satisfies FK deps ✓

**Why:** See [[soft-delete-unique-pattern]]
