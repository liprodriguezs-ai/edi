---
name: pattern-soft-delete-aware-unique-index
description: Recurring checkpoint - tenant-scoped SoftDeletes tables with a name/code uniqueness constraint MUST use a partial unique index (WHERE deleted_at IS NULL) via DB::statement, matching provider_tenant_name_unique / charge_concept_tenant_code_unique / receipt_sequence_tenant_year_unique — a plain $table->unique(['tenant_id','name']) silently breaks soft-delete-then-recreate flows
type: pattern
---

## Checkpoint for every migration review going forward

When a migration creates a TENANT-scoped table (`BaseModel::columns($table, true)`, has
SoftDeletes) with a `unique(['tenant_id', <col>])` constraint on a human-named/coded field
(name, code, rfc, clabe), check whether the accompanying FormRequest's `Rule::unique(...)`
includes `->whereNull('deleted_at')` (i.e. the app layer intends to allow reusing the name
after a soft-delete).

**If the FormRequest excludes soft-deleted rows but the DB constraint is a plain
`$table->unique([...])` (not partial), that is a real bug**: the FormRequest passes
validation (thinks the name is free), then the INSERT/UPDATE throws a raw
SQLSTATE 23505 unique-violation → uncaught QueryException → 500, instead of a clean 422.

**Correct pattern** (majority precedent — `provider_tenant_name_unique`,
`charge_concept_tenant_code_unique`, `receipt_sequence_tenant_year_unique`,
`amenity_reservation_by_day_unique`):
```php
DB::statement(
    'CREATE UNIQUE INDEX {table}_tenant_{col}_unique ON "{table}" '
    .'(tenant_id, {col}) '
    .'WHERE deleted_at IS NULL'
);
// ...and in down():
DB::statement('DROP INDEX IF EXISTS {table}_tenant_{col}_unique');
```

**Known outlier (pre-existing, not this PR's fault)**: `bank_account.name` uses a plain
`$table->unique(['tenant_id', 'name'])` with NO partial predicate — internally consistent
only because `BankAccountUpdateRequest`'s `name` unique rule also has no
`whereNull('deleted_at')` (so app layer and DB layer agree, even though both are arguably
wrong from a UX standpoint: a soft-deleted account's name blocks reuse forever). Don't cite
`bank_account` as valid precedent for a NEW module — it predates the partial-index
convention and was never fixed.

**Found in AmenityReservations S3-S5 review (2026-07-06)**: `amenity` table
(`2026_07_06_100004_create_amenity_table.php:46`) uses the plain (buggy) form while
`AmenityCreateRequest`/`AmenityUpdateRequest` both call `->whereNull('deleted_at')` on the
name-uniqueness rule — a genuine mismatch introduced within the same PR. Flagged as WARNING,
fix = swap to the partial-index DB::statement form.
