# Design: Module 5-rest — Treasury Expenses

## Architecture
Added to existing `Modules/Treasury/` module (not a new module).

## Key Decisions

### payment_method: REUSE from Collections (not duplicated)
Decision: `payment_method` table and model live in Collections. The Expense model references `Modules\Collections\Models\PaymentMethod`. This avoids schema duplication and keeps the catalog consistent for both payments (income) and expenses (outgo). Both use the same `payment_method` per tenant.

### Duplicate detection: soft warning with force override
Decision: register() returns `['duplicate' => bool, 'expense' => ?Expense]`. When duplicate=true and force=false, the caller (controller's performStore override) returns HTTP 409. The client can retry with force=true to confirm and proceed. This avoids losing data while flagging likely duplicates. Tolerance: ±10% of amount within 7 days for same provider+period.

### Balance arithmetic: lockForUpdate + raw int (same pattern as Collections)
Both BankAccountService and PaymentService use `lockForUpdate()->first()`, `getRawOriginal('current_balance')`, `$account->current_balance = $current + $delta; $account->save()`. ExpenseService uses the same pattern. DB::raw('col ± N') is intentionally avoided because MoneyIntegerCast rejects DB::raw expressions.

### community_project_id: nullable UUID, NO FK
Projects module (Module 7) will be built later. The column exists as `uuid null` with an index but NO FK constraint. When Projects module is ready, it will add:
```sql
ALTER TABLE expense ADD CONSTRAINT fk_expense_project 
FOREIGN KEY (community_project_id) REFERENCES community_project(id) ON DELETE SET NULL;
```

### authorized_by_user_id: central user with withoutGlobalScope
The `authorized_by_user_id` references the central User table. The `authorizedBy()` relation uses `->withoutGlobalScope(UserTenantScope::class)` to bypass the tenant filter, consistent with the Collaborator.user() pattern.

### Edit guard: deferred
Role-based time-window enforcement (treasurer: same-day only; president/admin: anytime) is noted but not implemented at service level in v1. Orion standard update applies. A future Policy class will add this constraint.

## Files Created/Modified
- New migrations: expense_status, beneficiary_type, expense
- New models: ExpenseStatus, BeneficiaryType, Expense
- New service: ExpenseService
- New controllers: ExpenseStatusController, BeneficiaryTypeController, ExpenseController, PublicSummaryController
- New requests: ExpenseCreateRequest, ExpenseUpdateRequest
- Modified: Treasury/routes/api.php (added 3 Orion resources + void + public-summary)
- Fixed: Collections/PaymentService (added missing adjustChargePaidAmount private method)
