# Gastos Dashboard Specification

## Purpose

Specifies the "Gastos" dashboard (`/tableros/gastos`). Shows expense breakdown by category, top providers, and monthly expense trend.

## Requirements

### Requirement: Gastos dashboard renders category breakdown, provider ranking, and trend

The dashboard at `/tableros/gastos` MUST display:

| KPI | Description |
|---|---|
| Gasto del mes | `monthly` entry for the current month `amount_minor` |
| Gasto del año | Sum of all `monthly` entries' `amount_minor` for the current year |
| # Proveedores con gasto | Count of distinct entries in `by_provider` |
| % Recurrente | `recurring_minor / total_minor × 100` as integer % |

The page MUST render:
- A `DonutChart` showing `by_category` (label + pct), with Spanish category labels from the endpoint.
- A `GroupedBarChart` showing `by_provider` top entries (provider name + amount).
- An `AreaTrendChart` showing `monthly` expense evolution over the 12-month window.

Permission entity: `report_expenses`. Sidebar icon: `Receipt`. Menu label: "Gastos".

#### Scenario: DonutChart uses Spanish category labels

- GIVEN `by_category` includes `{ "label": "Mantenimiento", "pct": 45 }`
- WHEN the `DonutChart` renders
- THEN the legend shows "Mantenimiento" and the tooltip shows "45%"

#### Scenario: Recurring percentage computed correctly

- GIVEN `total_minor = 200000` and `recurring_minor = 80000`
- WHEN the KPI renders
- THEN "% Recurrente" shows `40%`

#### Scenario: Gasto del mes uses current calendar month

- GIVEN the endpoint returns a June 2026 entry with `amount_minor = 30000`
- WHEN the dashboard renders in June 2026
- THEN "Gasto del mes" shows `$300.00`

#### Scenario: Route is permission-gated

- GIVEN a user without `report_expenses` navigates to `/tableros/gastos`
- WHEN the route resolves
- THEN `AccessDeniedPage` is shown

