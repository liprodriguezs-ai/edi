# Module 3 — Board & Staff: Design

## Architecture Decisions

### Decision: Spatie role grant/revoke via App\Models\Role (not Spatie\Permission\Models\Role)
- **Why**: The project uses `App\Models\Role` with `primaryKey = 'uuid'` (UUID primary key, not auto-increment integer). Using `Spatie\Permission\Models\Role::findByName()` returns an object whose `getKey()` returns null in pivot inserts because the column is `uuid` not `id`.
- **Implementation**: `BoardService` uses `AppRole::where('name', $slug)->where('guard_name', 'api')->first()` to find roles, then `$user->assignRole($appRole)` / `$user->removeRole($appRole)`.
- **Limitation (documented)**: Spatie roles are GLOBAL — not scoped per tenant. A user who is `president` in Fraccionamiento A is also `president` globally. This is an accepted limitation for v1; a tenant-scoped role system would require a different architecture.

### Decision: board_role.spatie_role_slug column (explicit mapping storage)
- **Why**: Maps board_role catalog rows to Spatie slugs without hardcoding in code. Makes the mapping inspectable and overrideable via admin.
- **Alternative**: Map by key in service code. Rejected — less explicit, harder to debug.

### Decision: Collaborator module kept as-is (not renamed to Employee)
- **Why**: Renaming the Nwidart module would require updating composer.json PSR-4, all providers, all route registrations, tests, and potentially seeder references. The domain rename is done at model/service level (EmployeeService, employee_position catalog, etc.) — the module namespace stays `Modules\Collaborator`.

### Decision: 3 new tenant catalog tables for employee enumerations (position, pay_frequency, shift)
- **Why**: Consistent with the no-hardcode rule. Even small fixed sets (day/night/mixed) benefit from tenant customizability in a multi-tenant system.

### Decision: is_active boolean for employee termination (not soft-delete)
- **Why**: Spec §7.5.2 requires history preservation. SoftDeletes would hide the row; `is_active=false` keeps it visible in history queries. The collaborator table already has SoftDeletes from BaseModel for the row itself — this is an additional application-level status flag.

## Table Relationships
```
board_period_status (tenant)
    ↑FK
board_period (tenant) → board_member (tenant) → board_role (tenant)
                                              → user_id (central, no FK)
                                              → unit_id (cross-module bare UUID, no FK)

employee_position (tenant)
pay_frequency (tenant)       → collaborator (tenant) ← FK
employee_shift (tenant)
```
