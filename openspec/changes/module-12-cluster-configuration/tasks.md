# Module 12 — Cluster Configuration — Tasks

## Status: COMPLETE (green gate passing)

### Migrations
- [x] No new tables (thin module — writes to tenant, company, and setting tables)

### Models
- [x] No new models; reuses: `Tenant`, `Company`, `Setting`, `Tower` (Housing)

### Controllers + Routes
- [x] `ConfigurationController` (plain Laravel Controller, not Orion):
  - `GET config/general` + `PUT config/general` (validates name/legal_name/rfc/fiscal_address/
    color_primary/color_secondary; reads/writes Company where main=true)
  - `GET config/operational` + `PUT config/operational` (validates timezone/currency/type/
    uses_ownership_share/confirmed; confirmation guard for timezone/currency changes;
    reads/writes Tenant real columns)
  - `GET config/collection-settings` + `PUT config/collection-settings` (reads 7 Setting slugs;
    upserts with auto-detected type)
  - `GET config/notification-templates` + `PUT config/notification-templates` (reads/upserts
    notification_template.* slugs in Setting)
- [x] Routes: all 8 endpoints under `auth:sanctum + initializeTenancy` middleware group

### Guards (in other modules — documented here)
- [x] `Tower.hasUnits()` (Housing model method; returns true if tower has non-deleted units)
- [x] `TowerController::beforeDestroy` (Housing; throws UnprocessableEntityHttpException if
      hasUnits() — guard enforces spec §16.4 rule 1)

### Seeding + Menu
- [x] `modules.csv`, `permissions.csv`, `module_system.csv` rows added
- [x] `SystemTableSeeder`: permission entities, routes, icons
- [x] `RolePermissionTableSeeder`: configuration entity in appropriate role array

### Tests (ConfigurationModuleTest.php — 7 tests)
- [x] A. Tenant operational columns are real columns (in getCustomColumns())
- [x] B. Tenant operational columns can be updated and reload correctly
- [x] C. Collection settings persist in Setting table (due_soon_days + overpayment_pct)
- [x] D. Collection settings are tenant-scoped (tenant A setting not visible in tenant B)
- [x] E. Tower without units → delete succeeds (hasUnits() = false)
- [x] F. Tower with units → UnprocessableEntityHttpException thrown by guard
- [x] G. Company general data update persists legal_name and color_primary

### Deferred
- [ ] Billing plan / Stripe subscription change endpoint (Cashier integration)
- [ ] Logo/favicon upload for company branding (MediaLibrary on Company)
- [ ] `bank_data_visible_to_residents` double-confirm guard (noted in docblock, not enforced)
- [ ] Audit trail for Setting table changes (GlobalObserver covers Tenant + Company only)
- [ ] Retroactive timestamp migration on timezone change
