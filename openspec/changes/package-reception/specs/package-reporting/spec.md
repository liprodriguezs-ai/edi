# Package Reporting Specification

## Purpose

Defines the contract for the pending-packages aging report (with Excel export) and the
"paquetes pendientes de retiro" dashboard widget. Both are read-only, permission-gated, and
fully tenant-scoped.

---

## Requirements

### Requirement: Aging report — `GET /api/package/report/aging`

The endpoint MUST be implemented in `PackageReportController` and MUST require the
`read.report_package` permission.

The report covers all packages with status `ready` (pending pickup). It groups them into three
age buckets based on `(today - received_at)` in whole days, computed using the tenant's
`timezone`:

| Bucket key | Range | Label |
|---|---|---|
| `d0_2` | 0–2 days | Recientes (0-2 días) |
| `d3_7` | 3–7 days | Por vencer (3-7 días) |
| `d7_plus` | 8+ days | Vencidos (7+ días) |

Response shape:
```json
{
  "generated_at": "2026-06-29T10:00:00Z",
  "total_pending": 12,
  "buckets": {
    "d0_2":   { "count": 5, "packages": [ { "id": "...", "unit_full_identifier": "...", "carrier": "DHL", "received_at": "...", "storage_location": "...", "days_pending": 1 } ] },
    "d3_7":   { "count": 4, "packages": [ … ] },
    "d7_plus": { "count": 3, "packages": [ … ] }
  }
}
```

Each package entry in `packages` MUST include: `id`, `unit_full_identifier`
(from the `Unit` accessor), `carrier` (name from `package_carrier` or `carrier_other` or null),
`received_at`, `storage_location`, `days_pending` (integer). No monetary fields; no `pickup_code`.

All aggregation MUST be performed via Eloquent on scoped models — not raw `DB::` queries.
Business logic MUST live in `PackageReportService`, not in the controller.

#### Scenario: Packages appear in the correct bucket

- GIVEN a tenant has:
  - package P1 with `received_at = 1 day ago` (status `ready`)
  - package P2 with `received_at = 5 days ago` (status `ready`)
  - package P3 with `received_at = 10 days ago` (status `ready`)
  - package P4 with `received_at = 2 days ago` (status `picked_up`)
- WHEN `GET /api/package/report/aging` is called
- THEN `d0_2.count = 1` (P1 only)
- AND `d3_7.count = 1` (P2 only)
- AND `d7_plus.count = 1` (P3 only)
- AND P4 does NOT appear in any bucket (not `ready`)
- AND `total_pending = 3`

#### Scenario: Report is tenant-scoped

- GIVEN Tenant A has 3 pending packages, Tenant B has 5 pending packages
- WHEN an administrator of Tenant A calls the report endpoint
- THEN `total_pending = 3` and only Tenant A's packages appear in buckets

#### Scenario: User without report permission is rejected

- GIVEN an authenticated user with role `security` (no `read.report_package` permission)
- WHEN `GET /api/package/report/aging` is called
- THEN HTTP 403 is returned

---

### Requirement: Excel export — `GET /api/package/report/aging/export`

The endpoint MUST export the same aging data as the JSON report, but rendered as an `.xlsx` file
using `ReportExport` (the shared export framework used by other modules). It MUST require the
same `read.report_package` permission.

The Excel file MUST contain one sheet with the following columns (in Spanish):
Unidad, Transportista, Número de seguimiento, Descripción, Ubicación de almacenamiento,
Recibido el, Días pendiente, Estado, Ubicación.

Rows MUST be sorted by `days_pending` descending (oldest first).

The HTTP response MUST set `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`
and `Content-Disposition: attachment; filename="paquetes-pendientes-{YYYY-MM-DD}.xlsx"`.

#### Scenario: Export file downloads with correct content type

- GIVEN an administrator with `read.report_package` permission
- WHEN `GET /api/package/report/aging/export` is called
- THEN HTTP 200 is returned
- AND `Content-Type` is `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`
- AND `Content-Disposition` contains `paquetes-pendientes-`

#### Scenario: Export contains only pending packages

- GIVEN a tenant with 2 `ready` packages and 3 `picked_up` packages
- WHEN the export is downloaded and parsed
- THEN the sheet contains exactly 2 data rows (excluding the header row)

---

### Requirement: Dashboard widget — "Paquetes pendientes de retiro"

A frontend widget MUST display the count of pending packages (status `ready`) for the current
tenant. It MUST fetch data from a lightweight endpoint:

`GET /api/package/report/pending-count`

Response:
```json
{
  "total": 12,
  "d7_plus": 3
}
```

`d7_plus` is the count of packages pending for 8+ days (critical bucket). The widget MUST
visually distinguish the critical count (e.g., a badge or colored indicator).

The widget MUST be integrated into the main dashboard home page
(`frontend/src/modules/dashboard/` or equivalent). It MUST be visible to users with
`read.package` permission. It MUST use the standard `useQuery` + `resourceApi` pattern; no
custom fetch logic.

`GET /api/package/report/pending-count` MUST be a lightweight aggregation (two COUNT queries,
no JOIN with packages list), gated by `read.package` permission.

#### Scenario: Widget shows correct totals

- GIVEN a tenant has 5 `ready` packages (2 are 9 days old, 3 are 1 day old)
- WHEN the dashboard widget loads
- THEN `total = 5` and `d7_plus = 2` are displayed

#### Scenario: Widget shows zero when no pending packages

- GIVEN a tenant has no packages with status `ready`
- WHEN the dashboard widget loads
- THEN `total = 0` is displayed with no error

#### Scenario: Widget not visible without permission

- GIVEN an authenticated user with no `read.package` permission
- WHEN the dashboard loads
- THEN the package widget is not rendered

---

### Requirement: `reportColumns()` wired in `PackageReportController`

`PackageReportController` MUST implement `reportColumns()` returning the column definitions
for the aging report. This wires the controller into the shared `ReportController` +
`ReportExport` export framework used by other modules (Reports module, Module 11).

#### Scenario: reportColumns returns correct column count

- GIVEN `PackageReportController::reportColumns()` is called
- WHEN the result is inspected
- THEN it returns at least 8 column definitions matching the Excel export columns defined above
