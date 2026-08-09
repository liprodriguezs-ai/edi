---
name: pattern-report-controller-export-hardening
description: ReportController (core, shared/legacy) grew a REPORT_MODEL_MAP for 15 Kivo7 models; two CRITICAL correctness bugs found (lazy()+orderBy tie-break, getFillable()-as-filter-whitelist broken for Audit); core now couples to 11 domain modules by design (minimal-touch tradeoff)
type: pattern
---

Context: `export-excel-lists` SDD change hardened `core/src/Http/Controllers/ReportController.php`
(a legacy, non-Orion, non-module-owned shared controller under `QCH\Core`) to serve xlsx exports
for 15 Kivo7 models via a `private const REPORT_MODEL_MAP` + `mappedModelReport()`. Reviewed
2026-07-06 against `tests/Feature/CustomReportTest.php` (8 cases, passing at review time).

**Why this matters for future reviews of this controller / of report exports anywhere in the repo:**

1. **`Builder::lazy($n)` is OFFSET/LIMIT pagination, NOT ID-based** (verified in
   `vendor/laravel/framework/.../Concerns/BuildsQueries.php:253-276` — `forPage($page, $chunkSize)->get()`
   in a loop). It is only safe with a fully deterministic `ORDER BY` (no ties). Ordering by
   `created_at` alone is NOT deterministic in this codebase — bulk-generated rows (a `ChargeBatch`
   creating hundreds of `Charge` rows, or bulk `Audit` inserts) routinely share the exact same
   `created_at`. Combined with the fact this `lazy()` path only activates above
   `config('excel.exports.sync_row_threshold')` (i.e. exactly the large/bulk-generated datasets),
   this is a live, demonstrable bug: duplicated/skipped rows across page boundaries. Fix pattern:
   add a deterministic tie-breaker (`->orderBy('created_at','desc')->orderBy('id','desc')`) or
   switch to `lazyById()`/`lazyByIdDesc()` if ordering-by-id is acceptable. **Grep for
   `->lazy(` anywhere paired with a non-unique `orderBy` column — same bug class will recur.**

2. **Do not derive a filter/query whitelist from `Model::getFillable()`.** `getFillable()` returns
   the literal `$fillable` property — for any model using `$guarded = []` instead (e.g.
   `Modules\Audit\Models\Audit` → `QCH\Core\Models\Audit` → `\OwenIt\Auditing\Models\Audit` which
   sets `protected $guarded = [];` and never defines `$fillable`), `getFillable()` silently returns
   `[]`. `ReportController::applyRequestFilters()` builds its filter whitelist this way
   (`array_merge($model->getFillable(), ['id','created_at','updated_at'])`), so **every body-filter
   on Audit exports except id/created_at/updated_at is silently dropped** — no error, just an
   unfiltered (but still tenant-scoped) export. Mass-assignment protection (`$fillable`/`$guarded`)
   and "fields safe to let a user filter/query by" are different concerns; conflating them breaks
   silently for any `$guarded=[]` model. The project already has the right pattern for this
   (Orion's `filterableBy()` per-controller) — a shared cross-model helper like this should define
   its own explicit allowlist mechanism, not repurpose `$fillable`.

3. **`core` now has compile-time `use` imports of 11 domain modules** (Access, Audit, Collections,
   Housing, Projects, Providers, Treasury) via `REPORT_MODEL_MAP`. This is a deliberate, acknowledged
   "minimal-touch" tradeoff on a legacy pre-Orion controller (not flagged as blocking), but it is a
   real Dependency Inversion violation worth tracking — if this pattern spreads to other core
   controllers, push back harder. Correct long-term shape: per-module self-registration
   (container tag / static registry) instead of `core` naming every module's models by hand.

4. **Money in `reportColumns()` closures is hand-extracted** via
   `(float) $result->x->formatByDecimal() : 0` (cknow/laravel-money's `MoneyFormatterTrait`,
   confirmed at `vendor/cknow/laravel-money/src/MoneyFormatterTrait.php:98`), repeated across ~10
   models, discarding currency entirely. Diverges from the skill's single documented Money-egress
   helper (`TenantMoney::format()`, laravel-backend-architecture §3.17) which is meant for exactly this kind of
   output (PDF/recibo/report strings). Reasonable UX choice for spreadsheets (raw number, summable)
   but should be centralized + explicitly called out as a deliberate exception, not organically
   copy-pasted per model.

5. When reviewing new `reportColumns()` additions on a model, always cross-check the closures'
   accessed relations (including relations touched *inside* accessors, e.g. `Unit::full_identifier`
   reads `$this->unitType`/`$this->tower` internally) against `REPORT_MODEL_MAP[...]['with']` in
   `ReportController.php` — this diff got the N+1 correspondence exactly right across all 15
   models, which is the bar to hold future additions to.

See also [[audit-2026-06-12]] for the project's general recurring-violation history.
