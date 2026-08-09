# Tasks: Module 2 — Housing / Units

## Completed Tasks

### Migrations (Housing module)
- [x] `2026_05_31_100000_create_unit_type_table.php`
- [x] `2026_05_31_100001_create_unit_status_table.php`
- [x] `2026_05_31_100002_create_unit_relationship_table.php`
- [x] `2026_05_31_100003_create_unit_table.php`
- [x] `2026_05_31_100004_create_unit_user_table.php`
- [x] `2026_05_31_100005_create_unit_unit_feature_table.php`
- [x] `2026_05_31_100006_create_vehicle_table.php`

### Models
- [x] `UnitType` (KEY_HOUSE, KEY_APARTMENT, KEY_COMMERCIAL constants)
- [x] `UnitStatus` (KEY_ACTIVE, KEY_VACANT, KEY_UNDER_CONSTRUCTION, KEY_INACTIVE)
- [x] `UnitRelationship` (KEY_OWNER, KEY_CO_OWNER, KEY_RENTER, KEY_FAMILY_RESIDENT, KEY_BILLING_CONTACT)
- [x] `Unit` (with all relationships)
- [x] `UnitUser` (with user() bypassing UserTenantScope)
- [x] `Vehicle`

### Controllers + Requests
- [x] `UnitTypeController` + Create/UpdateRequest
- [x] `UnitStatusController` + Create/UpdateRequest
- [x] `UnitRelationshipController` + Create/UpdateRequest
- [x] `UnitController` + Create/UpdateRequest
- [x] `UnitUserController` + Create/UpdateRequest (delegates to UnitUserService)
- [x] `VehicleController` + Create/UpdateRequest

### Service
- [x] `UnitUserService` with 3 invariants enforced in transaction

### Factories
- [x] UnitTypeFactory, UnitStatusFactory, UnitRelationshipFactory, UnitFactory, UnitUserFactory, VehicleFactory

### Routes
- [x] 6 new Orion resources added to `Modules/Housing/routes/api.php`

### Seeding
- [x] `TenantDatabaseSeeder` updated to seed all 3 catalogs per tenant (guarded by tenancy check)

### Dynamic Menu
- [x] `database/seeders/csv/modules.csv` — 6 new module rows
- [x] `database/seeders/csv/permissions.csv` — 36 new permission rows (6 per module)
- [x] `database/seeders/csv/module_system.csv` — 6 new Fraccionamiento system assignments
- [x] `database/seeders/SystemTableSeeder.php` — permission_entity, routes, icons, visibility (catalogs hidden)
- [x] `database/seeders/RolePermissionTableSeeder.php` — 60 new permissions in `$commonPermissions`

### Tests
- [x] `tests/Feature/Housing/UnitModuleTest.php` — 20 tests, 42 assertions
