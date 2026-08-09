---
name: tableros-contables-report-endpoints
description: Verified-safe pattern for the 5 read-only aggregation report endpoints (Treasury/Collections/Projects) — how Eloquent-only aggregation stays tenant-scoped.
metadata:
  type: pattern
---

The `tableros-contables` change (reviewed 2026-06-17, working tree, backend submodule) added 5 read-only aggregation report endpoints. It is a SAFE reference pattern for future financial dashboards.

**Why it is isolation-safe:**
- Every aggregation query SOURCE is a `BelongsToTenant` Eloquent model (`Payment`, `Expense`, `BankAccount`, `Charge`, `CommunityProject`), so `TenantScope` injects `where tenant_id = <current>` automatically. NO `DB::table`, NO `DB::select`, NO bare `DB::` as a source, NO `withoutGlobalScope`, NO `->on('central')`.
- `DB::raw(...)` is used ONLY inside `sum()` / `selectRaw()` ON an already-scoped builder (e.g. `sum(DB::raw('CAST(total_amount AS BIGINT)'))`, `selectRaw('expense_category_id, SUM(...)')` + `groupBy`). That is allowed — the WHERE clause still carries `tenant_id`.
- Correlated subqueries (`withSum('contributions','contributed_amount')`, `whereHas('provider', ...)`) are also safe because the CHILD models (`ProjectContribution`, `Provider`, `Unit`, `ExpenseCategory`) are themselves `BelongsToTenant`, so the subquery applies the child's `TenantScope` too.
- Month-bucketing uses per-bucket `whereBetween('paid_at', [start,end])` on the scoped builder — cannot widen across tenants.

**Auth chain:** all 3 route groups carry `auth:sanctum` + `initializeTenancy`; Treasury+Collections also add `rejectRefreshToken` + `authActive`. Controllers call `$this->authorize('read.report_*', <BelongsToTenant model>::class)` which (via `TraitController::authorize`) throws `AuthorizationException` (403) unless `tokenCan(ability)` or `tokenCan('*')`. Same pattern as `PaymentController::delinquency`.

**Permissions/seeders:** `report_pnl/collections/treasury/expenses/projects` are CENTRAL Spatie permissions (no tenant_id) under business system `b266838b-...` (the tenant system that also holds Cobranza/Seguridad — NOT Core). Granted to account-owner big array + President/Treasurer/Oversight explicitly. No tenant-scoped table introduced, no `*` wildcard granted.

**Tests prove it:** each module has a two-tenant isolation test that seeds tenant B with a distinctive large value (999000/999999) and asserts tenant A's AGGREGATED total excludes it (e.g. `total_outstanding_minor` == 100000, `projects` count == 1), plus 401 (no auth) and 403 (wrong permission) guards.
