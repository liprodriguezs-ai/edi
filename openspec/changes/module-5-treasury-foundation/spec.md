# Spec: Treasury Foundation

## Tables
| Table | Tenant-scoped | Key columns |
|---|---|---|
| bank_account_type | yes | key, name, order, is_active |
| bank_account_purpose | yes | key, name, order, is_active |
| bank_account | yes | name, bank_account_type_id, bank_account_purpose_id, bank, account_number, clabe, currency(3), initial_balance(bigint), current_balance(bigint), is_primary, is_active, notes |
| expense_category | yes | name, parent_id (self-ref nullable), is_active, order |

## Unique Constraints
- `bank_account_type`: (tenant_id, key), (tenant_id, name)
- `bank_account_purpose`: (tenant_id, key), (tenant_id, name)
- `bank_account`: (tenant_id, name); CLABE: partial expression index (tenant_id, COALESCE(clabe,'')) WHERE deleted_at IS NULL AND clabe IS NOT NULL
- `expense_category`: expression index (tenant_id, COALESCE(parent_id::text,''), name) WHERE deleted_at IS NULL

## Catalog Keys
- BankAccountType: KEY_BANK='bank', KEY_CASH='cash', KEY_INVESTMENT='investment'
- BankAccountPurpose: KEY_OPERATING='operating', KEY_RESERVE_FUND='reserve_fund', KEY_PAYROLL='payroll', KEY_PETTY_CASH='petty_cash', KEY_OTHER='other'

## TenantDatabaseSeeder additions
- 3 BankAccountType rows
- 5 BankAccountPurpose rows
- 7 ExpenseCategory top-level rows: Salarios, Servicios públicos, Mantenimiento, Insumos, Bancarias, Imprevistos, Proyectos

## Orion APIs
- GET/POST/PUT/DELETE bank_account_type
- GET/POST/PUT/DELETE bank_account_purpose
- GET/POST/PUT/DELETE bank_account (includes: type, purpose)
- GET/POST/PUT/DELETE expense_category (includes: parent, children)

## Menu System
New system "Tesorería" (id: c3d4e5f6-a7b8-9012-cdef-123456789001)
- Cuentas Bancarias (visible, icon: IconBuildingBank)
- Categorías de Egreso (visible, icon: IconTags)
- Tipos de Cuenta (hidden, catalog)
- Propósitos de Cuenta (hidden, catalog)
