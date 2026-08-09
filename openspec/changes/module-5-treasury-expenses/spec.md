# Spec: Module 5-rest — Treasury Expenses

## Tables

### expense_status (tenant-scoped)
- id, tenant_id, key, name, color, order, is_active
- Keys: paid → "Pagado", voided → "Anulado"

### beneficiary_type (tenant-scoped)
- id, tenant_id, key, name, order, is_active
- Keys: employee → "Empleado", provider → "Proveedor", other → "Otro"

### expense (tenant-scoped)
- id, tenant_id, concept (string), description (text null), paid_at (date), amount (bigInteger)
- expense_category_id (FK → expense_category), beneficiary_type_id (FK → beneficiary_type)
- employee_id (FK → collaborator null), provider_id (FK → provider null)
- beneficiary_text (string null — for 'other')
- year (smallint), month (smallint null), fortnight (smallint null: 1|2)
- payment_method_id (FK → payment_method [Collections]), bank_account_id (FK → bank_account)
- reference (string null), community_project_id (uuid null, NO FK constraint, index only)
- authorized_by_user_id (uuid null, central user, withoutGlobalScope)
- expense_status_id (FK → expense_status), void_reason (text null), notes (text null)

## API Endpoints
- Orion CRUD: expense_status, beneficiary_type, expense (with soft deletes)
- Custom: POST /expense/{id}/void → validates reason, calls ExpenseService.void()
- GET /treasury/public-summary?year=&month= → aggregates only (no individual rows)

## Business Rules (§9.6)
1. Expenses never deleted — only voided (status=voided, void_reason required)
2. Each expense must have bank_account_id (source account)
3. Balance: initial + confirmed_payments − expenses
4. Duplicate detection: same provider_id + year + month + amount within 10% tolerance + created_at within 7 days → returns {duplicate: true}; override with force=true
5. Public summary: aggregate totals only (no individual rows — privacy)
6. community_project_id: nullable UUID stored without FK (Projects module adds FK later)

## payment_method: REUSED from Collections
`Modules\Collections\Models\PaymentMethod` — table `payment_method` already seeded per tenant.
