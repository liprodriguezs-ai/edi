# Spec: Module 2 — Housing / Units

## Tables

### unit_type (tenant-scoped)
| Column | Type | Notes |
|--------|------|-------|
| (BaseModel cols) | | id UUID, timestamps, soft-delete, audit, tenant_id |
| name | string | Spanish UI label |
| key | string | stable slug (house/apartment/commercial) |
| order | integer default 0 | |
| is_active | boolean default true | |
- Unique: `[tenant_id, key]`, `[tenant_id, name]`

### unit_status (tenant-scoped)
| Column | Type | Notes |
|--------|------|-------|
| name | string | |
| key | string | lifecycle slug |
| color | string default '#6B7280' | hex color |
| order | integer | |
| is_active | boolean | |
- Unique: `[tenant_id, key]`

### unit_relationship (tenant-scoped)
| Column | Type | Notes |
|--------|------|-------|
| name | string | |
| key | string | |
| order | integer | |
| is_active | boolean | |
- Unique: `[tenant_id, key]`

### unit (tenant-scoped)
| Column | Type | Notes |
|--------|------|-------|
| unit_type_id | uuid FK→unit_type | |
| number | string nullable | apartment/house number |
| tower_id | uuid FK→tower nullable | |
| floor | string nullable | |
| section_id | uuid FK→section nullable | |
| built_m2 | decimal(9,2) nullable | |
| land_m2 | decimal(9,2) nullable | |
| ownership_share | decimal(7,4) nullable | indiviso % |
| unit_status_id | uuid FK→unit_status | |
| notes | text nullable | |
- Unique: `[tenant_id, unit_type_id, tower_id, floor, number]`

### unit_user (tenant-scoped)
| Column | Type | Notes |
|--------|------|-------|
| unit_id | uuid FK→unit | |
| user_id | uuid index | central user, no cross-boundary FK |
| unit_relationship_id | uuid FK→unit_relationship | |
| is_billing_owner | boolean default false | at most 1 active per unit |
| is_legal_representative | boolean default false | at most 1 active per unit |
| start_date | date nullable | |
| end_date | date nullable | null = active |
| notes | text nullable | |

### unit_unit_feature (tenant-scoped)
- Pivots unit ↔ unit_feature
- Unique: `[tenant_id, unit_id, unit_feature_id]`

### vehicle (tenant-scoped)
| Column | Type | Notes |
|--------|------|-------|
| unit_id | uuid FK→unit | |
| plate | string | |
| make | string nullable | |
| model | string nullable | |
| color | string nullable | |
| year | smallint nullable | |
- Unique: `[tenant_id, plate]`

## Business Invariants (UnitUserService)
1. At most ONE active (`end_date IS NULL`) `is_billing_owner=true` per unit. Setting a new one clears the previous.
2. At most ONE active `is_legal_representative=true` per unit. Same clearing behavior.
3. Assigning a `renter` (by key) relationship requires at least one active `owner` on the unit. Validation error if none.
All enforced in `DB::transaction()` with Spanish validation messages.

## Catalog Seeding
- `TenantDatabaseSeeder` seeds all 3 catalogs per tenant using `updateOrCreate(['key' => ...], $data)`
- Guard: `if (tenancy()->initialized)` prevents failure when called from root `DatabaseSeeder` without tenant context
- Idempotent: running twice produces same count (verified by test)
