# Module 11 — Reports — Tasks

## Status: COMPLETE (green gate passing)

### Migrations
- [x] `scheduled_report` (tenant-scoped; report_key string; config JSON; frequency string;
      format string; recipients JSON; next_run_at/last_run_at datetimes; is_active boolean)

### Models
- [x] `ScheduledReport` (BelongsToTenant; KEY_* constants for 5 report keys;
      FREQUENCY_* constants daily/weekly/monthly; casts config/recipients→array,
      next_run_at/last_run_at→datetime, is_active→boolean;
      `computeNextRun()` method returning self)

### Services
- [x] `ReportService.delinquency()` (overdue non-cancelled charges grouped by unit;
      total_overdue_amount, unit_count)
- [x] `ReportService.incomeStatement(start, end)` (confirmed payments + expenses in period;
      total_income, total_expenses, net)
- [x] `ReportService.accountBalances()` (active BankAccounts with type/purpose eager-loaded;
      ordered by is_primary DESC)
- [x] `ReportService.auditLog(filters, perPage)` (DB::table paginator; user_id/auditable_type/
      from/to filters)
- [x] `ReportService.collectionProgress(batchId)` (non-cancelled charges per batch;
      paid/overdue/pending counts; progress_pct rounded to 2 decimals)

### Controllers + Routes
- [x] Orion resource: `scheduled_report` (withSoftDeletes + auth:sanctum + initializeTenancy)
- [x] GET endpoints: `reports/delinquency`, `reports/income-statement`,
      `reports/account-balances`, `reports/audit-log`, `reports/collection-progress`
      (all auth:sanctum + initializeTenancy)
- [x] `POST reports/{key}/generate` (on-demand generation, delegates to ReportController)

### Seeding + Menu
- [x] `modules.csv`, `permissions.csv`, `module_system.csv` rows added
- [x] `SystemTableSeeder`: permission entities, routes, icons
- [x] `RolePermissionTableSeeder`: scheduled_report in `$commonPermissions`

### Tests (ReportsModuleTest.php — 8 scenarios)
- [x] A. Delinquency lists one overdue unit with correct total_overdue_amount
- [x] B. Delinquency excludes cancelled charges (unit_count = 0)
- [x] C. Income statement nets confirmed payments minus expenses (120,000 - 30,000 = 90,000)
- [x] D. Income statement excludes out-of-period payments
- [x] E. Account balances returns active accounts only (1 active, 1 inactive → 1 returned)
- [x] F. Collection progress: 2 completed + 1 overdue → paid_count=2, overdue_count=1,
      progress_pct≈66.67
- [x] G1. ScheduledReport CRUD + computeNextRun (monthly → future next_run_at)
- [x] G2. All three frequencies produce non-null next_run_at in the future
- [x] H. Tenant isolation: scheduled_report from tenant A not visible in tenant B

### Deferred
- [ ] PDF generation pipeline (Dompdf integration + storage + delivery)
- [ ] Excel/CSV export (Maatwebsite Excel integration)
- [ ] Automated scheduled dispatch queue job (reads ScheduledReport, runs service, delivers)
- [ ] Resident-own-unit gate (unit-level data restriction for resident role)
- [ ] Email/push delivery of scheduled report output
- [ ] Cross-tenant aggregation for super-admin
