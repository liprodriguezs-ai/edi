---
name: wu5-report-review
description: WU-5 Reports & dashboard review findings — 1 WARNING (duplicate export column), 4 SUGGESTIONs
metadata:
  type: project
---

Review of `PackageReportService`, `PackageReportController`, `PackageReportTest` (WU-5, package-reception, 2026-06-29).

**Verdict: fix-then-ship (1 WARNING, 4 SUGGESTIONs)**

### WARNING

**W1 — Duplicate column in Excel export (`PackageReportController.php` lines 77-80 and reportColumns lines 130/134)**
In `export()`, both `$row->storage_location` and `$row->location` are set to `$pkg->storage_location`. The `reportColumns()` then maps:
- "Ubicación de almacenamiento" → `$row->storage_location`
- "Ubicación" → `$row->location`
Result: Excel columns 5 and 9 always contain identical data. Users see two identically-valued columns. Fix: either drop "Ubicación" or map it to a different field (e.g., tower/floor derived from `unit_full_identifier`).

### SUGGESTIONs

**S1 — `generated_at` in `aging()` uses UTC not tenant timezone (line 87)**
`Carbon::now()->toIso8601String()` uses default timezone. `$now` is already computed as `Carbon::now($timezone)` at line 49. Fix: `'generated_at' => $now->toIso8601String()`.

**S2 — `export()` eager-loads `status` relation unnecessarily (line 63)**
All rows are READY (filtered by `PackageStatus::READY`). The `->with(['status'])` is a wasted JOIN. The fallback `?? 'Listo'` is dead code (FK is non-nullable) and hardcodes a catalog label.

**S3 — Missing tenant isolation test for `pendingCount` (PackageReportTest.php)**
`aging` has `test_aging_is_tenant_scoped`. `pendingCount` has zero cross-tenant tests. BelongsToTenant handles the isolation correctly, but the gap breaks the test-pattern symmetry.

**S4 — `test_report_columns_*` creates full tenant context for a pure function (lines 343-346)**
`reportColumns()` returns a static array of closures. `makeTenant()` + `seedTenant()` are unnecessary.

### What is correct
- Both COUNT queries in `pendingCount()` use the composite index `(package_status_id, received_at)` efficiently ✓
- `pendingCount()` is COUNT-only — no list load ✓
- Tenant scoping via BelongsToTenant active on all three methods ✓
- `diffInDays(received_at → now)` direction is non-negative (correctly avoids collections-report-signed-diffindays bug) ✓
- PHP-side `now()->subDays(8)` in pendingCount is equivalent to tenant-tz calc for pure duration-based boundaries (timezone offsets cancel in subtraction) ✓
- Route ordering: `package/report/*` literal routes declared before `Orion::resource('package', ...)` so no route collision ✓
- Authorization: `read.report_package` on aging/export, `read.package` on pendingCount (widget) — matches design §2 ✓
