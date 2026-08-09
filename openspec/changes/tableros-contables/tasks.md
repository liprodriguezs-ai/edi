# SDD Tasks — Tableros Contables

- **Change:** `tableros-contables`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** tasks
- **Artifact store:** hybrid
- **Date:** 2026-06-16
- **Delivery:** single PR with `size:exception` (cached user choice)
- **TDD mode:** Standard (strict_tdd = FALSE) — tests written alongside, not TDD-first
- **Depends on:** spec #1184, design #1186, `openspec/changes/tableros-contables/design.md`

---

## Work-unit overview

| # | Work unit | Commit boundary | Parallelizable after |
|---|-----------|-----------------|----------------------|
| WU-1 | Chart foundation (deps + atom + wrappers + theme) | `feat: add recharts + shadcn chart foundation and 4 themed wrappers` | Start immediately |
| WU-2 | Backend — Treasury report endpoints (3) | `feat(treasury): add TreasuryReportService and aggregation endpoints` | Start immediately (parallel with WU-1) |
| WU-3 | Backend — Collections report endpoint (1) | `feat(collections): add CollectionsReportService and aging endpoint` | Start immediately (parallel with WU-1 and WU-2) |
| WU-4 | Backend — Projects report endpoint (1) | `feat(projects): add ProjectsReportService and funding-summary endpoint` | Start immediately (parallel with WU-1 to WU-3) |
| WU-5 | Module registration (seeders + iconMap + routes) | `feat: register 5 tableros modules in sidebar and permissions` | After WU-2, WU-3, WU-4 (iconMap edit can be done in WU-1) |
| WU-6 | Frontend — Estado de Resultados dashboard | `feat(dashboards): add EstadoResultados dashboard (P&L)` | After WU-1, WU-2, WU-5 |
| WU-7 | Frontend — Cobranza / Morosidad dashboard | `feat(dashboards): add Cobranza dashboard (aging)` | After WU-1, WU-3, WU-5 |
| WU-8 | Frontend — Tesorería dashboard | `feat(dashboards): add Tesoreria dashboard (cashflow)` | After WU-1, WU-2, WU-5 |
| WU-9 | Frontend — Gastos dashboard | `feat(dashboards): add Gastos dashboard (expenses)` | After WU-1, WU-2, WU-5 |
| WU-10 | Frontend — Proyectos Comunitarios dashboard | `feat(dashboards): add Proyectos dashboard (funding)` | After WU-1, WU-4, WU-5 |
| WU-11 | Quality gates + review | `chore: run quality gates for tableros-contables` | After all WU-1 to WU-10 |

---

## WU-1 — Chart foundation

Satisfies: REQ-CF-1, REQ-CF-2, REQ-CF-3

### 1.1 Dependency installation

- [x] **1.1.1** Check `frontend/.npmrc` for `strict-peer-dependencies=false`. If absent, add it (or pass the flag per command) to allow Recharts 2.x peer range mismatch with React 19.
- [x] **1.1.2** From `frontend/`, run: `pnpm add recharts@^2.15.0 --config.strict-peer-dependencies=false`. Verify `frontend/package.json` now lists `recharts` at `^2.15.x`.
- [x] **1.1.3** Run: `pnpm dlx shadcn@latest add chart`. Verify `frontend/src/components/ui/chart.tsx` was created and NO other existing `components/ui/*.tsx` were overwritten.
- [x] **1.1.4** Update `.project-context/stack.md`: change the Recharts entry to reflect `2.15.x (React 19 via --config.strict-peer-dependencies=false)`. This closes the documentation debt flagged in the design.

### 1.2 Theme tokens

- [x] **1.2.1** Open `frontend/src/index.css`. Check whether CSS custom properties `--chart-1` through `--chart-5` exist inside an `@theme` or `:root` block.
- [x] **1.2.2** If absent, add them under a Tailwind v4 `@theme` block (both light and dark variants matching the shadcn palette). Example pattern (adjust actual color values to match the existing shadcn theme):
  ```css
  @theme {
    --chart-1: oklch(0.646 0.222 41.116);
    --chart-2: oklch(0.6 0.118 184.704);
    --chart-3: oklch(0.398 0.07 227.392);
    --chart-4: oklch(0.828 0.189 84.429);
    --chart-5: oklch(0.769 0.188 70.08);
  }
  ```
  Confirm dark mode override block also exists if shadcn's chart.tsx references dark-specific `--chart-*` vars.

### 1.3 shadcn chart.tsx atom

- [x] **1.3.1** Verify `frontend/src/components/ui/chart.tsx` exports: `ChartContainer`, `ChartTooltip`, `ChartTooltipContent`, `ChartLegend`, `ChartLegendContent`, `ChartConfig`, `ChartStyle`. These must come from the `pnpm dlx shadcn add chart` output — do NOT hand-edit the atom beyond what the CLI emits.
- [x] **1.3.2** Confirm no TypeScript error in `chart.tsx` by running `pnpm build` once (quick sanity-check; full gate is in WU-11).

### 1.4 iconMap.ts additions (prerequisite for WU-5)

- [x] **1.4.1** Open `frontend/src/shared/utils/iconMap.ts`. Add the following 3 Tabler imports alongside existing imports:
  ```ts
  import { IconTrendingUp, IconWallet, IconReceipt } from '@tabler/icons-react';
  ```
- [x] **1.4.2** Register these 3 entries in the map object:
  ```ts
  IconTrendingUp,
  IconWallet,
  IconReceipt,
  ```
  Verify `IconAlertTriangle`, `IconTargetArrow`, `IconChartBar` are ALREADY in the map (confirmed by design) — do not re-add them.

### 1.5 Themed chart wrappers

Create `frontend/src/shared/components/charts/` directory (create if does not exist) and the 4 wrapper files.

- [x] **1.5.1** `LineBarComboChart.tsx` — Recharts `ComposedChart` with bars (income + expense) and a line (net). Interface:
  ```ts
  interface LineBarComboChartProps {
    data: Array<{ label: string; income_minor: number; expense_minor: number; net_minor: number }>;
    config: ChartConfig;
  }
  ```
  Use `ChartContainer`, `ResponsiveContainer` (width 100%, height configurable via prop). Format money on Y-axis ticks and tooltip via `formatMinorUnits`. Colors from `--chart-1`, `--chart-2`, `--chart-3`. No `any`.

- [x] **1.5.2** `GroupedBarChart.tsx` — Recharts `BarChart` with multiple `Bar` children (grouped, `barCategoryGap`). Interface:
  ```ts
  interface GroupedBarChartProps {
    data: Array<Record<string, number | string>>;
    bars: Array<{ key: string; label: string; color: string }>;
    xAxisKey: string;
    formatMoney?: boolean; // when true, format Y-axis + tooltip values as formatMinorUnits
  }
  ```
  Use `ChartContainer`, `ResponsiveContainer`. No horizontal scroll at 375px (narrow bars or scrollable via Tailwind overflow-x-auto wrapper — verify at mobile width). No `any`.

