# Backend Aggregation Endpoints Specification

## Purpose

Defines the contract for the 5 new read-only aggregation endpoints. These endpoints provide pre-computed financial data to the dashboards. All amounts MUST be in bigInteger minor units. All endpoints MUST be tenant-scoped and permission-gated.

## Requirements

### Requirement: Tenant isolation is absolute

Every aggregation endpoint MUST be registered under `['auth:sanctum', 'initializeTenancy']` middleware. Aggregation queries MUST run exclusively through Eloquent models that carry the `BelongsToTenant` trait (tenant scope applied automatically). The `DB::` facade MUST NOT be used for aggregation queries. Any `selectRaw`/`groupBy` clause MUST be expressed on a scoped Eloquent builder so the generated SQL retains `WHERE tenant_id = ?`.

#### Scenario: Tenant A cannot see Tenant B data

- GIVEN Tenant A and Tenant B each have charges and payments
- WHEN a user of Tenant A calls any aggregation endpoint
- THEN the response contains only Tenant A's data
- AND the executed SQL contains a `tenant_id` filter equal to Tenant A's ID

#### Scenario: Unauthenticated request rejected

- GIVEN no `Authorization` header is provided
- WHEN any aggregation endpoint is called
- THEN HTTP 401 is returned

---

### Requirement: Money in minor units end-to-end

All monetary fields in every aggregation endpoint response MUST be `integer` values in centavos (minor units). No `float`, no `decimal`, no `string`-formatted currency. Field names MUST end in `_minor` to make the contract explicit.

#### Scenario: Response carries integer minor units

- GIVEN charges totaling $1,500.00 MXN exist for a tenant
- WHEN the relevant aggregation endpoint is called
- THEN the response field contains `150000` as an integer, not `1500.0` or `"$1,500.00"`

---

### Requirement: Aggregation logic lives in Service classes

Business/aggregation logic MUST live in a dedicated Service class per owner module (e.g., `TreasuryReportService`, `CollectionsReportService`, `ProjectsReportService`). The controller MUST only orchestrate: call the service, return `response()->json(...)`. No aggregation SQL in controllers.

---

### Requirement: Estado de resultados endpoint — `GET /treasury/income-expense-series`

The endpoint MUST accept an optional `months` query parameter (default: 12, max: 24). It MUST return:

```json
{
  "series": [
    { "year": 2026, "month": 6, "label": "Jun 2026",
      "income_minor": 0, "expense_minor": 0, "net_minor": 0 }
  ],
  "totals": { "income_minor": 0, "expense_minor": 0, "net_minor": 0 }
}
```

Month buckets MUST use the tenant's `timezone` field for boundary computation. Income = confirmed payments credited to a bank account. Expenses = recorded expenses linked to the tenant. `net_minor = income_minor - expense_minor`.

#### Scenario: Returns 12-month series by default

- GIVEN a tenant with payments and expenses spread over 14 months
- WHEN `GET /treasury/income-expense-series` is called without `months`
- THEN the `series` array contains exactly 12 entries ordered oldest-first

#### Scenario: net_minor is computed correctly

- GIVEN a month with `income_minor = 500000` and `expense_minor = 200000`
- WHEN the endpoint is called
- THEN `net_minor` for that month is `300000`

---

### Requirement: Cobranza / Morosidad endpoint — `GET /collections/aging`

The endpoint MUST return:

```json
{
  "aging": {
    "current_minor": 0,
    "d1_30_minor": 0,
    "d31_60_minor": 0,
    "d61_90_minor": 0,
    "d90_plus_minor": 0
  },
  "total_outstanding_minor": 0,
  "units_with_debt": 0,
  "collection_rate_pct": 0,
  "by_status": [
    { "status_key": "overdue", "label": "Vencido", "count": 0, "amount_minor": 0 }
  ],
  "top_debtors": [
    { "unit_id": "uuid", "unit_label": "Torre A-101",
      "outstanding_minor": 0, "oldest_due_date": "2026-01-15" }
  ]
}
```

Aging buckets MUST be computed from `charge.due_date` relative to today. `collection_rate_pct` = (charges paid this month / charges issued this month) × 100, as an integer 0–100. `top_debtors` MUST be limited to at most 10 entries, ordered by `outstanding_minor` descending. The endpoint MUST reuse or align with the logic from the existing `PaymentController::delinquency` method.

