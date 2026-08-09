# Design: Module 6 — Providers

## Architecture
Standard Kivo7 tenant module pattern:
- `Modules/Providers/` with migrations, models, controllers, routes, requests, factories
- All models use BelongsToTenant + GlobalObserver + BaseModel (UUID PK + soft deletes + audit columns)
- Module registered in modules_statuses.json with value true

## Key Decisions

### Frequency as string (not catalog)
Frequency (monthly/bimonthly/eventual) stored as plain string. A small catalog would add schema overhead for marginal benefit. The application validates values in the FormRequest. If more values are needed in the future, a catalog can be added without breaking existing data.

### estimated_amount as bigInteger (minor units)
Follows the project-wide money convention: bigInteger + MoneyIntegerCast:MXN. Never decimal/float.

### payment_method: REUSE from Collections
payment_method table lives in Collections module. Expense model uses `Modules\Collections\Models\PaymentMethod`. No duplication.

### System placement: Tesorería
Providers placed in the Tesorería system (c3d4e5f6-a7b8-9012-cdef-123456789001). Decision: providers are financially oriented (they receive payments) so they belong with treasury, not fraccionamiento.

### Partial indexes for unique constraints
- Name: `(tenant_id, name) WHERE deleted_at IS NULL` — soft-deleted providers don't block re-use of name
- RFC: COALESCE expression index only when rfc IS NOT NULL — multiple null RFCs allowed per tenant

## Files Created
- Migrations: 200001_create_provider_type, 200002_create_provider_service_type, 200003_create_provider
- Models: ProviderType, ProviderServiceType, Provider
- Controllers: ProviderTypeController, ProviderServiceTypeController, ProviderController
- Requests: ProviderCreateRequest, ProviderUpdateRequest
- Factory: ProviderFactory
- Routes: api.php (3 Orion resources)