- [x] **1.5.3** `DonutChart.tsx` — Recharts `PieChart` with `innerRadius`. Interface:
  ```ts
  interface DonutChartProps {
    data: Array<{ label: string; value: number; color?: string }>;
    formatMoney?: boolean; // format value as formatMinorUnits in tooltip/center
    totalLabel?: string;
  }
  ```
  Bind slice colors to `--chart-N` vars (iterate through them). Tooltip shows label + formatted value. No `any`.

- [x] **1.5.4** `AreaTrendChart.tsx` — Recharts `AreaChart`. Interface:
  ```ts
  interface AreaTrendChartProps {
    data: Array<{ label: string; value: number }>;
    color?: string; // defaults to --chart-1
    formatMoney?: boolean;
  }
  ```
  Use `ChartContainer`, `ResponsiveContainer`. Gradient fill (standard shadcn area chart pattern). No `any`.

- [x] **1.5.5** Export all 4 from `frontend/src/shared/components/charts/index.ts` barrel file. NOTE: barrel files are prohibited by skill §4 rule 1 — skipped intentionally. Dashboards import directly from each wrapper file.

- [x] **1.5.6** Verify: run `pnpm build` locally — zero TypeScript errors in wrappers. Zero `any` (use `tsc --strict` if needed, or check manually — CI gate is WU-11).

---

## WU-2 — Backend: Treasury report endpoints (3)

Satisfies: REQ-BE-1, REQ-BE-2, REQ-BE-3, REQ-BE-4, REQ-BE-6, REQ-BE-7, REQ-BE-9

All files in `backend/Modules/Treasury/`.

### 2.1 Preparation (read models before writing Service)

- [ ] **2.1.1** Open `backend/Modules/Treasury/app/Models/BankAccount.php` — confirm `BelongsToTenant`, `current_balance` column name and cast (`MoneyIntegerCast`), `is_active` column.
- [ ] **2.1.2** Open `backend/Modules/Treasury/app/Models/Expense.php` — confirm `BelongsToTenant`, `amount` column name, expense_status_id + `ExpenseStatus::VOIDED` constant, `paid_at` column, `provider_id` nullable FK, relationship `provider()`, relationship `category()` (for expense_category_id).
- [ ] **2.1.3** Open `backend/Modules/Collections/app/Models/Payment.php` — confirm `BelongsToTenant`, `total_amount` column, `payment_status_id`, `PaymentStatus::CONFIRMED` constant, `paid_at`.
- [ ] **2.1.4** Open `backend/Modules/Providers/app/Models/Provider.php` — confirm `is_recurring` boolean column.
- [ ] **2.1.5** Check `.npmrc` / `.npmrc` equivalent for pnpm strict-peer setting (already done in 1.1.1 but confirm on the PHP side for test runner: if the suite runs on SQLite, note it for the aggregation query strategy).

### 2.2 TreasuryReportService

- [ ] **2.2.1** Create `backend/Modules/Treasury/app/Services/TreasuryReportService.php`.
  - Namespace: `Modules\Treasury\Services`.
  - No constructor injection needed (uses static model classes via scoped queries).
  - **Private helper: `buildBuckets(int $months, string $timezone): array`** — returns `[['year', 'month', 'label', 'start' (Carbon UTC), 'end' (Carbon UTC)], ...]` ordered oldest-first. Use `now($timezone)->startOfMonth()->subMonths($months - 1 - $i)` per iteration. Spanish label "Mmm YYYY" (use `Carbon::setLocale('es')` or map month number to Spanish abbreviation constant).
  - **Private helper: `monthlyInflow(array $buckets): array`** — keyed by `"YYYY-MM"`, value = income_minor integer. Uses `Payment::query()->where('payment_status_id', PaymentStatus::CONFIRMED)`. Single grouped Postgres query: `selectRaw("to_char((paid_at AT TIME ZONE 'UTC' AT TIME ZONE ?, 'YYYY-MM') as ym, SUM(CAST(total_amount AS BIGINT)) as total", [$tz])->groupBy('ym')->pluck('total','ym')->all()`. Provide a per-bucket `whereBetween` fallback if the test path is SQLite (check `config('database.default')`).
  - **Private helper: `monthlyOutflow(array $buckets): array`** — same shape, `Expense::query()->where('expense_status_id', '!=', ExpenseStatus::VOIDED)->whereBetween` variant. Sum `CAST(amount AS BIGINT)`.
  - **Public method: `incomeExpenseSeries(int $months): array`** — calls `buildBuckets`, `monthlyInflow`, `monthlyOutflow`, maps onto buckets (missing → 0), computes `net_minor = income_minor - expense_minor` per bucket. Computes `totals`. Returns shape from spec §5.1.
  - **Public method: `cashflowSeries(int $months): array`** — `accounts` from `BankAccount::query()->where('is_active', true)->get(...)`, `total_balance_minor` = sum of `(int) $acc->getRawOriginal('current_balance')`. `series` buckets via reused inflow/outflow helpers; `running_balance_minor[i]` = cumulative `Σ(inflow-outflow)` up to i. Returns shape from spec §5.3.
  - **Public method: `expensesBreakdown(int $months): array`** — window = `$months` (bucket helpers). Sub-aggregates:
    - `by_category`: `Expense::query()->...->selectRaw('expense_category_id, SUM(CAST(amount AS BIGINT)) as total')->groupBy('expense_category_id')->with('category:id,name')->get()`. `pct` = `round(total / grandTotal * 100)`, guarded by zero `grandTotal → 0`. `label` = `category->name ?? 'Sin categoría'`.
    - `by_provider`: same structure, `whereNotNull('provider_id')`, grouped by `provider_id`, `with('provider:id,name')`, ordered desc by amount.
    - `monthly`: per-bucket expense series `[{label, amount_minor}]`.
    - `recurring_minor`: `Expense::query()->whereHas('provider', fn($q) => $q->where('is_recurring', true))->sum(DB::raw('CAST(amount AS BIGINT)'))` (still on scoped builder; `DB::raw` inside `sum()` = allowed).
    - `one_off_minor = total_minor - recurring_minor`. Returns shape from spec §5.4.

### 2.3 TreasuryReportController

