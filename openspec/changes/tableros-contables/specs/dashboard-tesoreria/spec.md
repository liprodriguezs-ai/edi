# Tesorería / Flujo de Caja Dashboard Specification

## Purpose

Specifies the "Tesorería" dashboard (`/tableros/tesoreria`). Shows bank account balances, monthly cash-flow evolution, and inflow vs. outflow comparison.

## Requirements

### Requirement: Tesorería dashboard renders balances, trend, and flow comparison

The dashboard at `/tableros/tesoreria` MUST display:

| KPI | Description |
|---|---|
| Saldo total | `total_balance_minor` across all accounts |
| # Cuentas | Number of entries in `accounts` array |
| Entradas del mes | `inflow_minor` for the current month from `series` |
| Salidas del mes | `outflow_minor` for the current month from `series` |

The page MUST render:
- An `AreaTrendChart` showing `running_balance_minor` over the 12 monthly series entries.
- A `GroupedBarChart` comparing `inflow_minor` vs `outflow_minor` per month.
- A list of bank accounts with individual `current_balance_minor` (formatted) and account name.

Permission entity: `report_treasury`. Sidebar icon: `Wallet`. Menu label: "Tesorería / Flujo de Caja".

#### Scenario: Area chart shows balance evolution

- GIVEN the endpoint returns 12 series entries with increasing `running_balance_minor`
- WHEN the `AreaTrendChart` renders
- THEN the area line ascends across the 12 months

#### Scenario: Current month KPIs use correct series entry

- GIVEN today is June 2026 and the series contains a June 2026 entry
- WHEN the KPIs are computed
- THEN "Entradas del mes" and "Salidas del mes" reflect the June 2026 `inflow_minor` and `outflow_minor`

#### Scenario: Account list shows all accounts

- GIVEN the endpoint returns 3 accounts
- WHEN the account list renders
- THEN all 3 accounts are displayed with their names and formatted balances

#### Scenario: Route is permission-gated

- GIVEN a user without `report_treasury` navigates to `/tableros/tesoreria`
- WHEN the route resolves
- THEN `AccessDeniedPage` is shown

