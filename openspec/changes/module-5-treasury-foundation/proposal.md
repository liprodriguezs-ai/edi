# Proposal: Treasury Foundation (Module 5)

## Intent
Implement the financial data layer for the Kivo7 backend: bank accounts (including cash boxes) and expense categories — the foundation that Collections (payments) and the rest of Treasury (expenses) will build on.

## Scope
New Laravel module `Treasury` with four tenant-scoped resources:
1. `bank_account_type` — catalog (bank / cash / investment)
2. `bank_account_purpose` — catalog (operating / reserve_fund / payroll / petty_cash / other)
3. `bank_account` — the main account record with money columns
4. `expense_category` — hierarchical categories with optional parent

## Key Decisions
- Money = bigInteger minor units + `Cknow\Money\Casts\MoneyIntegerCast`. NEVER decimal.
- `current_balance` defaults to `initial_balance` on create (model boot hook); later modules maintain it transactionally via `BankAccountService::adjustBalance()`.
- Catalogs use string `key` constants seeded per-tenant; no shared UUID constants (UUIDs differ per tenant).
- CLABE unique per tenant for non-null values — implemented via COALESCE partial index (WHERE deleted_at IS NULL AND clabe IS NOT NULL).
- ExpenseCategory self-referential hierarchy (parent_id) uses a COALESCE expression unique index `(tenant_id, COALESCE(parent_id::text,''), name) WHERE deleted_at IS NULL`.
- New `Tesorería` system in the dynamic menu (system_id: c3d4e5f6-a7b8-9012-cdef-123456789001).