- [ ] **2.3.1** Create `backend/Modules/Treasury/app/Http/Controllers/TreasuryReportController.php`.
  - Extends `QCH\Core\Http\Controllers\Controller`.
  - Constructor: `public function __construct(private TreasuryReportService $service) {}`.
  - **`incomeExpenseSeries(Request $request): JsonResponse`**: validate `months` inline (`$request->validate(['months' => 'integer|min:1|max:24'])`, default 12 via `$request->integer('months', 12)`). Authorize: `$this->authorize('read.report_pnl', Payment::class)`. Return `response()->json($this->service->incomeExpenseSeries($months))`.
  - **`cashflowSeries(Request $request): JsonResponse`**: authorize `read.report_treasury`. Same `months` validation. Return cashflow payload.
  - **`expensesBreakdown(Request $request): JsonResponse`**: authorize `read.report_expenses`. Same `months` validation. Return expenses breakdown payload.
  - All imports explicit, no `use App\Http\Controllers\Controller` (use QCH base).

### 2.4 Routes

- [ ] **2.4.1** Open `backend/Modules/Treasury/routes/api.php`. Locate the `$customActionMiddleware` group (the one with `['auth:sanctum','rejectRefreshToken','authActive','initializeTenancy']` — verify exact middleware names from the file). Add inside that group:
  ```php
  Route::get('treasury/income-expense-series', [TreasuryReportController::class, 'incomeExpenseSeries']);
  Route::get('treasury/cashflow-series', [TreasuryReportController::class, 'cashflowSeries']);
  Route::get('treasury/expenses-breakdown', [TreasuryReportController::class, 'expensesBreakdown']);
  ```
  Add the import at the top: `use Modules\Treasury\Http\Controllers\TreasuryReportController;`.

### 2.5 Feature tests — Treasury endpoints

