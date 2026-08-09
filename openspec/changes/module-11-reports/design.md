# Module 11 — Reports — Design Decisions

## Read-only aggregation principle

`ReportService` writes NOTHING. All five methods are purely read-only queries over existing
tenant tables (Collections: charge/payment/charge_status, Treasury: expense/bank_account,
Auditing: audits). This keeps the reporting layer decoupled from domain logic and safe to
call in read replicas or reporting contexts.

## Raw minor unit integers in response

Report endpoints return amounts as raw minor-unit integers (not Money objects). This is
consistent with how Collections stores and returns amounts. The frontend formats them for
display. No Money casting is applied in the ReportService — it uses `getRawOriginal('col')`
or `sum()` via `sum(fn() => (int) $c->getRawOriginal('col'))` for correctness without
triggering MoneyIntegerCast serialization overhead.

## auditLog via DB::table (no Eloquent model)

The `audits` table is owned by the `owen-it/laravel-auditing` package. Rather than creating
an Eloquent model (which would require careful configuration to avoid `BelongsToTenant` scope
pollution), the audit log query uses `DB::table('audits')` directly. This keeps the Reports
module loosely coupled to the auditing package internals.

Note: the `audits` table is not scoped by `tenant_id` in its standard form. The tenant scope
is implicit when the tenancy is initialized — all writes in the audit table include a
`user_id` that belongs to the tenant, so filtering by `user_id` is the practical tenant-scoping
mechanism for this report.

## ScheduledReport as configuration, not execution

`ScheduledReport` stores delivery configuration (frequency, format, recipients, `next_run_at`).
`computeNextRun()` is a pure model method that updates the `next_run_at` field. No queue job
or artisan command actually dispatches the report yet. The design allows plugging in a
`ReportDispatchJob` that reads active `ScheduledReport` records, calls the appropriate
`ReportService` method, and sends the output — without changing the data model.

## collection_progress excludes cancelled charges

Cancelled charges (`is_cancelled = true`) are excluded from progress counts and totals.
A cancelled charge means the billing obligation was voided; including it would inflate
`total_expected` and understate the real collection rate.

## incomeStatement: confirmed payments only

Only `PaymentStatus::KEY_CONFIRMED` payments count as income. Pending-validation and cancelled
payments are excluded. This matches the bank reconciliation expectation: only captured,
confirmed payments represent actual received funds.

## Accepted limitations / deferrals

- PDF/Excel generation: `ScheduledReport.format` stores 'pdf' or 'excel' but the generation
  and delivery pipeline (Dompdf, Maatwebsite Excel, queue job) is not implemented.
- Automated scheduled dispatch: `next_run_at` is computed but no cron/queue worker reads
  `scheduled_report` rows and executes them.
- Resident-own-unit gate: the API returns full tenant-scoped data. Unit-level access
  restriction (resident sees only their unit in delinquency or income view) is deferred to
  the frontend permission layer.
- Cross-tenant aggregation (super-admin aggregate view) is not in scope.
