# Spec: Module 6 — Providers

## Tables

### provider_type (tenant-scoped)
- id (uuid PK), tenant_id (FK), key (string, indexed), name (string), order (int), is_active (bool)
- Unique: (tenant_id, key)
- Keys: individual → "Persona física", company → "Persona moral"

### provider_service_type (tenant-scoped)
- id, tenant_id, key, name, order, is_active
- Unique: (tenant_id, key)
- Keys: waste_collection, utilities, maintenance, gardening, cleaning, supplies, professional, other

### provider (tenant-scoped)
- id, tenant_id, provider_type_id (FK), name, rfc (nullable), provider_service_type_id (FK null), contact_person (null), phone (null), email (null), address (text null), is_recurring (bool default false), frequency (string null: monthly/bimonthly/eventual), estimated_amount (bigInteger null, Money), usual_payment_day (smallint null), clabe (null), bank (null), notes (text null), is_active (bool default true)
- Unique indexes: (tenant_id, name) WHERE deleted_at IS NULL; COALESCE(rfc, '') WHERE deleted_at IS NULL AND rfc IS NOT NULL

## API Endpoints
- GET/POST /api/provider_type + search + soft deletes (Orion)
- GET/POST /api/provider_service_type + search + soft deletes (Orion)
- GET/POST /api/provider + search + soft deletes (Orion); includes: providerType, serviceType, expenses
- Permissions: read/create/update/delete/restore/report per resource

## Business Rules
- Provider.expenses: hasMany Expense where provider_id = this.id (payment history)
- estimated_amount is informational — used to pre-fill expense form
- Soft-delete only if has expenses; implemented at API level (not enforced at DB level)
