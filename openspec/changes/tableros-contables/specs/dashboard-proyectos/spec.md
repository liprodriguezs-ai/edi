# Proyectos Comunitarios Dashboard Specification

## Purpose

Specifies the "Proyectos Comunitarios" dashboard (`/tableros/proyectos`). Shows funding progress per community project, global totals, and status distribution.

## Requirements

### Requirement: Proyectos dashboard renders funding progress and status distribution

The dashboard at `/tableros/proyectos` MUST display:

| KPI | Description |
|---|---|
| Proyectos activos | `totals.active_count` |
| Meta total | `totals.goal_minor` (formatted) |
| Recaudado total | `totals.raised_minor` (formatted) |
| % Avance global | `totals.pct` (integer 0–100, shown as %) |

The page MUST render:
- A `GroupedBarChart` comparing `goal_minor` vs `raised_minor` per project (using `title` as the X-axis label).
- A `DonutChart` showing `by_status` distribution (project count per status), using Spanish `label` from the endpoint.

Permission entity: `report_projects`. Sidebar icon: `Target`. Menu label: "Proyectos Comunitarios".

#### Scenario: Progress bar shows correct per-project pct

- GIVEN a project with `goal_minor = 100000` and `raised_minor = 45000`
- WHEN the `GroupedBarChart` renders
- THEN that project's bars show goal `$1,000.00` and raised `$450.00`
- AND the project `pct` is `45`

#### Scenario: Global pct zero when no goal set

- GIVEN `totals.goal_minor = 0` and `totals.raised_minor = 0`
- WHEN the KPI renders
- THEN "% Avance global" shows `0%` without a division-by-zero error

#### Scenario: DonutChart uses Spanish status labels

- GIVEN `by_status` includes `{ "status_key": "active", "label": "Activo", "count": 3 }`
- WHEN the `DonutChart` renders
- THEN the legend entry reads "Activo (3)"

#### Scenario: Route is permission-gated

- GIVEN a user without `report_projects` navigates to `/tableros/proyectos`
- WHEN the route resolves
- THEN `AccessDeniedPage` is shown

