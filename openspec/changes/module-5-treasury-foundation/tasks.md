# Tasks: Treasury Foundation

## Completed

- [x] `php artisan module:make Treasury` + registered in modules_statuses.json
- [x] Migrations: bank_account_type, bank_account_purpose, expense_category (with self-ref FK split), bank_account (with COALESCE clabe index)
- [x] Models: BankAccountType, BankAccountPurpose, BankAccount (MoneyIntegerCast, boot hook for current_balance), ExpenseCategory (self-ref parent/children)
- [x] Controllers: 4 Orion CRUD controllers
- [x] Form Requests: 8 requests with tenant-scoped validation
- [x] Service: BankAccountService::adjustBalance() for transactional balance mutations
- [x] Routes: api.php with all 4 resources
- [x] Factories: 4 Eloquent factories
- [x] TenantDatabaseSeeder: seedBankAccountTypes, seedBankAccountPurposes, seedExpenseCategories
- [x] Dynamic menu: modules.csv (+4), permissions.csv (+24), module_system.csv (+4), systems.csv (+1 Tesorería)
- [x] SystemTableSeeder: permission entities, routes, icons, visibility (catalogs hidden)
- [x] RolePermissionTableSeeder: commonPermissions (+4*6 = 24 Treasury perms)
- [x] Integration tests: 18 tests / 42 assertions (all passing)
- [x] Pint formatting applied
- [x] Green gate: migrate:fresh --seed, db:seed ResetPermissionTableSeeder, orion:specs, php artisan test (129 passed)