#### Scenario: Overdue charge appears in correct aging bucket

- GIVEN a charge with `due_date` 45 days ago and `charge_status` = overdue
- WHEN `GET /collections/aging` is called
- THEN `d31_60_minor` includes that charge's outstanding amount

#### Scenario: top_debtors capped at 10

- GIVEN a tenant with 15 units that have outstanding debt
- WHEN `GET /collections/aging` is called
- THEN `top_debtors` contains exactly 10 entries

---

### Requirement: Tesorería / Flujo de caja endpoint — `GET /treasury/cashflow-series`

The endpoint MUST accept an optional `months` query parameter (default: 12, max: 24). It MUST return:

```json
{
  "accounts": [{ "id": "uuid", "name": "Caja", "current_balance_minor": 0 }],
  "total_balance_minor": 0,
  "series": [
    { "label": "Jun 2026", "inflow_minor": 0, "outflow_minor": 0,
      "running_balance_minor": 0 }
  ]
}
```

`inflow_minor` = confirmed payments received in that month. `outflow_minor` = expenses recorded in that month. `running_balance_minor` = cumulative balance up to the end of that month. Month bucketing uses the tenant's `timezone`.

#### Scenario: running_balance_minor is cumulative

- GIVEN month 1 has `inflow=100000, outflow=60000` and month 2 has `inflow=80000, outflow=40000`
- WHEN the endpoint is called
- THEN month 1 `running_balance_minor = 40000` and month 2 `running_balance_minor = 80000`

---

### Requirement: Gastos endpoint — `GET /treasury/expenses-breakdown`

The endpoint MUST accept an optional `months` query parameter (default: 12, max: 24). It MUST return:

```json
{
  "by_category": [
    { "category_id": "uuid", "label": "Mantenimiento", "amount_minor": 0, "pct": 0 }
  ],
  "by_provider": [
    { "provider_id": "uuid", "name": "X", "amount_minor": 0 }
  ],
  "monthly": [{ "label": "Jun 2026", "amount_minor": 0 }],
  "total_minor": 0,
  "recurring_minor": 0,
  "one_off_minor": 0
}
```

`pct` in `by_category` MUST be an integer 0–100. `by_provider` MUST include only expenses linked to a provider (non-null `provider_id`). `recurring_minor` = sum of expenses where the linked provider has `is_recurring = true`.

#### Scenario: by_category pct sums to 100

- GIVEN a tenant with expenses in 3 categories
- WHEN `GET /treasury/expenses-breakdown` is called
- THEN the sum of all `pct` values in `by_category` equals 100 (allowing rounding to ±1)

---

### Requirement: Proyectos comunitarios endpoint — `GET /projects/funding-summary`

The endpoint MUST return:

```json
{
  "projects": [
    { "id": "uuid", "title": "Barda perimetral",
      "goal_minor": 0, "raised_minor": 0, "pct": 0,
      "status_key": "active", "status_label": "Activo" }
  ],
  "totals": { "active_count": 0, "goal_minor": 0, "raised_minor": 0, "pct": 0 },
  "by_status": [{ "status_key": "active", "label": "Activo", "count": 0 }]
}
```

`pct` per project = `raised_minor / goal_minor × 100` as integer 0–100 (0 when `goal_minor = 0`). `totals.pct` = global raised / global goal × 100. `status_label` MUST be the Spanish display label from the `community_project_status` catalog row.

#### Scenario: pct is zero when goal is zero

- GIVEN a project with `goal_minor = 0`
- WHEN the endpoint is called
- THEN `pct` for that project is `0`, not a division error

#### Scenario: Feature test asserts tenant scope

- GIVEN a feature test seeds projects for two tenants
- WHEN authenticated as tenant 1 and calling the endpoint
- THEN only tenant 1's projects appear

---

### Requirement: Feature tests per endpoint

Each endpoint MUST have at least one Laravel feature test that: seeds representative data for a tenant, calls the endpoint as an authenticated user with the required permission, and asserts that all monetary fields are integers in minor units. Tests MUST also assert that a second tenant's data does not appear in the response.

