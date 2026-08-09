# Cobranza / Morosidad Dashboard Specification

## Purpose

Specifies the "Cobranza" accounting dashboard (`/tableros/cobranza`). Shows delinquency aging, charge-status distribution, collection rate, and top debtor units.

## Requirements

### Requirement: Cobranza dashboard renders aging, status distribution, and top debtors

The dashboard at `/tableros/cobranza` MUST display:

| KPI | Description |
|---|---|
| Cartera vencida | `total_outstanding_minor` from endpoint |
| Tasa de cobro | `collection_rate_pct` (0–100 integer, shown as %) |
| Unidades morosas | `units_with_debt` count |
| Antigüedad promedio | Average days overdue, computed client-side or returned by endpoint |

The page MUST render:
- A `GroupedBarChart` showing the 5 aging buckets (corriente / 1–30 / 31–60 / 61–90 / 90+) with their `_minor` values.
- A `DonutChart` showing `by_status` distribution (count and amount per `charge_status`).
- A `ResponsiveDataTable` listing `top_debtors` (unit label, outstanding amount, oldest due date) — maximum 10 rows, sorted by outstanding amount descending.

Permission entity: `report_collections`. Sidebar icon: `AlertTriangle`. Menu label: "Cobranza / Morosidad".

#### Scenario: Aging buckets displayed correctly

- GIVEN the endpoint returns `aging.d31_60_minor = 75000` and other buckets zero
- WHEN the `GroupedBarChart` renders
- THEN only the "31–60 días" bar is non-zero and shows `$750.00`

#### Scenario: Top debtors table shows at most 10 rows

- GIVEN `top_debtors` contains 10 entries from the endpoint
- WHEN the `ResponsiveDataTable` renders
- THEN exactly 10 rows are visible with unit label, amount, and due date in Spanish column headers

#### Scenario: DonutChart shows status labels in Spanish

- GIVEN `by_status` includes `{ "status_key": "overdue", "label": "Vencido", ... }`
- WHEN the `DonutChart` renders
- THEN the legend and tooltip use "Vencido" — not the raw key "overdue"

#### Scenario: Route is permission-gated

- GIVEN a user without `report_collections` navigates to `/tableros/cobranza`
- WHEN the route resolves
- THEN `AccessDeniedPage` is shown

