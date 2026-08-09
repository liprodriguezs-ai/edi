# Module 11 — Reports — Spec

## Requirements

### R1 — Scheduled report entity
`scheduled_report` has: `report_key` (string, one of: delinquency/income_statement/
account_balances/audit_log/collection_progress), `config` (JSON, arbitrary report parameters),
`frequency` (string: daily/weekly/monthly), `format` (string: pdf/excel), `recipients` (JSON
array of email addresses), `next_run_at` (datetime, nullable), `last_run_at` (datetime,
nullable), `is_active` (boolean).

`computeNextRun()` method updates `next_run_at`:
- daily → `now()->addDay()->startOfDay()->setHour(8)`
- weekly → `now()->next('Monday')->startOfDay()->setHour(8)`
- monthly → `now()->addMonthNoOverflow()->startOfMonth()->setHour(8)`

### R2 — Delinquency report
`ReportService.delinquency()` returns:
- `units`: collection of `{unit_id, unit_number, overdue_charges, total_overdue_amount,
  oldest_due_date}` — one entry per unit that has at least one overdue, non-cancelled charge.
- `total_overdue_amount`: sum of `(expected_amount - paid_amount)` across all overdue
  non-cancelled charges (minor units).
- `unit_count`: number of distinct units.

Excludes: charges where `is_cancelled = true`.

### R3 — Income statement
`ReportService.incomeStatement(periodStart, periodEnd)` returns:
- `period_start`, `period_end`: the period boundaries (date strings).
- `total_income`: sum of `payment.total_amount` for confirmed payments with
  `paid_at BETWEEN start AND end` (minor units).
- `total_expenses`: sum of `expense.amount` with `paid_at BETWEEN start AND end` (minor units).
- `net`: `total_income - total_expenses`.
- `payments`, `expenses`: the underlying collections.

Includes only `confirmed` status payments (excludes pending_validation and cancelled).

### R4 — Account balances
`ReportService.accountBalances()` returns active (`is_active = true`) bank accounts ordered by
`is_primary DESC` with `type` and `purpose` relations eager-loaded.

### R5 — Audit log
`ReportService.auditLog(filters, perPage=50)` paginates the `audits` table (via `DB::table`).
Supported filters: `user_id`, `auditable_type`, `from` (date), `to` (date).
Returns `LengthAwarePaginator`.

### R6 — Collection progress
`ReportService.collectionProgress(batchId)` returns:
- `batch_id`, `total_charges`, `paid_count`, `overdue_count`, `pending_count` (total - paid -
  overdue), `total_expected`, `total_collected` (minor units), `progress_pct` (0–100 float,
  2 decimal places).
- Excludes cancelled charges (`is_cancelled = true`).
- `paid_count`: charges with status `completed`.
- `overdue_count`: charges with status `overdue`.

## Scenarios (acceptance — from tests in ReportsModuleTest.php)

- **A. Delinquency lists overdue**: one overdue charge ($700) for one unit → unit_count=1,
  total_overdue_amount=70,000.
- **B. Delinquency excludes cancelled**: cancelled overdue charge → unit_count=0.
- **C. Income statement nets correctly**: $700 + $500 income, $300 expense in March 2026 →
  total_income=120,000, total_expenses=30,000, net=90,000.
- **D. Income statement excludes out-of-period**: payment in February not counted in March.
- **E. Account balances returns active only**: 1 active + 1 inactive account → returns 1
  (the active one).
- **F. Collection progress correct stats**: 2 completed + 1 overdue charges → paid_count=2,
  overdue_count=1, pending_count=0, progress_pct≈66.67%.
- **G. ScheduledReport CRUD + computeNextRun**: monthly report → `next_run_at` computed
  in the future; daily/weekly/monthly all produce non-null future timestamps.
- **H. Tenant isolation**: scheduled_report from tenant A not visible in tenant B.
