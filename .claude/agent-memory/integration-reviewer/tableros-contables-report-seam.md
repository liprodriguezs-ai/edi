---
name: tableros-contables-report-seam
description: Review findings for the 5 report dashboard contracts in tableros-contables (2026-06-17). Violations by endpoint.
metadata:
  type: project
---

# tableros-contables — Report Seam Findings (2026-06-17)

## Pattern: derived KPI fields

The FE schemas for report dashboards define KPI fields that the backend service
does NOT return. At runtime those KPI tiles show `undefined` (rendered as nothing
or "0" depending on the ?? default). This is the recurring critical violation
pattern for this feature area.

## Endpoint violations

### GET /treasury/income-expense-series → resultados/

CRITICAL: `IResultadosTotals.margin_pct` — FE schema:32, Dashboard:19.
Backend `incomeExpenseSeries()` returns only `income_minor`, `expense_minor`, `net_minor`.
No `margin_pct`. KPI tile reads `totals?.margin_pct ?? 0` → always 0.

### GET /collections/aging → cobranza/

CRITICAL (×5):
1. Top-level shape mismatch: Backend returns `{ aging, total_outstanding_minor, units_with_debt, collection_rate_pct, by_status, top_debtors }`. FE expects `{ totals: {...}, buckets: {...}, by_status, top_debtors }`.
   - FE reads `data?.totals` and `data?.buckets` → both `undefined`.
   - Backend root-level `aging` key → FE expects `buckets` key.
2. `IAgingTotals.avg_days_overdue` — not in backend.
3. `ITopDebtor.charge_count` — not in backend (backend has `oldest_due_date` instead).
4. `ITopDebtor` missing `unit_id` — frontend schema doesn't declare it but `buildDebtorRows` uses it via `d.unit_label`/`d.outstanding_minor`/`d.charge_count`. `charge_count` will always be `undefined`.

### GET /treasury/cashflow-series → tesoreria/

CRITICAL (×4 — `ITesoreriaPayload.totals` does not exist in backend):
Backend returns `{ accounts, total_balance_minor, series }` — NO `totals` key.
FE expects `{ accounts, series, totals: { total_balance_minor, account_count, current_month_inflow_minor, current_month_outflow_minor } }`.
- `data?.totals` → `undefined`. All 4 KPI tiles: 0/undefined.
- `total_balance_minor` is at the root, not nested in `totals`.

Additionally `ICashflowBucket` declares `year` and `month` fields, but backend
cashflow series buckets only include `label`, `inflow_minor`, `outflow_minor`,
`running_balance_minor`. No `year`/`month`. (Low severity — fields not consumed
in the component, only declared in the schema.)

### GET /treasury/expenses-breakdown → gastos/

CRITICAL (×3):
1. `by_provider[].label` — FE schema, but backend returns `by_provider[].name`.
   Component reads `p.label` → `undefined`, all provider bars are blank.
2. `by_category[].expense_category_id` — FE schema. Backend returns `by_category[].category_id`.
   Component reads `c.expense_category_id` for the donut `key` → `undefined`.
3. `IGastoTotals.recurring_pct` and `IGastoTotals.provider_count` — not in backend.
   KPI tiles always show 0/undefined.

Additionally `IGastoMonthlyBucket` declares `year` and `month` — backend `monthly`
entries only have `label` and `amount_minor`. (Schema drift, not consumed in component.)

### GET /projects/funding-summary → proyectos/

ALIGNED. No critical violations. All fields in `IProyectoRow`, `IProyectosTotals`,
`IProyectoByStatus` exactly match the backend `ProjectsReportService.fundingSummary()`
response. Route, method, permission all correct.

## What is aligned (all 5 endpoints)
- URL paths and HTTP methods
- Middleware chain: auth:sanctum + rejectRefreshToken + authActive + initializeTenancy
- Permission ability strings (read.report_* seeded and roled correctly)
- FE permission check uses OR of read+report abilities
- No pagination assumed (all are flat objects)
- _minor suffix used consistently on all monetary fields that DO exist
- IDs are string (UUID)
