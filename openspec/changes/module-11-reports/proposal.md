# Module 11 — Reports (Reportes y Exportación)

## Intent

Provide a unified reporting surface for board members and administrators, aggregating data
from existing tenant tables without introducing new domain writes. The module exposes five
read-only aggregation endpoints and one tenant-scoped `scheduled_report` entity that records
scheduled delivery configurations (deferred delivery engine — structure in place, execution
not yet automated).

## Scope

New tenant-scoped module `Reports` (`backend/Modules/Reports`):

- **Entities**: `scheduled_report` (tenant-scoped configuration for scheduled report delivery).
- **Services**: `ReportService` — read-only aggregations reading Collections, Treasury,
  and Audit tables:
  - `delinquency()`: units with overdue charges, total overdue amount, oldest due date.
  - `incomeStatement(start, end)`: confirmed payments minus expenses for a date range.
  - `accountBalances()`: active bank accounts with current balance.
  - `auditLog(filters, perPage)`: paginated read from the `audits` table.
  - `collectionProgress(batchId)`: per-batch charge stats (paid/overdue counts, progress %).
- **Endpoints**: Orion CRUD for `scheduled_report`; GET endpoints for each aggregation;
  `POST reports/{key}/generate` for on-demand generation.
- Menu/permission registration, integration tests (8 scenarios).

No new domain tables beyond `scheduled_report`. All report data is read from existing tables.

## Approach

- All `ReportService` methods are read-only — no writes to any domain table.
- Money amounts are returned as raw minor units (integers) consistent with the Collections
  convention; the frontend is responsible for formatting.
- `incomeStatement` includes only `confirmed` payments (not `pending_validation` or
  `cancelled`).
- `delinquency` excludes cancelled charges (`is_cancelled = true`).
- `auditLog` queries the `audits` table directly via `DB::table` — no Eloquent model — and
  applies filters (user_id, auditable_type, from/to dates).
- `ScheduledReport.computeNextRun()` computes the next scheduled run datetime in-model based on
  `frequency` (daily/weekly/monthly), using Carbon. Actual dispatch is deferred.

## Out of Scope (v1)

- PDF generation of report output (Dompdf integration deferred).
- Excel/CSV export (Maatwebsite Excel deferred).
- Automated scheduled execution of reports (queue job for `scheduled_report` deferred).
- Resident-own-unit gate (residents viewing only their own unit's data) — deferred to
  frontend permission layer; backend returns full tenant-scoped data.
- Push/email delivery of scheduled reports.
