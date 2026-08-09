# Proposal: Module 6 — Providers

## Intent
Implement a tenant-scoped providers catalog that tracks external companies and individuals who receive regular payments from the residential complex. Providers are linked to expenses (Module 5-rest) for payment history.

## Scope
- New `Providers` Laravel module with 3 models: `ProviderType`, `ProviderServiceType`, `Provider`
- Tenant-scoped catalogs for type and service type (seeded per tenant via TenantDatabaseSeeder)
- `Provider` model with money field `estimated_amount` (bigInteger + MoneyIntegerCast)
- Orion CRUD for all 3 resources
- `Provider.expenses()` hasMany to `Expense` (Module 5) for payment history
- Menu registration in Tesorería system

## Approach
Follow existing tenant module pattern (BelongsToTenant, GlobalObserver, Orion controller). Frequency stored as string ('monthly'/'bimonthly'/'eventual') — no enum to remain flexible.

## Constraints
- Provider cannot be deleted if it has associated expenses (soft-delete only)
- Name unique per tenant (partial index WHERE deleted_at IS NULL)
- RFC unique per tenant when non-null (COALESCE expression partial index)
- Placed in Tesorería system in module_system.csv (system ID: c3d4e5f6-a7b8-9012-cdef-123456789001)
