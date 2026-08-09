# Plan 060: Index the `expense` table for the financial dashboards and make the period filter sargable

> **Executor instructions**: Follow step by step; verify each step. STOP
> conditions halt you. Update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C backend diff --stat 8117e66..HEAD -- Modules/Treasury/database/migrations Modules/Treasury/app/Http/Controllers/PublicSummaryController.php Modules/Treasury/app/Services/TreasuryReportService.php`

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: performance
- **Planned at**: backend `8117e66`, 2026-07-01

## Why this matters

The `expense` table is a single shared table across all tenants (Postgres single-DB,
row-tenancy). The new financial-dashboard/tablero endpoints run ~8 aggregation queries
filtering/grouping by `paid_at`, `expense_status_id`, `provider_id`, and
`expense_category_id` — none of which are indexed (only `community_project_id`,
`authorized_by_user_id`, and the automatic `tenant_id` index exist). The sibling `payment`
table has exactly these indexes for the identical query shape. So every dashboard load
degrades to "index-scan tenant_id, then filter the tenant's whole expense history
in-memory," growing linearly with each cluster's history. Additionally,
`PublicSummaryController` (hit on every dashboard load, uncached) filters with
`whereYear('paid_at')`/`whereMonth('paid_at')`, which compile to date-extraction
expressions a plain b-tree on `paid_at` cannot satisfy anyway.

## Current state

- `backend/Modules/Treasury/database/migrations/2026_05_31_200012_create_expense_table.php`:
  indexes ONLY `community_project_id` (line 51) and `authorized_by_user_id` (line 54);
  `BaseModel::columns($table, true)` adds the `tenant_id` index. No index on `paid_at`,
  `expense_status_id`, `provider_id`, `expense_category_id`. There ARE integer `year`
  (line 37) and `month` (line 38) columns.
- Compare `Modules/Collections/database/migrations/2026_05_31_154430_create_payment_table.php`
  (≈39–44): `index('payment_status_id')` + composite `index(['tenant_id','paid_at'])`.
- `Modules/Treasury/app/Http/Controllers/PublicSummaryController.php` (≈45–68): uses
  `whereYear('paid_at', $year)->whereMonth('paid_at', $month)` for income and expense
  aggregates; `groupBy('expense_category_id')`. This controller has NO caching (unlike
  `TreasuryReportService`, which is cached 300s).
- `Modules/Treasury/app/Services/TreasuryReportService.php` (≈143–146, 362–422): 5 scans
  of `expense` filtered by `expense_status_id != X AND paid_at BETWEEN [...]`, two grouped
  by `expense_category_id`/`provider_id`.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Migrate (test) | `cd backend && php artisan migrate --env=testing` | new index migration applies |
| Syntax | `cd backend && php -l Modules/Treasury/app/Http/Controllers/PublicSummaryController.php` | clean |
| Lint | `cd backend && vendor/bin/pint --dirty` | exit 0 |
| Test | `cd backend && php artisan test --filter=Treasury` and `--filter=PublicSummary` | all pass |

## Scope

**In scope**:
- A new additive migration adding indexes on `expense` mirroring `payment`: a composite
  `index(['tenant_id','paid_at'])` plus `index('expense_status_id')`,
  `index('provider_id')`, `index('expense_category_id')`. Comment nothing (indexes don't
  need column comments) but keep the migration style consistent with the payment one.
- OPTIONAL: make `PublicSummaryController`'s period filter sargable — replace
  `whereYear/whereMonth` on `paid_at` with `whereBetween('paid_at', [$start, $end])`
  (month bounds), so the new composite index is usable. (The integer `year`/`month`
  columns are an alternative, but `whereBetween` on `paid_at` matches how
  `TreasuryReportService` already queries.)

**Out of scope**:
- Adding caching to `PublicSummaryController` (that interacts with the resident-transparency
  product question — see the index's PERF-03 note; separate).
- Changing what the endpoints return.

## Steps

### Step 1: Add the index migration

New migration `ALTER`ing `expense` to add the four indexes. Model it on the payment table
migration's index style.

**Verify**: `php artisan migrate --env=testing` applies cleanly; re-running `migrate` is a
no-op (idempotent).

### Step 2 (optional but recommended): sargable period filter

In `PublicSummaryController`, compute `$start = Carbon::create($year,$month,1)->startOfMonth()`
and `$end = (clone $start)->endOfMonth()`, then `whereBetween('paid_at', [$start, $end])`
for both income and expense aggregates, so the `['tenant_id','paid_at']` index is used.

**Verify**: `php -l` clean; `php artisan test --filter=PublicSummary` (or `--filter=Treasury`)
still green (same numbers, faster plan).

### Step 3: Format

**Verify**: `vendor/bin/pint --dirty` → exit 0.

## Done criteria

- [ ] `expense` has indexes on `(tenant_id, paid_at)`, `expense_status_id`, `provider_id`,
      `expense_category_id`
- [ ] Migration is idempotent / re-runnable
- [ ] (If Step 2 taken) `PublicSummaryController` uses `whereBetween('paid_at', …)`; tests
      return identical totals
- [ ] `php artisan test --filter=Treasury` green
- [ ] `vendor/bin/pint --dirty` exit 0
- [ ] `plans/README.md` row updated

## STOP conditions

- The expense migration/indexes already changed since the excerpt (drift) — re-check what
  indexes exist before adding duplicates.
- Switching to `whereBetween` changes a test's expected total (would mean the year/month
  and paid_at boundaries disagree somewhere) — investigate before shipping Step 2; Step 1
  (indexes) is safe to ship alone.

## Maintenance notes

- New aggregation endpoints over `expense` should filter on the now-indexed columns and
  use `whereBetween` on `paid_at`, not `whereYear/whereMonth`.
- Reviewer: confirm the composite index column order is `(tenant_id, paid_at)` (tenant
  first — every query is tenant-scoped).
- Follow-up (separate): consider caching `PublicSummaryController` like
  `TreasuryReportService`, pending the resident-transparency product decision.
