# Proposal: Module 5-rest — Treasury Expenses

## Intent
Add the expenses (Egresos) feature to the existing Treasury module. Expenses track money leaving the complex's bank accounts — payroll, services, maintenance, etc. They decrement bank_account.current_balance on registration and restore it on void. No physical deletion is allowed.

## Scope
- New models in `Modules/Treasury/`: ExpenseStatus, BeneficiaryType, Expense
- 3 new migrations: expense_status, beneficiary_type, expense tables
- ExpenseService with register() + void() + duplicate detection
- Orion CRUD for all 3 resources + custom POST /expense/{id}/void
- GET /treasury/public-summary (resident-facing aggregate view, §9.5)
- payment_method REUSED from Collections (no duplication)
- community_project_id stored as nullable UUID with NO FK (Projects module is future)

## Approach
Follows Treasury/Collections money pattern: bigInteger + MoneyIntegerCast. Balance updates use lockForUpdate + read-modify-write with raw integers (same as BankAccountService + PaymentService). Duplicate detection is a soft warning (returns {duplicate: true, expense: null}) with force=true override.
