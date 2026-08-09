# Tasks: Module 5-rest — Treasury Expenses

## Completed

### Phase 1: Migrations
- [x] create_expense_status_table (tenant-scoped, key unique per tenant)
- [x] create_beneficiary_type_table (tenant-scoped, key unique per tenant)
- [x] create_expense_table (tenant-scoped, all FKs, community_project_id nullable uuid NO FK)

### Phase 2: Models
- [x] ExpenseStatus (BelongsToTenant, GlobalObserver, KEY_PAID + KEY_VOIDED constants)
- [x] BeneficiaryType (BelongsToTenant, GlobalObserver, KEY constants)
- [x] Expense (BelongsToTenant, MoneyIntegerCast on amount, all relations, withoutGlobalScope for authorizedBy)

### Phase 3: Service
- [x] ExpenseService.register() — creates expense (status=paid), decrements bank balance, duplicate detection
- [x] ExpenseService.void() — sets status=voided, restores bank balance, guards double-void
- [x] ExpenseService.detectDuplicate() — same provider+period+amount±10% within 7 days
- [x] ExpenseService.adjustBankBalance() — lockForUpdate + raw int (consistent with PaymentService)

### Phase 4: Controllers + Requests
- [x] ExpenseStatusController (Orion, read-only catalog)
- [x] BeneficiaryTypeController (Orion, read-only catalog)
- [x] ExpenseController (Orion, performStore override for duplicate detection, void() custom action)
- [x] PublicSummaryController (GET /treasury/public-summary — aggregates only)
- [x] ExpenseCreateRequest + ExpenseUpdateRequest

### Phase 5: Routes
- [x] api.php: 3 Orion resources + POST expense/{id}/void + GET treasury/public-summary

### Phase 6: Seeding + Menu
- [x] TenantDatabaseSeeder: seedExpenseStatuses() + seedBeneficiaryTypes()
- [x] modules.csv: 3 rows (e5001001, e5001002, e5001003)
- [x] module_system.csv: 3 rows in Tesorería system
- [x] permissions.csv: 18 rows (6 per module)
- [x] SystemTableSeeder: permission entities, routes, visibility (catalogs hidden), icons
- [x] RolePermissionTableSeeder: expense + expense_status + beneficiary_type in $commonPermissions

### Phase 7: Fix Pre-existing Bug
- [x] Collections/PaymentService: added missing `adjustChargePaidAmount()` private method
  (method was referenced on lines 127/240/304/375 but never defined — was causing test failures)

### Phase 8: Tests
- [x] TreasuryExpenseTest (14 tests):
  - Catalog seeding (ExpenseStatus × 2, BeneficiaryType × 3)
  - register() decrements balance
  - void() restores balance
  - Voided expense not deleted (keeps history)
  - Cannot double-void
  - Amount money round-trip (integer storage)
  - Duplicate detection fires (same provider+period+amount)
  - Duplicate can be forced
  - BeneficiaryType: employee, other+text
  - Tenant isolation
  - Cross-module balance (payment from Collections + expense from Treasury)
  - community_project_id stored without FK
