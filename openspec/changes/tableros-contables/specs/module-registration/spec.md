# Module Registration Specification

## Purpose

Defines the requirements for registering all 5 accounting dashboards as sidebar modules so they appear in the menu and are protected by per-dashboard permissions (CLAUDE.md rule 3).

## Requirements

### Requirement: All 5 dashboards registered as sidebar modules

For each of the 5 dashboards, the system MUST complete all mandatory registration steps:

| Dashboard | Module name | Permission entity | Route | Icon |
|---|---|---|---|---|
| Estado de resultados | `ReportPnl` | `report_pnl` | `/tableros/resultados` | `TrendingUp` |
| Cobranza / Morosidad | `ReportCollections` | `report_collections` | `/tableros/cobranza` | `AlertTriangle` |
| Tesorería / Flujo de caja | `ReportTreasury` | `report_treasury` | `/tableros/tesoreria` | `Wallet` |
| Gastos | `ReportExpenses` | `report_expenses` | `/tableros/gastos` | `Receipt` |
| Proyectos comunitarios | `ReportProjects` | `report_projects` | `/tableros/proyectos` | `Target` |

Each MUST appear in: `modules.csv`, `permissions.csv`, `module_system.csv`, `SystemTableSeeder` (entity, route, icon), and `RolePermissionTableSeeder`. The `ResetPermissionTableSeeder` MUST be run after seeder changes.

Each icon MUST be a valid key present in `frontend/src/shared/utils/iconMap.ts`.

#### Scenario: Dashboard appears in sidebar for authorized user

- GIVEN a user with role `company.admin` is authenticated on a tenant
- WHEN `GET /api/menu` is called
- THEN the response includes all 5 report modules grouped under "Tableros"
- AND each entry carries the correct route, label (in Spanish), and icon name

#### Scenario: Dashboard absent for unauthorized user

- GIVEN a user with role `collaborator` (no report permissions)
- WHEN `GET /api/menu` is called
- THEN none of the 5 report entries appear in the menu

---

### Requirement: Permission entities are read-only report permissions

Each dashboard MUST have a permission entity (`report_pnl`, `report_collections`, `report_treasury`, `report_expenses`, `report_projects`). These entities MUST be accessible to roles `account.owner` and `company.admin` via the existing `whereNotIn` mechanism (no explicit grant needed). Roles `Treasurer`, `President`, and `Oversight Committee` MUST receive explicit grants in `RolePermissionTableSeeder` for the relevant entities.

The permission seed MUST NOT regress any existing role/permission assignment.

#### Scenario: Treasurer can access collections and treasury dashboards

- GIVEN a user with role `Treasurer` on a tenant
- WHEN the authorization check runs for `report_collections` or `report_treasury`
- THEN access is granted

#### Scenario: Resident cannot access any dashboard

- GIVEN a user with role `Resident`
- WHEN authorization is checked for any report permission entity
- THEN access is denied and the route returns HTTP 403

---

### Requirement: PermissionRoute guard in App.tsx

Each dashboard MUST be wrapped in `<PermissionRoute permissionEntity="{entity}" />` in `frontend/src/App.tsx`. Navigating to the route without the permission MUST render the `AccessDeniedPage`.

#### Scenario: Unauthorized direct navigation

- GIVEN a user without `report_pnl` permission navigates directly to `/tableros/resultados`
- WHEN the route renders
- THEN `AccessDeniedPage` is displayed — not the dashboard

