# Module 3 — Board & Staff: Proposal

## Intent
Implement Mesa Directiva (board management with Spatie role grant/revoke) and Personal Operativo (employee lifecycle with history preservation) for the Kivo7 multi-tenant residential-complex SaaS backend.

## Scope
- New `Board` Nwidart module: 4 tables (board_role, board_period_status, board_period, board_member)
- Reshaping `Collaborator` module: 3 new catalog tables (employee_position, pay_frequency, employee_shift) + employee-domain columns added to `collaborator` table
- BoardService: transactional period start/close with Spatie role assignment/revocation
- EmployeeService: termination (history-preserving) and reactivation (new record) 
- 8 new module menu registrations, 42 new permissions (6 existing reused for collaborator)
- 21 integration tests covering role grant/revoke, one-period invariant, money round-trip

## Key Approach
- Board module is new; Collaborator module is **reshaped** (not renamed) — lowest churn
- All enumerations are tenant-scoped catalog tables (no hardcoded enums)
- Spatie role grant/revoke uses `App\Models\Role` (project's custom Role model with `primaryKey='uuid'`)
- `base_salary` is bigInteger minor units with `MoneyIntegerCast:MXN`