- [ ] **2.5.1** Create `backend/Modules/Treasury/tests/Feature/TreasuryReportControllerTest.php`.
  - Use `RefreshDatabase` (or the project test base trait — check existing module test files for pattern).
  - **Shared setup:** create 2 tenants (A and B). Create a user for each with `read.report_pnl`, `read.report_treasury`, `read.report_expenses` permissions. Seed representative Payments (CONFIRMED), Expenses (non-VOIDED and VOIDED), and BankAccounts for tenant A. Seed unrelated data for tenant B.
  - **Tests for `/treasury/income-expense-series`:**
    - Response structure: `series` array with `year`, `month`, `label`, `income_minor`, `expense_minor`, `net_minor`. All monetary fields are integers.
    - Default months=12 → exactly 12 series entries, ordered oldest-first.
    - `net_minor = income_minor - expense_minor` (assert arithmetic on seeded known values).
    - VOIDED expenses excluded from `expense_minor`.
    - Tenant B data absent (call as tenant A user, assert B's payments not in sum).
    - Unauthenticated → HTTP 401.
    - Missing permission → HTTP 403.
  - **Tests for `/treasury/cashflow-series`:**
    - `accounts` array contains only active BankAccounts; `current_balance_minor` is integer.
    - `running_balance_minor` is cumulative (verify month-2 = month-1-net + month-2-net).
    - Tenant isolation + 401/403.
  - **Tests for `/treasury/expenses-breakdown`:**
    - `by_category` pct sum ≈ 100 (±1 for integer rounding).
    - `recurring_minor` counts only expenses linked to `is_recurring=true` providers.
    - `one_off_minor = total_minor - recurring_minor` (assert arithmetic).
    - Tenant isolation + 401/403.

---

## WU-3 — Backend: Collections report endpoint (aging)

Satisfies: REQ-BE-1, REQ-BE-2, REQ-BE-3, REQ-BE-5, REQ-BE-9

All files in `backend/Modules/Collections/`.

### 3.1 Preparation

- [ ] **3.1.1** Open `backend/Modules/Collections/app/Models/Charge.php` — confirm: `BelongsToTenant`, columns `expected_amount`, `discount_amount`, `paid_amount` (or confirm actual column names for the raw calculation), `is_cancelled` boolean, `due_date`, `charge_status_id`. Confirm relationship `chargeStatus()` (BelongsTo ChargeStatus). Confirm `ChargeStatus::OVERDUE` and `ChargeStatus::PARTIAL` constants.
- [ ] **3.1.2** Open `backend/Modules/Collections/app/Http/Controllers/PaymentController.php` — read the `delinquency` action carefully to understand the outstanding calculation: `max(0, expected - discount - paid)` using `getAttributes()` or `getRawOriginal()`. Copy the exact PHP pattern (do NOT re-derive it — this is the seam the design authorizes reusing).
- [ ] **3.1.3** Confirm relationship `unit()` on `Charge` (for `unit_label`). Confirm `unit->number` or `unit->full_identifier`.
- [ ] **3.1.4** Confirm `collection_rate_pct` axis: check `ChargeStatusService` and charge migrations for whether `issued_at` or `due_date` is used to bucket "charges of this month". Default to `due_date` if ambiguous (per design §7).

### 3.2 CollectionsReportService

- [ ] **3.2.1** Create `backend/Modules/Collections/app/Services/CollectionsReportService.php`.
  - Namespace: `Modules\Collections\Services`.
  - **Public method: `aging(): array`** — implements spec §REQ-BE-5:
    - Load: `Charge::query()->whereIn('charge_status_id', [ChargeStatus::OVERDUE, ChargeStatus::PARTIAL])->where('is_cancelled', false)->with(['unit', 'chargeStatus'])->get()`.
    - Per charge: `outstanding = max(0, (int)$charge->getRawOriginal('expected_amount') - (int)$charge->getRawOriginal('discount_amount') - (int)$charge->getRawOriginal('paid_amount'))`.
    - Aging bucket assignment by `$charge->due_date` vs `now(tenant_timezone)`:
      - `current`: due_date >= today (not yet due but in the overdue/partial set — edge case, keep in bucket).
      - `d1_30`: 1–30 days past due.
      - `d31_60`: 31–60 days.
      - `d61_90`: 61–90 days.
      - `d90_plus`: 91+ days.
    - `total_outstanding_minor` = sum of all outstanding.
    - `units_with_debt` = count of distinct `unit_id`.
    - `collection_rate_pct`: 2 scoped aggregate queries — charges due in current month (by `due_date`) = `issued_total`; those with `charge_status_id` IN [COMPLETED, OVERPAID] = `paid_count`. `pct = issued_total > 0 ? (int)round($paid_count / $issued_total * 100) : 0`.
    - `by_status`: group loaded collection by `charge_status_id` → `{ status_key: chargeStatus->key ?? 'unknown', label: chargeStatus->name, count, amount_minor }`. Note: resolve `status_key` from a slug/key column on `ChargeStatus` if it exists; otherwise map from UUID constants (verify in 3.1.1).
    - `top_debtors`: group by `unit_id`, sum outstanding per unit, sort desc, take 10. Per entry: `{ unit_id, unit_label: unit->full_identifier ?? unit->number, outstanding_minor, oldest_due_date }`.
    - Returns array matching spec §5.2 shape.

### 3.3 CollectionsReportController

- [ ] **3.3.1** Create `backend/Modules/Collections/app/Http/Controllers/CollectionsReportController.php`.
  - Extends `QCH\Core\Http\Controllers\Controller`.
  - Constructor: `public function __construct(private CollectionsReportService $service) {}`.
  - **`aging(Request $request): JsonResponse`**: authorize `read.report_collections` against `Charge::class`. Return `response()->json($this->service->aging())`.

### 3.4 Routes

- [ ] **3.4.1** Open `backend/Modules/Collections/routes/api.php`. Locate the `$customActionMiddleware` group. Add inside it (alongside existing `delinquency` route):
  ```php
  Route::get('collections/aging', [CollectionsReportController::class, 'aging']);
  ```
  Add import: `use Modules\Collections\Http\Controllers\CollectionsReportController;`.

### 3.5 Feature tests — Collections endpoint

- [ ] **3.5.1** Create `backend/Modules/Collections/tests/Feature/CollectionsReportControllerTest.php`.
  - **Setup:** 2 tenants. Tenant A: seed charges with known due_dates (bucket each across the 5 aging ranges), known outstanding amounts, varied `charge_status` (OVERDUE, PARTIAL, COMPLETED). Seed 15 units with debt to test `top_debtors` cap.
  - **Tests:**
    - Charge 45 days overdue → `d31_60_minor` bucket non-zero, others zero for that charge.
    - `top_debtors` with 15 units → response contains exactly 10 entries, ordered desc by `outstanding_minor`.
    - `collection_rate_pct` = integer 0–100. For 5 issued / 2 completed → 40.
    - Spec scenario: `d31_60_minor=75000` → `outstanding_minor = 75000`, field is integer (not string).
    - `by_status`: `status_key='overdue'`, `label='Vencido'` (assert Spanish label).
    - Tenant B charges absent.
    - 401 unauthenticated, 403 missing permission.

---

## WU-4 — Backend: Projects report endpoint (funding-summary)

Satisfies: REQ-BE-1, REQ-BE-2, REQ-BE-3, REQ-BE-8, REQ-BE-9

All files in `backend/Modules/Projects/`.

### 4.1 Preparation

- [ ] **4.1.1** Open `backend/Modules/Projects/app/Models/CommunityProject.php` — confirm: `BelongsToTenant`, `goal_amount` column name, cast (MoneyIntegerCast). Confirm relationship `status()` (BelongsTo the community_project_status catalog). Confirm relationship `contributions()` (HasMany ProjectContribution).
- [ ] **4.1.2** Open `backend/Modules/Projects/app/Models/ProjectContribution.php` — confirm the money column name (design notes it as unknown at design time; verify the actual column: likely `amount` or `contribution_amount`). Confirm whether CAST is needed for `withSum` (if the column is already bigint it should sum correctly, but CAST is safer for correctness).
- [ ] **4.1.3** Check `backend/Modules/Projects/app/Models/CommunityProjectStatus.php` or the catalog rows — confirm whether the model has a `key` or `slug` column for stable `status_key` values, OR whether to map from UUID constants (e.g. `CommunityProjectStatus::ACTIVE → 'active'`).

### 4.2 ProjectsReportService

- [ ] **4.2.1** Create `backend/Modules/Projects/app/Services/ProjectsReportService.php`.
  - Namespace: `Modules\Projects\Services`.
  - **Public method: `fundingSummary(): array`** — implements spec §REQ-BE-8:
    - Load: `CommunityProject::query()->with('status:id,name')->withSum('contributions as raised_amount_sum', '<amount_col>')` (use confirmed column from 4.1.2). If CAST needed: `withSum('contributions', DB::raw('CAST(<col> AS BIGINT)'))` or a `selectRaw` subquery.
    - Per project: `goal_minor = (int) $project->getRawOriginal('goal_amount')`. `raised_minor = (int) $project->raised_amount_sum`. `pct = $goal_minor > 0 ? (int)round($raised_minor / $goal_minor * 100) : 0` (spec scenario: goal=0 → pct=0, no division).
    - `status_key`: from status `key` column if it exists, else derive from UUID constant map (confirm in 4.1.3). `status_label = $project->status->name`.
    - `by_status`: group projects by `status_id` → `{ status_key, label, count }`.
    - `totals`: `active_count` (projects in "active" status key), `goal_minor` Σ, `raised_minor` Σ, `pct = totals.goal > 0 ? round(raised/goal*100) : 0`.
    - Returns shape from spec §5.5.
    - N+1 guard: single `with('status')` + `withSum` — no per-project additional query.

### 4.3 ProjectsReportController

- [ ] **4.3.1** Create `backend/Modules/Projects/app/Http/Controllers/ProjectsReportController.php`.
  - Extends `QCH\Core\Http\Controllers\Controller`.
  - Constructor: `public function __construct(private ProjectsReportService $service) {}`.
  - **`fundingSummary(Request $request): JsonResponse`**: authorize `read.report_projects` against `CommunityProject::class`. Return `response()->json($this->service->fundingSummary())`.

### 4.4 Routes

- [ ] **4.4.1** Open `backend/Modules/Projects/routes/api.php`. Locate or create the `$customActionMiddleware` group (verify middleware array from the existing file; add it if not present using the same array as Treasury and Collections). Add:
  ```php
  Route::get('projects/funding-summary', [ProjectsReportController::class, 'fundingSummary']);
  ```
  Add import: `use Modules\Projects\Http\Controllers\ProjectsReportController;`.

### 4.5 Feature tests — Projects endpoint

- [ ] **4.5.1** Create `backend/Modules/Projects/tests/Feature/ProjectsReportControllerTest.php`.
  - **Setup:** 2 tenants. Tenant A: seed 3 projects with known `goal_amount` and contributions summing to known `raised` amounts (one project with `goal_amount=0`). Seed a project for tenant B.
  - **Tests:**
    - `goal_minor=100000, raised_minor=45000 → pct=45`. Fields are integers.
    - `goal_minor=0 → pct=0` (no division error — spec scenario).
    - `by_status` entry `status_key='active', label='Activo', count=3` → DonutChart legend assertion (assert payload, not UI).
    - Tenant B project absent from response.
    - 401 unauthenticated, 403 missing permission.
    - Feature test seeds 2 tenants → tenant 1 response contains only tenant 1 projects (spec scenario REQ-BE-8).

---

## WU-5 — Module registration

Satisfies: REQ-MR-1, REQ-MR-2, REQ-MR-3

This work unit is sequential after WU-2, WU-3, WU-4 are completed (because the seeders reference route paths that back-endpoints must be ready to serve). The iconMap edit was done in WU-1 step 1.4.

### 5.1 modules.csv

- [ ] **5.1.1** Open `backend/database/seeders/csv/modules.csv`. Append 5 rows (generate UUIDs):
  ```csv
  <uuid-1>,Estado de resultados,Tableros
  <uuid-2>,Cobranza / Morosidad,Tableros
  <uuid-3>,Tesorería,Tableros
  <uuid-4>,Gastos,Tableros
  <uuid-5>,Proyectos comunitarios,Tableros
  ```
  Record these UUIDs for use in the subsequent CSV and seeder files.

### 5.2 permissions.csv

- [ ] **5.2.1** Open `backend/database/seeders/csv/permissions.csv`. For each of the 5 modules, append **2 rows** (read + report only — ADR-1): `read.<entity>` and `report.<entity>`. Total: 10 new rows.
  ```csv
  <perm-uuid>,read,report_pnl,<module-uuid-1>
  <perm-uuid>,report,report_pnl,<module-uuid-1>
  <perm-uuid>,read,report_collections,<module-uuid-2>
  <perm-uuid>,report,report_collections,<module-uuid-2>
  <perm-uuid>,read,report_treasury,<module-uuid-3>
  <perm-uuid>,report,report_treasury,<module-uuid-3>
  <perm-uuid>,read,report_expenses,<module-uuid-4>
  <perm-uuid>,report,report_expenses,<module-uuid-4>
  <perm-uuid>,read,report_projects,<module-uuid-5>
  <perm-uuid>,report,report_projects,<module-uuid-5>
  ```
  (Verify the actual CSV column order from the existing file before writing.)

### 5.3 module_system.csv

- [ ] **5.3.1** Open `backend/database/seeders/csv/module_system.csv`. Verify the column structure (likely: `module_id, system_id, sort_order, group_label, group_icon, visible`). Append 5 rows mounting each module on system `b266838b-4cb0-4eb1-aa76-242cf96df41a` (the tenant business system — verify this UUID from the existing CSV rows for Dashboard/Collections):
  ```csv
  <uuid-1>,b266838b-...,5,Tableros,IconChartBar,1
  <uuid-2>,b266838b-...,6,Tableros,IconChartBar,1
  <uuid-3>,b266838b-...,7,Tableros,IconChartBar,1
  <uuid-4>,b266838b-...,8,Tableros,IconChartBar,1
  <uuid-5>,b266838b-...,9,Tableros,IconChartBar,1
  ```
  Confirm `sort_order` values don't collide with existing rows (read the current max sort_order in the file first).

### 5.4 SystemTableSeeder.php

- [ ] **5.4.1** Open `backend/database/seeders/SystemTableSeeder.php`. Find `seedModulePermissionEntities()` and add:
  ```php
  'Estado de resultados' => 'report_pnl',
  'Cobranza / Morosidad' => 'report_collections',
  'Tesorería' => 'report_treasury',
  'Gastos' => 'report_expenses',
  'Proyectos comunitarios' => 'report_projects',
  ```
- [ ] **5.4.2** Find `seedModuleRoutes()` and add:
  ```php
  'Estado de resultados' => '/tableros/resultados',
  'Cobranza / Morosidad' => '/tableros/cobranza',
  'Tesorería' => '/tableros/tesoreria',
  'Gastos' => '/tableros/gastos',
  'Proyectos comunitarios' => '/tableros/proyectos',
  ```
- [ ] **5.4.3** Find `seedModuleIcons()` and add:
  ```php
  'Estado de resultados' => 'IconTrendingUp',
  'Cobranza / Morosidad' => 'IconAlertTriangle',
  'Tesorería' => 'IconWallet',
  'Gastos' => 'IconReceipt',
  'Proyectos comunitarios' => 'IconTargetArrow',
  ```
- [ ] **5.4.4** Find `seedModuleVisibility()`. These 5 modules are NOT technical/internal — do NOT add them to `$hidden`. Confirm they are absent from `$hidden` (they should show in sidebar).

### 5.5 RolePermissionTableSeeder.php

- [ ] **5.5.1** Open `backend/database/seeders/RolePermissionTableSeeder.php`. Find `seedKivo7RolePermissions()`. Locate the arrays for roles `president`, `treasurer`, `oversight_committee`. Add to each:
  ```php
  'read.report_pnl',
  'report.report_pnl',
  'read.report_collections',
  'report.report_collections',
  'read.report_treasury',
  'report.report_treasury',
  'read.report_expenses',
  'report.report_expenses',
  'read.report_projects',
  'report.report_projects',
  ```
  Do NOT add to `secretary`, `vocal`, `security`, `operations_staff`, `resident`, `renter` (design §2.5).
  Do NOT add to `$permissionsOnlySuperAdmin` — `account.owner`, `company.admin`, `administrator` already inherit via `whereNotIn`.

### 5.6 Run seeder

- [ ] **5.6.1** From `backend/`, run: `php artisan db:seed --class=ResetPermissionTableSeeder`.
  Verify: no exception. Spot-check: `president` role has `read.report_pnl`. `resident` role does NOT have `read.report_pnl`. `account.owner` has `read.report_pnl`.

---

## WU-6 — Frontend: Estado de Resultados dashboard [DONE — Batch 4a]

Satisfies: REQ-D1, REQ-UI-1 to REQ-UI-9

Directory: `frontend/src/modules/dashboards/resultados/` (named `resultados` per route `/tableros/resultados`)

### 6.1 Schema

- [x] **6.1.1** Create `schema.ts`:
  ```ts
  import { z } from 'zod';

  export const seriesBucketSchema = z.object({
    year: z.number().int(),
    month: z.number().int(),
    label: z.string(),
    income_minor: z.number().int(),
    expense_minor: z.number().int(),
    net_minor: z.number().int(),
  });

  export const pnlTotalsSchema = z.object({
    income_minor: z.number().int(),
    expense_minor: z.number().int(),
    net_minor: z.number().int(),
  });

  export const pnlPayloadSchema = z.object({
    series: z.array(seriesBucketSchema),
    totals: pnlTotalsSchema,
  });

  export type IPnlSeriesBucket = z.infer<typeof seriesBucketSchema>;
  export type IPnlPayload = z.infer<typeof pnlPayloadSchema>;
  ```
  No `any`. All monetary fields typed as `number` (integer minor units).

### 6.2 Resource

- [x] **6.2.1** Create `services/ResultadosResource.ts` (named to match route):
  ```ts
  import { createCrudApi } from '@/shared/api/resourceApi';
  import type { IPnlPayload } from '../schema';

  const api = createCrudApi<never, never, never, {
    fetch: (months?: number) => Promise<IPnlPayload>;
  }>({
    basePath: '/treasury',
    customMethods: {
      fetch: (months = 12) =>
        apiClient.get<IPnlPayload>(`/treasury/income-expense-series`, { params: { months } })
          .then(r => r.data),
    },
  });

  export const EstadoResultadosResource = api;
  ```
  Adjust to match actual `createCrudApi` `customMethods` signature from `resourceApi.ts`. The key point: a single `GET /treasury/income-expense-series` returning the flat `IPnlPayload`.

### 6.3 Page component

- [x] **6.3.1** Create `ResultadosDashboard.tsx`:
  - `useQuery({ queryKey: ['pnl', months], queryFn: () => EstadoResultadosResource.fetch(months) })`.
  - **Loading state:** `<Skeleton name="pnl-dashboard" loading={isLoading}>` (boneyard-js). NEVER `animate-pulse`.
  - **Error state:** `useEffect` watching `isError` → `toast.error('Error al cargar el tablero de resultados')` (sileo, imported from `@/shared/hooks/useToast`). Show inline `<Callout>` or error message.
  - **Empty state:** when `data?.series.length === 0` → Spanish message "Aún no hay datos en el período seleccionado".
  - **KPIs (4):** use `KpiTile` for each:
    - "Ingresos (12m)" → `formatMinorUnits(totals.income_minor)`.
    - "Egresos (12m)" → `formatMinorUnits(totals.expense_minor)`.
    - "Neto" → `formatMinorUnits(totals.net_minor)`.
    - "Margen %" → `totals.income_minor > 0 ? Math.round(totals.net_minor / totals.income_minor * 100) : 0` + "%" (spec scenario: income=0 → 0%).
  - **Chart:** `<LineBarComboChart data={data.series} config={...} />` inside `<ResponsiveContainer width="100%" height={320}>`.
  - **Permission gate:** `usePermissions('read.report_pnl')` — if denied, render `<AccessDeniedPage />` (spec REQ-D1 scenario).
  - **Responsive:** chart wrapped in `ResponsiveContainer`, page uses Tailwind grid (sm/md/lg breakpoints for KPI tiles).
  - All strings in Spanish. No `any`. PageHeader title "Estado de resultados".

### 6.4 Frontend tests

- [x] **6.4.1** Create `__tests__/resultados.test.ts` — 6 tests covering URL, months param, minor-unit integers, net invariant, error propagation:
  - **Schema test:** parse a representative payload with `pnlPayloadSchema.parse(...)` — passes without error.
  - **Resource test:** mock `apiClient`, call `EstadoResultadosResource.fetch(12)`, assert it hits `/treasury/income-expense-series?months=12`.
  - **Render smoke — loading:** mock `useQuery` returning `{ isLoading: true }`, assert Skeleton rendered, KPI values not present.
  - **Render smoke — success:** mock `useQuery` returning representative payload, assert KPI values present and `formatMinorUnits` applied (e.g. "Ingresos" label visible), chart wrapper `LineBarComboChart` rendered.
  - **Render smoke — empty:** mock `useQuery` returning `{ data: { series: [], totals: {...0s} } }`, assert empty state message visible.
  - **Render smoke — error:** mock `useQuery` returning `{ isError: true }`, assert toast.error called.

---

## WU-7 — Frontend: Cobranza / Morosidad dashboard [DONE — Batch 4a]

Satisfies: REQ-D2, REQ-UI-1 to REQ-UI-9

Directory: `frontend/src/modules/dashboards/cobranza/`

### 7.1 Schema

- [x] **7.1.1** Create `schema.ts` — interfaces for the `/collections/aging` payload:
  ```ts
  import { z } from 'zod';

  export const agingBucketsSchema = z.object({
    current_minor: z.number().int(),
    d1_30_minor: z.number().int(),
    d31_60_minor: z.number().int(),
    d61_90_minor: z.number().int(),
    d90_plus_minor: z.number().int(),
  });

  export const byStatusItemSchema = z.object({
    status_key: z.string(),
    label: z.string(),
    count: z.number().int(),
    amount_minor: z.number().int(),
  });

  export const topDebtorSchema = z.object({
    unit_id: z.string().uuid(),
    unit_label: z.string(),
    outstanding_minor: z.number().int(),
    oldest_due_date: z.string(),
  });

  export const agingPayloadSchema = z.object({
    aging: agingBucketsSchema,
    total_outstanding_minor: z.number().int(),
    units_with_debt: z.number().int(),
    collection_rate_pct: z.number().int().min(0).max(100),
    by_status: z.array(byStatusItemSchema),
    top_debtors: z.array(topDebtorSchema).max(10),
  });

  export type IAgingPayload = z.infer<typeof agingPayloadSchema>;
  export type ITopDebtor = z.infer<typeof topDebtorSchema>;
  ```

### 7.2 Resource

- [x] **7.2.1** Create `services/CobranzaResource.ts` — `customMethod fetch()` hitting `GET /collections/aging`. Returns `IAgingPayload` (flat, no data-wrapper — verify against the actual response shape; delinquency returns flat, aging follows same pattern).

### 7.3 Page component

- [x] **7.3.1** Create `CobranzaDashboard.tsx`:
  - `useQuery` for `CobranzaResource.fetch()`.
  - **KPIs:** "Cartera vencida" (`total_outstanding_minor`), "Tasa de cobro" (`collection_rate_pct` + "%"), "Unidades morosas" (`units_with_debt`), "Antigüedad promedio" (compute from aging buckets: weighted average days, or show days range — document in comments).
  - **Charts:**
    - `<GroupedBarChart>` with 5 aging buckets (labels: "Corriente", "1-30 días", "31-60 días", "61-90 días", "90+ días"), `formatMoney=true`.
    - `<DonutChart>` using `by_status` (label from status.label, value from `amount_minor`, `formatMoney=true`). Spec scenario: `status_key='overdue', label='Vencido'`.
    - `<ResponsiveDataTable>` for `top_debtors` (max 10 rows, columns: "Unidad", "Deuda pendiente", "Vencimiento más antiguo"). Spanish column headers (spec REQ-D2 scenario).
  - Loading, error, empty, permission gate (`read.report_collections`), responsive — same patterns as WU-6.

### 7.4 Frontend tests

- [x] **7.4.1** Created `__tests__/cobranza.test.ts` — 7 tests covering URL, minor-unit integers, top_debtors≤10, collection_rate_pct range, bucket sum invariant, by_status field types, error propagation.

---

## WU-8 — Frontend: Tesorería dashboard [DONE — Batch 4a]

Satisfies: REQ-D3, REQ-UI-1 to REQ-UI-9

Directory: `frontend/src/modules/dashboards/tesoreria/`

### 8.1 Schema

- [x] **8.1.1** Create `schema.ts` — interfaces for `/treasury/cashflow-series` payload:
  ```ts
  // accounts[], total_balance_minor, series[{label, inflow_minor, outflow_minor, running_balance_minor}]
  ```
  All monetary fields `z.number().int()`. No `any`.

### 8.2 Resource

- [x] **8.2.1** Create `services/TesoreriaResource.ts` — `customMethod fetch(months?: number)` hitting `GET /treasury/cashflow-series`.

### 8.3 Page component

- [x] **8.3.1** Create `TesoreriaDashboard.tsx`:
  - **KPIs:** "Saldo total" (`total_balance_minor`), "# Cuentas" (`accounts.length`), "Entradas del mes" (last series entry `inflow_minor`), "Salidas del mes" (last series entry `outflow_minor`). Spec scenario: June 2026 series entry → KPIs reflect June values.
  - **Charts:**
    - `<AreaTrendChart data={series.map(s => ({ label: s.label, value: s.running_balance_minor }))} formatMoney>` — ascending series (spec scenario).
    - `<GroupedBarChart>` with `inflow_minor` vs `outflow_minor` per month label.
    - Account list: `<ul>` or simple table showing account name + `formatMinorUnits(current_balance_minor)` for each account (spec scenario: 3 accounts → all 3 shown).
  - Loading, error, empty, permission gate (`read.report_treasury`), responsive.

### 8.4 Frontend tests

- [x] **8.4.1** Created `__tests__/tesoreria.test.ts` — 7 tests covering URL, months param, minor-unit integers, running_balance cumulative invariant, account_count matches array, total_balance_minor matches sum, error propagation.

---

## WU-9 — Frontend: Gastos dashboard

Satisfies: REQ-D4, REQ-UI-1 to REQ-UI-9

Directory: `frontend/src/modules/dashboards/gastos/`

### 9.1 Schema

- [ ] **9.1.1** Create `schema.ts` — interfaces for `/treasury/expenses-breakdown` payload:
  ```ts
  // by_category[{category_id, label, amount_minor, pct}]
  // by_provider[{provider_id, name, amount_minor}]
  // monthly[{label, amount_minor}]
  // total_minor, recurring_minor, one_off_minor
  ```
  All monetary fields `z.number().int()`. `pct` as `z.number().int().min(0).max(100)`. No `any`.

### 9.2 Resource

- [ ] **9.2.1** Create `services/GastosResource.ts` — `customMethod fetch(months?: number)` hitting `GET /treasury/expenses-breakdown`.

### 9.3 Page component

- [ ] **9.3.1** Create `GastosDashboard.tsx`:
  - **KPIs:** "Gasto del mes" (last `monthly` entry `amount_minor`), "Gasto del año" (`total_minor`), "# Proveedores con gasto" (`by_provider.length`), "% Recurrente" (`total_minor > 0 ? round(recurring_minor/total_minor*100) : 0`). Spec scenario: total=200000, recurring=80000 → 40%.
  - **Charts:**
    - `<DonutChart>` by_category (`label`, `amount_minor`, `formatMoney=true`). Spec scenario: "Mantenimiento" + pct label.
    - `<GroupedBarChart>` top providers (name, amount_minor, `formatMoney=true`).
    - `<AreaTrendChart>` monthly trend (`label`, `amount_minor`, `formatMoney=true`). Spec scenario: June entry `amount_minor=30000` → "$300.00".
  - Loading, error, empty, permission gate (`read.report_expenses`), responsive.

### 9.4 Frontend tests

- [ ] **9.4.1** Schema test + Resource test + render smoke. Spec: DonutChart label "Mantenimiento" visible; "% Recurrente = 40%" visible for total=200000, recurring=80000.

---

## WU-10 — Frontend: Proyectos Comunitarios dashboard

Satisfies: REQ-D5, REQ-UI-1 to REQ-UI-9

Directory: `frontend/src/modules/dashboards/proyectos/`

### 10.1 Schema

- [ ] **10.1.1** Create `schema.ts` — interfaces for `/projects/funding-summary` payload:
  ```ts
  // projects[{id, title, goal_minor, raised_minor, pct, status_key, status_label}]
  // totals{active_count, goal_minor, raised_minor, pct}
  // by_status[{status_key, label, count}]
  ```
  All monetary fields `z.number().int()`. `pct` `z.number().int().min(0).max(100)`. No `any`.

### 10.2 Resource

- [ ] **10.2.1** Create `services/ProyectosResource.ts` — `customMethod fetch()` hitting `GET /projects/funding-summary`.

### 10.3 Page component

- [ ] **10.3.1** Create `ProyectosDashboard.tsx`:
  - **KPIs:** "Proyectos activos" (`totals.active_count`), "Meta total" (`totals.goal_minor`), "Recaudado total" (`totals.raised_minor`), "% Avance global" (`totals.pct`). Spec scenario: totals goal=0, raised=0 → "0%" (no division error — already in payload).
  - **Charts:**
    - `<GroupedBarChart>` goal vs raised per project (bars: "Meta" + "Recaudado"; labels = project titles). Spec: goal=100000, raised=45000 → bars show `formatMinorUnits` values.
    - `<DonutChart>` by_status (`label`, value = `count`, `formatMoney=false`). Spec: "Activo (3)" in legend (include count in label).
  - Loading, error, empty, permission gate (`read.report_projects`), responsive.

### 10.4 Frontend tests

- [ ] **10.4.1** Schema test + Resource test + render smoke. Spec: goal=100000/raised=45000 → "45%" visible; "Activo (3)" in DonutChart legend; "0%" when goal=0.

---

## WU-11 — App.tsx routing + quality gates

Satisfies: REQ-MR-3

### 11.1 App.tsx routes

- [ ] **11.1.1** Open `frontend/src/App.tsx`. Add 5 lazy imports:
  ```tsx
  const EstadoResultadosDashboard = lazy(() => import('@/modules/dashboards/estadoResultados/EstadoResultadosDashboard'));
  const CobranzaDashboard = lazy(() => import('@/modules/dashboards/cobranza/CobranzaDashboard'));
  const TesoreriaDashboard = lazy(() => import('@/modules/dashboards/tesoreria/TesoreriaDashboard'));
  const GastosDashboard = lazy(() => import('@/modules/dashboards/gastos/GastosDashboard'));
  const ProyectosDashboard = lazy(() => import('@/modules/dashboards/proyectos/ProyectosDashboard'));
  ```
- [ ] **11.1.2** Inside the `ProtectedRoute` + `SubscriptionGuard` block, add 5 permission-gated routes:
  ```tsx
  <Route element={<PermissionRoute permissionEntity="report_pnl" />}>
    <Route path="/tableros/resultados" element={<EstadoResultadosDashboard />} />
  </Route>
  <Route element={<PermissionRoute permissionEntity="report_collections" />}>
    <Route path="/tableros/cobranza" element={<CobranzaDashboard />} />
  </Route>
  <Route element={<PermissionRoute permissionEntity="report_treasury" />}>
    <Route path="/tableros/tesoreria" element={<TesoreriaDashboard />} />
  </Route>
  <Route element={<PermissionRoute permissionEntity="report_expenses" />}>
    <Route path="/tableros/gastos" element={<GastosDashboard />} />
  </Route>
  <Route element={<PermissionRoute permissionEntity="report_projects" />}>
    <Route path="/tableros/proyectos" element={<ProyectosDashboard />} />
  </Route>
  ```
  Spec scenario: user without `report_pnl` navigating to `/tableros/resultados` → `AccessDeniedPage` shown (this is handled by `PermissionRoute`).

### 11.2 Quality gates

Run all gates sequentially; resolve any error before marking the next gate done.

- [ ] **11.2.1** `vendor/bin/pint --dirty` (from `backend/`). Zero formatting errors.
- [ ] **11.2.2** `php artisan test --filter=TreasuryReportControllerTest` — all green.
- [ ] **11.2.3** `php artisan test --filter=CollectionsReportControllerTest` — all green.
- [ ] **11.2.4** `php artisan test --filter=ProjectsReportControllerTest` — all green.
- [ ] **11.2.5** `pnpm lint` (from `frontend/`) — zero ESLint errors. No `any` violations, no import path errors.
- [ ] **11.2.6** `pnpm build` (from `frontend/`) — TypeScript strict compile + Vite build succeed. Zero `any` in `tsc --strict` output.
- [ ] **11.2.7** `pnpm test` (from `frontend/`) — all Vitest tests green (schemas, resources, render smokes).
- [ ] **11.2.8** `/review-stack` — run the full review pipeline. Expected reviewers: `laravel-architecture-reviewer` (backend services/controllers/routes), `react-frontend-reviewer` (dashboards + chart wrappers), `integration-reviewer` (payload contracts, field names match `_minor` suffix), `tenancy-reviewer` (must confirm `tenant_id` in generated SQL via query log or test assertion), `database-architect` (aggregation query patterns, N+1 detection).
- [ ] **11.2.9** Address any CRITICAL findings from `/review-stack` before declaring the PR ready.

---

## Spec → Task traceability matrix

| Spec requirement | Satisfied by |
|---|---|
| REQ-CF-1 (recharts install) | WU-1.1 |
| REQ-CF-2 (chart.tsx shadcn atom) | WU-1.3 |
| REQ-CF-3 (4 themed wrappers) | WU-1.5 |
| REQ-MR-1 (5 modules in sidebar) | WU-5.1, WU-5.2, WU-5.3, WU-5.4, WU-5.6 |
| REQ-MR-2 (permission entities by role) | WU-5.5, WU-5.6 |
| REQ-MR-3 (PermissionRoute in App.tsx) | WU-11.1 |
| REQ-BE-1 (tenant isolation) | WU-2.1–2.4, WU-3.2–3.4, WU-4.2–4.4 |
| REQ-BE-2 (minor units end-to-end) | All backend WUs (CAST BIGINT + _minor fields) |
| REQ-BE-3 (aggregation in Services) | WU-2.2, WU-3.2, WU-4.2 |
| REQ-BE-4 (income-expense-series) | WU-2.2, WU-2.3, WU-2.4 |
| REQ-BE-5 (collections aging) | WU-3.2, WU-3.3, WU-3.4 |
| REQ-BE-6 (cashflow-series) | WU-2.2, WU-2.3, WU-2.4 |
| REQ-BE-7 (expenses-breakdown) | WU-2.2, WU-2.3, WU-2.4 |
| REQ-BE-8 (funding-summary) | WU-4.2, WU-4.3, WU-4.4 |
| REQ-BE-9 (feature tests per endpoint) | WU-2.5, WU-3.5, WU-4.5 |
| REQ-UI-1 (read-only + useQuery) | WU-6.3, WU-7.3, WU-8.3, WU-9.3, WU-10.3 |
| REQ-UI-2 (boneyard-js skeleton) | WU-6.3, WU-7.3, WU-8.3, WU-9.3, WU-10.3 |
| REQ-UI-3 (Spanish empty state) | WU-6.3, WU-7.3, WU-8.3, WU-9.3, WU-10.3 |
| REQ-UI-4 (sileo toast error + inline) | WU-6.3, WU-7.3, WU-8.3, WU-9.3, WU-10.3 |
| REQ-UI-5 (usePermissions gate) | WU-6.3, WU-7.3, WU-8.3, WU-9.3, WU-10.3 |
| REQ-UI-6 (responsive) | WU-1.5, WU-6.3, WU-7.3, WU-8.3, WU-9.3, WU-10.3 |
| REQ-UI-7 (Spanish UI, no any) | All frontend WUs |
| REQ-UI-8 (file structure) | WU-6, WU-7, WU-8, WU-9, WU-10 |
| REQ-UI-9 (frontend tests per dashboard) | WU-6.4, WU-7.4, WU-8.4, WU-9.4, WU-10.4 |
| REQ-D1 (Estado de Resultados) | WU-6 |
| REQ-D2 (Cobranza) | WU-7 |
| REQ-D3 (Tesorería) | WU-8 |
| REQ-D4 (Gastos) | WU-9 |
| REQ-D5 (Proyectos) | WU-10 |

---

## Review Workload Forecast

| Metric | Estimate |
|---|---|
| Backend new files | ~11 files (3 Services + 3 Controllers + 3 test files + 2 route edits + 5 seeder file edits) |
| Frontend new files | ~25 files (4 chart wrappers + index + 5×[Dashboard + Resource + schema + test]) |
| CSV edits | 3 files (~16 new rows) |
| Other edits | App.tsx, iconMap.ts, index.css, stack.md (.npmrc possibly) |
| **Estimated changed lines** | ~900–1100 lines |
| Chained PRs recommended | **No** — user explicitly chose `size:exception` single PR |
| Decision needed before apply | **No** — `size:exception` already in effect |
| Note | `size:exception` granted. PR will be large but fully traceable via work-unit commit boundaries. Reviewer should focus on tenant isolation (tenancy-reviewer) and minor-unit correctness (integration-reviewer). |

---

*Generated by sdd-tasks on 2026-06-16. Authority: spec #1184, design #1186, design.md, approved design doc.*
