# Proposal: Module 2 — Housing / Units

## Intent
Extend the Housing module with the unit and resident data model to support full unit management in a multi-tenant residential complex SaaS.

## Scope
Add to `backend/Modules/Housing/`:
- Three tenant-scoped catalog tables: `unit_type`, `unit_status`, `unit_relationship`
- `unit` table (the central entity — apartment/house/commercial space)
- `unit_user` pivot (persona-unit assignments with domain invariants)
- `unit_unit_feature` pivot (unit ↔ feature many-to-many)
- `vehicle` table (vehicles registered per unit)
- Full Orion CRUD for all 6 resources + routes + form requests
- Per-tenant seeding of all 3 catalogs from TenantDatabaseSeeder
- Dynamic menu registration for 6 new modules
- 20 PHPUnit integration tests

## Approach
- Single-DB Stancl tenancy: `BaseModel::columns($table, true)` + `BelongsToTenant` on all new tables
- Catalogs keyed by `key` column (stable slug, e.g. `'house'`) with `KEY_*` class constants — NOT UUID PKs, since each tenant gets independent rows
- Business invariants (billing_owner, legal_rep, renter+owner) enforced in `UnitUserService` inside `DB::transaction()`
- `unit_user` exposed as top-level Orion resource (not nested); `performStore/performUpdate` delegate to service
- Catalog seeding guarded by `tenancy()->initialized` check to allow safe call from root DatabaseSeeder
