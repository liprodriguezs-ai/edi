# Tasks: Module 6 — Providers

## Completed

### Phase 1: Module scaffold
- [x] `php artisan module:make Providers`
- [x] Update modules_statuses.json (add Providers: true)
- [x] Update module.json description

### Phase 2: Migrations
- [x] create_provider_type_table (tenant-scoped, key unique per tenant)
- [x] create_provider_service_type_table (tenant-scoped, key unique per tenant)
- [x] create_provider_table (tenant-scoped, FKs to type + service type, partial index on name + rfc)

### Phase 3: Models
- [x] ProviderType (BelongsToTenant, GlobalObserver, KEY constants)
- [x] ProviderServiceType (BelongsToTenant, GlobalObserver, KEY constants)
- [x] Provider (BelongsToTenant, MoneyIntegerCast on estimated_amount, relations to type/serviceType/expenses)

### Phase 4: Controllers + Requests
- [x] ProviderTypeController (Orion, keyForPermission: provider_type)
- [x] ProviderServiceTypeController (Orion, keyForPermission: provider_service_type)
- [x] ProviderController (Orion, keyForPermission: provider, includes/filter/search)
- [x] ProviderCreateRequest + ProviderUpdateRequest

### Phase 5: Routes
- [x] api.php (3 Orion resources with auth:sanctum + initializeTenancy)

### Phase 6: Seeding + Menu
- [x] TenantDatabaseSeeder: seedProviderTypes() + seedProviderServiceTypes()
- [x] modules.csv: 3 rows (f6001001, f6001002, f6001003)
- [x] module_system.csv: 3 rows in Tesorería system
- [x] permissions.csv: 18 rows (6 per module)
- [x] SystemTableSeeder: permission entities, routes, visibility (Tipos/Tipos de Servicio hidden), icons
- [x] RolePermissionTableSeeder: all 3 entities in $commonPermissions

### Phase 7: Tests
- [x] ProvidersModuleTest (11 tests): catalog seeding, CRUD, money round-trip, tenant isolation, unique constraints
