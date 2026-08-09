# Estado de Resultados Dashboard Specification

## Purpose

Specifies the "Estado de Resultados" accounting dashboard (`/tableros/resultados`). Shows income vs. expense summary over a 12-month window with net margin.

## Requirements

### Requirement: Estado de Resultados dashboard renders correct KPIs and chart

The dashboard at `/tableros/resultados` MUST display:

| KPI | Description |
|---|---|
| Ingresos (12m) | Total income (minor units) for the last 12 months |
| Egresos (12m) | Total expenses (minor units) for the last 12 months |
| Neto | `income_minor - expense_minor` totals |
| Margen % | `(net / income) × 100` as integer, displayed as percentage |

The page MUST render a `LineBarComboChart` showing bars for income and expenses per month and an overlay line for net, using the data from `GET /treasury/income-expense-series?months=12`. The permission entity is `report_pnl`. The sidebar icon is `TrendingUp`. The module name in the menu MUST be "Estado de Resultados" (Spanish).

#### Scenario: Correct KPIs displayed from endpoint data

- GIVEN the endpoint returns `totals.income_minor = 600000`, `expense_minor = 400000`, `net_minor = 200000`
- WHEN the dashboard renders
- THEN "Ingresos" shows `$6,000.00`, "Egresos" shows `$4,000.00`, "Neto" shows `$2,000.00`, "Margen" shows `33%`
- AND all values are formatted via `formatMinorUnits` — never displayed as raw integers

#### Scenario: Chart contains 12 data points

- GIVEN the endpoint returns 12 monthly series entries
- WHEN the `LineBarComboChart` renders
- THEN the chart contains 12 bar groups and the net line has 12 data points

#### Scenario: Zero-income month shows margen 0%

- GIVEN a month where `income_minor = 0` and `expense_minor = 0`
- WHEN the KPIs are computed
- THEN "Margen" displays `0%` without a division-by-zero error

#### Scenario: Route is permission-gated

- GIVEN a user without `report_pnl` permission navigates to `/tableros/resultados`
- WHEN the route resolves
- THEN `AccessDeniedPage` is shown

