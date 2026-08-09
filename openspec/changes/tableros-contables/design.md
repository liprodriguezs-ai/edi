# SDD Design — Tableros contables (accounting dashboards)

- **Change:** `tableros-contables`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** design (the HOW at architectural level)
- **Artifact store:** hybrid (this file + engram `sdd/tableros-contables/design`)
- **Date:** 2026-06-16
- **Reads:** proposal (engram #1183 + `openspec/changes/tableros-contables/proposal.md`), approved design doc `docs/superpowers/specs/2026-06-16-tableros-contables-design.md`, spec deltas under `openspec/changes/tableros-contables/specs/`.
- **Authority order on conflict:** laravel-backend-architecture / stancl-tenancy / react-frontend-architecture skills > `CLAUDE.md` > this design. This design does NOT restate skill rules; it applies them.

This design closes the 3 open decisions left by propose/spec, grounded in the LIVE codebase (every file referenced below was read this phase). It is the architectural HOW; the WHAT-to-do steps belong to sdd-tasks.

---

## 0. Executive summary

5 read-only accounting dashboards, each a sidebar route under a **"Tableros"** menu group, each backed by ONE new tenant-scoped aggregation endpoint returning bigInteger minor units. Aggregation lives in 3 new module Services (Treasury / Collections / Projects), invoked by thin custom (non-Orion) controller actions registered under `['auth:sanctum', 'initializeTenancy']`. Frontend builds a shared Recharts-on-shadcn `chart` foundation + 4 themed wrappers, then 5 read-only pages following the `TreasurerHome` (`useQuery` + custom `Resource`) pattern. Charts library: **Recharts 2.15.x** with `--legacy-peer-deps`. Permission model: **`read` + `report` only** per report entity (no full CRUD-6), aligned with the menu/seeder tooling.

---

## 1. Architecture approach

### 1.1 Pattern & layering (no new architecture invented)

This change rides EXISTING, proven seams. No new patterns:

- **Backend aggregation** = the `PublicSummaryController` + `PaymentController::delinquency` seam: a custom (non-Orion-resource) route → controller action that authorizes + delegates to a Service → `response()->json([...])`. New: the aggregation SQL is extracted into dedicated `*ReportService` classes (SRP, laravel-backend-architecture rule 7 + spec "Aggregation logic lives in Service classes").
- **Frontend dashboard** = the `TreasurerHome` seam: `useQuery` (TanStack) + a `createCrudApi` Resource with `customMethods` (like `ExpenseResource.publicSummary` / `PaymentResource.delinquency`), composing `KpiTile` + charts + `ResponsiveDataTable`. NOT `useBaseList`/`CatalogCrudModule` (those are for paginated CRUD lists, not aggregations).
- **Charting** = a thin new shadcn `components/ui/chart.tsx` atom + 4 themed organism wrappers in `shared/components/charts/`. Recharts is wrapped, never imported directly by dashboard pages.

### 1.2 Module boundaries (who owns what)

| Concern | Owner module | New file(s) |
|---|---|---|
| P&L series, cashflow series, expenses breakdown | **Treasury** | `TreasuryReportService`, `TreasuryReportController` |
| Aging / morosidad | **Collections** | `CollectionsReportService`, `CollectionsReportController` |
| Project funding | **Projects** | `ProjectsReportService`, `ProjectsReportController` |
| Module registration (sidebar + permissions) | seeders (root `database/seeders/`) | edits to 5 files + CSVs |
| Chart foundation + 5 pages | **frontend** | `components/ui/chart.tsx`, `shared/components/charts/*`, `modules/dashboards/*` |

Treasury owns 3 of the 5 endpoints because income (Payment) + expenses (Expense) + accounts (BankAccount) all converge there, and `PublicSummaryController` already proves Treasury reaching into `Modules\Collections\Models\Payment` for income. We keep that precedent rather than splitting P&L across two modules.

### 1.3 Data-flow (one dashboard, end to end)

```
[Dashboard page] --useQuery--> [Resource.customMethod] --GET--> route (auth:sanctum, initializeTenancy)
   -> Controller action: $this->authorize('read.<entity>') -> app(ReportService)->method($months)
      -> tenant-scoped Eloquent aggregation (TenantScope auto-applies WHERE tenant_id=?)
   -> response()->json([... minor-unit ints ...])
<- JSON -> Zod-typed schema -> themed chart wrapper -> formatMinorUnits() on axes/tooltips
```

---

## 2. DECISION 1 — Permission-row granularity (CLOSED)

### 2.1 What the tooling actually requires (verified in the live seeders)

I read `modules.csv`, `permissions.csv`, `module_system.csv`, `SystemTableSeeder.php`, `RolePermissionTableSeeder.php`, and `MenuController.php`. The chain works like this:

1. **`MenuController` visibility filter (the only thing that gates the sidebar item).** For a module whose `permission_entity` is a plain entity (no `verb:` prefix), `MenuController` shows it when the user has **`read.<entity>` OR `report.<entity>`** (lines 95–96). For a `verb:entity` form it requires exactly `verb.entity`. So a read-only dashboard only needs `read.<entity>` (or `report.<entity>`) to exist AND be granted — `create/update/delete/restore` are NEVER consulted for menu visibility.
2. **`SystemTableSeeder::seedModulePermissionEntities()`** maps the module `name` → `permission_entity`. For these dashboards we use the PLAIN entity form (`report_pnl`, etc.), NOT `update:report_pnl`, because there is no "manage" action to gate — visibility on `read`/`report` is exactly right.
3. **Backend authorization** (`$this->authorize('read.<entity>')` in the controller) consumes the SAME `read.<entity>` ability. Menu visibility and endpoint authorization therefore use the identical ability — no drift (this is the exact lesson encoded in the seeder comment for `'Morosidad' => 'payment'`).
4. **`RolePermissionTableSeeder`**: `account.owner` gets EVERY permission except `$permissionsOnlySuperAdmin` via `whereNotIn`; `company.admin` similarly via `whereNotIn` (its own exclude list). So both inherit any new `read.report_*` automatically the moment the rows exist. The 11 Kivo7 roles, however, are EXPLICIT allow-lists in `seedKivo7RolePermissions()` — they get a permission ONLY if it appears in their array. A new `read.report_pnl` will NOT reach Treasurer/President/Oversight unless we add it.

### 2.2 Decision: seed **`read` + `report`** rows per report entity (NOT the conventional 6)

**Chosen:** 2 rows per entity — `read.<entity>` and `report.<entity>` — for all 5 entities (10 permission rows total).

Rationale:
- **Functionally honest.** These are read-only aggregations; `create/update/delete/restore` would be dead permissions that no route ever checks. Seeding them invites a reviewer/maintainer to wonder what mutates a "tablero" (nothing).
- **Does NOT fight the tooling.** `MenuController` only reads `read`/`report`; `whereNotIn` for owner/company-admin works on whatever rows exist (2 or 6, both fine); `seedKivo7RolePermissions` is an explicit allow-list, so fewer rows = fewer no-op grants. Nothing in the seeder pipeline assumes 6 rows exist for a given module.
- **`report.` matters semantically.** Including `report.<entity>` keeps the door open for the Reports module (PDF/Excel export, out of scope now) to gate export per dashboard without a new entity, and it satisfies the MenuController `report.` branch as a second visibility path.

> Precedent in the repo: most modules seed all 6, but several already seed extra/fewer rows for the same module id (`charge` has `discount.charge`, `cancel.charge`; `payment` has `create.payment_submission`; `subscription_plan` has `manage.subscription`). The 6-row pattern is a convenience, not a hard contract. Read-only report entities are the right place to deviate.

### 2.3 The 5 permission entities, menu group, routes, icons

| # | Dashboard | `permission_entity` | Frontend route | Backend authorize ability | Tabler icon (registry name) |
|---|---|---|---|---|---|
| 1 | Estado de resultados | `report_pnl` | `/tableros/resultados` | `read.report_pnl` | `IconTrendingUp` |
| 2 | Cobranza / Morosidad | `report_collections` | `/tableros/cobranza` | `read.report_collections` | `IconAlertTriangle` (already in iconMap) |
| 3 | Tesorería / Flujo de caja | `report_treasury` | `/tableros/tesoreria` | `read.report_treasury` | `IconWallet` |
| 4 | Gastos | `report_expenses` | `/tableros/gastos` | `read.report_expenses` | `IconReceipt` |
| 5 | Proyectos comunitarios | `report_projects` | `/tableros/proyectos` | `read.report_projects` | `IconTargetArrow` (already in iconMap) |

- **Menu group label:** `Tableros`, group icon `IconChartBar` (already imported in `iconMap.ts`). All 5 rows in `module_system.csv` carry `group_label=Tableros, group_icon=IconChartBar`, mounted on the tenant business system (`system_id = b266838b-4cb0-4eb1-aa76-242cf96df41a`, the same system Dashboard/Collections/etc. use). `sort_order` 5–9 (after the existing top-level `0–4`, before the `Cobranza` group at 10).

### 2.4 CORRECTION to the proposal/design-doc icon names (load-bearing)

The proposal/design-doc listed lucide names (`TrendingUp`, `Wallet`, `Receipt`, `Target`). **The sidebar does NOT use lucide for module icons** — it uses `@tabler/icons-react` via the CURATED `iconMap.ts` registry, and `getIcon()` falls back to `IconCircle` for any unregistered name. Therefore:

- Module `icon` values seeded in `SystemTableSeeder::seedModuleIcons()` MUST be Tabler `Icon*` names.
- `IconAlertTriangle`, `IconTargetArrow`, `IconChartBar` are ALREADY registered in `iconMap.ts`. **`IconTrendingUp`, `IconWallet`, `IconReceipt` are NOT registered** — the design REQUIRES adding these 3 imports + map entries to `frontend/src/shared/utils/iconMap.ts`, or the new sidebar items render a blank circle.

### 2.5 Role grants in `RolePermissionTableSeeder`

- **No change needed for `account.owner` / `company.admin` / `administrator`** — they receive `read.report_*`/`report.report_*` automatically (whereNotIn / `administrator` = "all non-exclusive"). These entities are NOT added to `$permissionsOnlySuperAdmin`.
- **Explicit grants in `seedKivo7RolePermissions()`** (read-only verbs): add the 5 entities to the relevant role arrays:
  - `president`, `treasurer`, `oversight_committee` → all 5 (`read` + `report`). These are the finance-oversight roles the proposal names.
  - `secretary`, `vocal` → none (they are resident-level on finance today; do not widen).
  - Optionally add the 5 entities to the shared `$catalogs` read-only list ONLY IF every operational role should see them — we do NOT, to keep security/operations_staff/resident/renter out of finance tableros. Explicit per-role is safer and matches how `collections`/`expense` are already scoped.
- Run `php artisan db:seed --class=ResetPermissionTableSeeder` after editing (per CLAUDE.md regla 3). Reviewer must confirm no regression to existing role rows (the seeder TRUNCATEs `role_has_permissions` then rebuilds — additive arrays only).

---

## 3. DECISION 2 — Recharts version + React 19 peer-deps (CLOSED)

### 3.1 Facts verified this phase

- `frontend/package.json`: `react` / `react-dom` are `^19.2.0`; `@types/react` `^19.2.7`. NO charting library present. NO `frontend/src/components/ui/chart.tsx`. (Confirms the `stack.md` claim that "Recharts 2.15.x is installed" is FALSE — doc-debt to fix after install.)
- Package manager is **pnpm** (workspaces); shadcn CLI `^3.8.5` is a devDependency.

### 3.2 Decision: **Recharts 2.15.x** + `--legacy-peer-deps`

Reasoning:
- The shadcn `chart` component (`components/ui/chart.tsx`) is authored and documented against **Recharts 2.x**. Pinning 2.15.x is the lowest-risk path: the shadcn snippet drops in unmodified, and 2.x is battle-tested.
- Recharts 2.x declares a React `^16–18` peerRange; with React 19 that triggers a peer-dep warning. `--legacy-peer-deps` (npm) / pnpm's peer behavior resolves it. Recharts 2.15.x runs correctly on React 19 at runtime (the peer range is conservative metadata, not an actual incompatibility).
- Recharts 3.x adds React 19 to its peer range (cleaner install) BUT changes internal APIs the current shadcn `chart` snippet predates; adopting 3.x would mean validating/patching the shadcn wrapper. That is avoidable scope for zero user-visible benefit here. **Rejected for this change.**

### 3.3 Exact install + shadcn add steps (pnpm)

```bash
# from frontend/
pnpm add recharts@^2.15.0 --config.strict-peer-dependencies=false
# add shadcn's chart primitive (writes src/components/ui/chart.tsx)
pnpm dlx shadcn@latest add chart
```

Notes for apply:
- pnpm equivalent of npm `--legacy-peer-deps` is `--config.strict-peer-dependencies=false` (or add `recharts` to `pnpm.peerDependencyRules.allowedVersions` / set `strict-peer-dependencies=false` in `.npmrc`). If the repo `.npmrc` already disables strict peers, the plain `pnpm add recharts@^2.15.0` suffices.
- `shadcn add chart` may also pull its tooltip/legend deps; verify it only added `chart.tsx` (and did not overwrite existing `components/ui/*`). It must NOT touch other shadcn atoms.
- After install, **update `stack.md`** to record `recharts 2.15.x (React 19 via non-strict peer)` (closes the documentation-lie risk).

### 3.4 Compatibility guardrails

- `chart.tsx` binds series colors to CSS variables (`--chart-1..5`); confirm those tokens exist in `index.css`/theme. If absent, add a `@theme` block (Tailwind v4 CSS-first) defining `--chart-1..5` so dark mode inherits automatically. (Design-doc §3 explicitly relies on theme CSS vars.)
- Build gate: `pnpm build` (`tsc -b && vite build`) must pass with React 19 + Recharts 2.15.x before the charts are considered done.

---

## 4. DECISION 3 — Per-endpoint tenant-scoped aggregation query strategy (CLOSED)

### 4.1 The tenancy contract for aggregations (the non-negotiable)

Single shared DB, row-level isolation via `tenant_id` + `TenantScope` (from `BelongsToTenant`). Every model touched here carries `BelongsToTenant` (verified: `Payment`, `Expense`, `BankAccount`, `Charge`, `CommunityProject`). Therefore:

- **Entry point is always a scoped Eloquent model query** (`Payment::query()`, `Expense::query()`, …). The `TenantScope` injects `WHERE tenant_id = <current>` automatically. The endpoints run under `initializeTenancy`, so the current tenant is set.
- **`DB::` facade is forbidden as a query entry point** (laravel-backend-architecture rule 2, stancl-tenancy §4) — it bypasses `TenantScope` → cross-tenant leak.
- **`DB::raw(...)` INSIDE an Eloquent aggregate is allowed and is the proven repo pattern.** `PublicSummaryController` does `Payment::query()->...->sum(DB::raw('CAST(total_amount AS BIGINT)'))` and `->selectRaw('expense_category_id, SUM(CAST(amount AS BIGINT)) as total')->groupBy(...)`. The builder is still the scoped Eloquent builder, so the generated SQL keeps `tenant_id`. We reuse exactly this. (The prohibition is on `DB::table(...)` / `DB::select(...)` as the query SOURCE, not on `DB::raw` expressions layered onto a scoped Eloquent builder.)
- **Money is stored as bigint minor units** but cast via `MoneyIntegerCast`. When aggregating in SQL, cast the raw column (`CAST(col AS BIGINT)`) to sum the integer minor units directly (the proven pattern). When summing in PHP over a loaded collection (the delinquency path), read the RAW attribute: `(int) $charge->getAttributes()['expected_amount']` — NOT the cast Money object. Both patterns already exist in the codebase; pick per endpoint as noted below.

### 4.2 Month bucketing with `tenant.timezone` (shared helper)

The spec requires month buckets computed in the tenant's timezone. `Tenant->timezone` exists (default `America/Mexico_City`). Strategy:

- Compute the bucket WINDOW in PHP using Carbon in the tenant tz, then filter/aggregate per bucket. Concretely, a private helper in each `*ReportService` (or a shared trait `BucketsByTenantMonth`) builds an ordered list of `N` month buckets: for `i` in `0..N-1`, `start = now($tz)->startOfMonth()->subMonths(N-1-i)`, `end = start->copy()->endOfMonth()`; each bucket carries `year`, `month`, `label` (Spanish "Mmm YYYY", e.g. "Jun 2026").
- **Avoid `whereMonth`/`whereYear` for the series** (they evaluate in the DB session tz, risking off-by-one at month boundaries for non-UTC tenants). Instead, convert each bucket's `[start,end]` to the storage tz and use `whereBetween('paid_at', [$startUtc, $endUtc])`. `paid_at` is cast `date`/`date:Y-m-d` (no time component on Expense; Payment `date`), so day-granular `whereBetween` on the bucket's first/last day is exact and tz-stable. This is a deliberate improvement over `PublicSummaryController`'s single-month `whereYear/whereMonth` (acceptable there for a single current month; not for a 12-bucket series).
- `months` query param: validate `integer, min:1, max:24`, default 12 (inline validation in the controller action per laravel-backend-architecture §2.10 — custom actions validate inline, no FormRequest).

> N+1 avoidance for the series: do NOT run one COUNT/SUM query per bucket if it can be one grouped query. Where feasible, run a single grouped aggregate keyed by `(year-month in tenant tz)` and then map results onto the pre-built bucket list (missing buckets → 0). For Postgres, `selectRaw("to_char((paid_at AT TIME ZONE 'UTC' AT TIME ZONE ?), 'YYYY-MM') as ym, SUM(CAST(amount AS BIGINT)) as total", [$tz])->groupBy('ym')` on the scoped builder gives one query for all buckets, tz-correct, tenant-scoped. Fall back to per-bucket `whereBetween` only if the grouped expression complicates the SQLite test path (see §6).

### 4.3 Endpoint-by-endpoint design

All controllers extend the project base where they need `$this->authorize(...)`. The aggregation controllers are CUSTOM (non-Orion) — they may extend `Illuminate\Routing\Controller` like `PublicSummaryController`, but to reuse `$this->authorize()` cleanly they SHOULD extend `QCH\Core\Http\Controllers\Controller` with `$model` + `$keyForPermission` unset for resource routes (we register only custom `Route::get`, so no Orion resource is generated). Decision: extend `QCH\Core\Http\Controllers\Controller`, call `$this->authorize('read.report_*', <OwnerModel>::class)` exactly as `PaymentController::delinquency` does (`$this->authorize('read.payment', Payment::class)`). Each action: authorize → delegate to service → `response()->json($payload)`.

#### 4.3.1 `GET /treasury/income-expense-series?months=12` → `TreasuryReportService::incomeExpenseSeries(int $months)`

- **Income per bucket:** `Payment::query()->where('payment_status_id', PaymentStatus::CONFIRMED)` aggregated by month (grouped tz query or per-bucket `whereBetween('paid_at', ...)`), `SUM(CAST(total_amount AS BIGINT))`.
- **Expense per bucket:** `Expense::query()->where('expense_status_id', '!=', ExpenseStatus::VOIDED)` aggregated by month on `paid_at`, `SUM(CAST(amount AS BIGINT))`.
- Map both onto the pre-built bucket list; `net_minor = income_minor - expense_minor`; `totals` = column sums.
- Both queries are tenant-scoped (Payment & Expense carry `BelongsToTenant`). Income reaching across to Collections\Payment is the established `PublicSummaryController` precedent.
- Route lives in `Modules/Treasury/routes/api.php` under `$customActionMiddleware` (`['auth:sanctum','rejectRefreshToken','authActive','initializeTenancy']`, matching the existing `treasury/public-summary` and `expense/{id}/void` lines).

#### 4.3.2 `GET /collections/aging` → `CollectionsReportService::aging()`

- **Reuse `PaymentController::delinquency` logic.** Same source set: `Charge::query()->whereIn('charge_status_id', [ChargeStatus::OVERDUE, ChargeStatus::PARTIAL])->where('is_cancelled', false)->with(['unit','chargeStatus'])->get()`. Outstanding per charge = `max(0, expected − discount − paid)` read from RAW attributes (the delinquency pattern; do NOT use the cast Money object).
- **Aging buckets** by `due_date` vs today (tenant tz `now()`): `current` (not yet due), `d1_30`, `d31_60`, `d61_90`, `d90_plus`. Computed in PHP over the loaded collection (the set is bounded — only overdue/partial charges — so loading + PHP grouping is acceptable and mirrors the existing endpoint; no N+1 because `unit` and `chargeStatus` are eager-loaded in ONE `with()`).
- **`by_status`:** group the same collection by `chargeStatus` → `{ status_key, label (status->name), count, amount_minor }`.
- **`top_debtors`:** the delinquency `byUnit` map, capped to 10, ordered by `outstanding_minor` desc; `unit_label` from `unit->full_identifier ?? unit->number`.
- **`collection_rate_pct`:** integer 0–100 = charges paid this month / charges issued this month × 100. "Issued this month" = `Charge::query()->whereBetween('issued_at'|'due_date', [monthStart,monthEnd])`; "paid" = those whose `charge_status` is completed/overpaid (or `paid_amount >= expected−discount`). Compute via 2 scoped aggregate queries (count + conditional count), not per-row. Guard divide-by-zero → 0.
- Route lives in `Modules/Collections/routes/api.php` under the existing `$customActionMiddleware` group (next to `Route::get('delinquency', ...)`), as `Route::get('collections/aging', [CollectionsReportController::class, 'aging'])`. Authorize `read.report_collections`.

> N+1 note: the only "load then group in PHP" is the bounded overdue/partial set with eager-loaded `unit`/`chargeStatus` (identical to today's delinquency). Everything else (collection_rate) is scoped aggregate queries.

#### 4.3.3 `GET /treasury/cashflow-series?months=12` → `TreasuryReportService::cashflowSeries(int $months)`

- **`accounts`:** `BankAccount::query()->where('is_active', true)->get(['id','name','current_balance'])` → `{ id, name, current_balance_minor: (int) getRawOriginal('current_balance') }` (read raw, the `PublicSummaryController` pattern). `total_balance_minor` = sum.
- **`series` per bucket:** `inflow_minor` = confirmed payments in month (same query as P&L income), `outflow_minor` = non-voided expenses in month (same as P&L expense). `running_balance_minor` = cumulative: start from `total_balance_minor − Σ(inflow−outflow over the whole window)` to get the balance at the window's start, then accumulate forward; OR (simpler, spec-compliant) compute `running` as cumulative `inflow−outflow` per the spec scenario (month1=40000, month2=80000 from net deltas). **Decision:** follow the spec scenario literally — `running_balance_minor[i] = Σ_{k≤i}(inflow_k − outflow_k)`. (This is a cumulative-net series, not the reconciled bank balance; documented as such in the schema comment to avoid a reviewer flagging it against `current_balance`.)
- Reuses the same scoped income/expense bucket aggregates as 4.3.1 — extract a shared private `monthlyInflow($months)` / `monthlyOutflow($months)` in `TreasuryReportService` to avoid duplication across P&L and cashflow.
- Route in `Modules/Treasury/routes/api.php`, custom group. Authorize `read.report_treasury`.

#### 4.3.4 `GET /treasury/expenses-breakdown?months=12` → `TreasuryReportService::expensesBreakdown(int $months)`

- Window = last `months` (same bucket helper); all sub-aggregates filter `expense_status_id != VOIDED` and `whereBetween('paid_at', [windowStart, windowEnd])`.
- **`by_category`:** `Expense::query()->selectRaw('expense_category_id, SUM(CAST(amount AS BIGINT)) as total')->groupBy('expense_category_id')->with('category:id,name')->get()` (the `PublicSummaryController` pattern exactly). `pct` = integer `round(total/grandTotal*100)`; guard 0 when grandTotal=0. `label` = `category->name ?? 'Sin categoría'`.
- **`by_provider`:** same shape grouped by `provider_id`, **`whereNotNull('provider_id')`** (spec: only provider-linked expenses), `with('provider:id,name')`. Order desc by amount.
- **`monthly`:** the per-bucket expense series (`{ label, amount_minor }`).
- **`recurring_minor`:** sum of expenses whose linked provider `is_recurring = true`. Use `whereHas('provider', fn($q)=>$q->where('is_recurring', true))` on the scoped Expense builder (keeps tenant scope; one query). `one_off_minor = total_minor − recurring_minor`.
- Route in `Modules/Treasury/routes/api.php`, custom group. Authorize `read.report_expenses`.

#### 4.3.5 `GET /projects/funding-summary` → `ProjectsReportService::fundingSummary()`

- **`projects`:** `CommunityProject::query()->with('status:id,name')->withSum('contributions as raised_minor', DB::raw('CAST(... AS BIGINT)'))` — but contribution amount column must be verified. Strategy: load projects with `status`, and compute `raised_minor` via `withSum('contributions', '<amount_col>')` (Eloquent `withSum` keeps the parent's TenantScope AND scopes children through the relation). If the contribution amount needs CAST, use the raw-attribute summation or `selectRaw` subquery. `goal_minor` = `(int) getRawOriginal('goal_amount')`. `pct` = `round(raised/goal*100)`, **0 when goal=0** (spec scenario). `status_key` = a stable key for the status row (status->name slug or a key column — see open item §7), `status_label` = `status->name` (Spanish display).
- **`totals`:** `active_count` (projects whose status is the "active" key), `goal_minor` Σ, `raised_minor` Σ, `pct` global.
- **`by_status`:** group projects by status → `{ status_key, label, count }`.
- N+1 avoidance: ONE `with('status')` + `withSum('contributions', ...)` — no per-project contribution query.
- Route lives in `Modules/Projects/routes/api.php` under the existing custom-action middleware group, as `Route::get('projects/funding-summary', [ProjectsReportController::class, 'fundingSummary'])`. Authorize `read.report_projects`.

### 4.4 FormRequest / validation / middleware summary

- **No FormRequests** — these are custom non-CRUD actions; validate `months` inline (`min:1,max:24`) per laravel-backend-architecture §2.10. The other endpoints take no body.
- **Middleware:** every route in the existing module `$customActionMiddleware` group → `['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']` (matches the sibling custom GETs `delinquency`, `unit/{id}/statement`, `treasury/public-summary`). Tenant scope guaranteed by `initializeTenancy`.
- **Authorization:** in-action `$this->authorize('read.report_*', <Model>::class)` (Sanctum `tokenCan` via the project base controller `authorize()`), mirroring `PaymentController::delinquency`.

---

## 5. Frontend design

### 5.1 Chart foundation (built first; all 5 pages depend on it)

- `frontend/src/components/ui/chart.tsx` — the shadcn `chart` primitive (`ChartContainer`, `ChartTooltip`, `ChartTooltipContent`, `ChartLegend`, `ChartLegendContent`, `ChartConfig`). Treated as a shadcn atom (do NOT hand-edit beyond what the CLI emits).
- `frontend/src/shared/components/charts/` — 4 thin themed wrappers, each typed (no `any`), each wrapping Recharts inside `ChartContainer` + `ResponsiveContainer`, formatting money via `formatMinorUnits` on axis ticks + tooltip values:
  - `LineBarComboChart.tsx` — `ComposedChart` (bars income/expense + line net).
  - `GroupedBarChart.tsx` — `BarChart` grouped (aging / inflow-outflow / raised-vs-goal / top providers).
  - `DonutChart.tsx` — `PieChart` `innerRadius` (by category / by status).
  - `AreaTrendChart.tsx` — `AreaChart` (running balance / expense trend).
- Each wrapper takes already-aggregated, typed data + a `ChartConfig` (series keys → label + `--chart-N` color). Colors come from theme CSS vars → dark mode + palette for free. Accessibility (tooltip/focus/legend) ships with the shadcn primitive (satisfies the WCAG rule).

### 5.2 Per-dashboard structure (5 modules)

```
frontend/src/modules/dashboards/<nombre>/
  <Nombre>Dashboard.tsx        # page: useQuery + KpiTile + chart wrappers + ResponsiveDataTable
  services/<Nombre>Resource.ts # createCrudApi({ basePath }) + customMethods GET-ting the endpoint
  schema.ts                    # typed payload interfaces (Zod schema mirroring spec §5; no any)
```

- **Data layer mirrors `ExpenseResource.publicSummary` / `PaymentResource.delinquency`:** `createCrudApi<TEntity, Record<string, unknown>, Record<string, unknown>, { fetch: (months?: number) => Promise<TPayload> }>({ basePath, customMethods })`. The `basePath` is nominal (e.g. `/treasury`); the customMethod GETs the real aggregation path and returns `data` (these endpoints return a flat object, NOT `{ data }`-wrapped, like `delinquency` — confirm per endpoint and type accordingly).
- **Page:** `useQuery({ queryKey: [...], queryFn })` exactly like `TreasurerHome`. Reuse `KpiTile`, `StatusPill`, `ResponsiveDataTable` (top deudores, account lists, project lists), `PageHeader`, `formatMinorUnits`, `displayFormatDate`. UI 100% Spanish.
- **Mandatory states (spec dashboard-ui-shared):** loading via `boneyard-js` `<Skeleton loading={isLoading}>` (NEVER `animate-pulse`); empty (clear Spanish message for a fresh cluster, e.g. "Aún no hay datos en el periodo"); error via `toast.error(...)` from `@/shared/hooks/useToast` (sileo) + inline error; permission gating via `usePermissions('read.report_*')`; responsive (charts in `ResponsiveContainer`, tables via `ResponsiveDataTable`).

### 5.3 Routing (App.tsx) + sidebar

- Add 5 lazy-imported pages and 5 routes, each wrapped in `<PermissionRoute permissionEntity="report_pnl" />` etc. (the same pattern as every existing route block):
  ```tsx
  <Route element={<PermissionRoute permissionEntity="report_pnl" />}>
    <Route path="/tableros/resultados" element={<EstadoResultadosDashboard />} />
  </Route>
  ```
  …and the analogous 4. `PermissionRoute` with `permissionEntity` checks `read.<entity>` client-side, matching `MenuController` and the backend authorize — one ability, three gates, no drift.
- The sidebar itself is data-driven from `GET /api/menu`, so NO manual `app-sidebar.tsx` edit is needed — the 5 modules appear automatically once the seeders register them with `visible=true`, the `Tableros` group, the Tabler icons, and the user holds `read.report_*`.

---

## 6. Testing approach (Standard Mode — strict_tdd is FALSE)

- **Backend (PHPUnit feature test per endpoint, `php artisan make:test --phpunit`):** seed representative data for tenant A + a second tenant B; authenticate as a tenant-A user holding `read.report_*`; assert (a) all monetary fields are integers in minor units (e.g. `150000`, not `1500.0`/`"$1,500.00"`), (b) tenant B's data does NOT appear, (c) the headline spec scenarios (`net_minor` math, `running_balance` cumulative, `top_debtors` capped at 10, `pct=0` when goal=0, aging bucket placement, `by_category pct` sums ~100). Reuse module factories. Watch the SQLite test path: if the Postgres `to_char(... AT TIME ZONE ...)` grouped expression is used, provide a driver-portable fallback (per-bucket `whereBetween`) so tests don't depend on Postgres-only SQL — OR run these tests on Postgres if the suite already does. Tests assert tenant scope by data absence (the practical proxy for "SQL contains tenant_id").
- **Frontend (Vitest + Testing Library):** (1) Zod schema/interface tests — parse a representative payload, assert types and minor-unit fields; (2) Resource test — mock `apiClient`, assert the customMethod hits the right path and maps the payload; (3) render smoke test per dashboard — mock the query, assert KPIs + chart wrappers render and money is formatted via `formatMinorUnits` (chart internals can be shallow-rendered; assert wrapper presence + KPI text). Tests live beside code or in `__tests__` per team convention.
- **Quality gates:** backend `vendor/bin/pint` + the targeted feature tests; frontend `pnpm lint` + `pnpm build` (tsc) + `pnpm test`. Final `/review-stack` (laravel-architecture, react-frontend, integration, tenancy, database).

---

## 7. Open items / assumptions for sdd-tasks & apply (not blockers)

- **`status_key` source for projects/charges in `by_status`.** Spec wants stable keys (`"active"`, `"overdue"`). Verify whether `community_project_status` / `charge_status` rows expose a `key`/slug column or whether to derive a key from the fixed UUID constants (e.g. map `CommunityProjectStatus::ACTIVE` → `'active'`). Apply must read the catalog models to pick the real source; do NOT invent a column.
- **Contribution amount column name** on `ProjectContribution` (for `raised_minor` `withSum`). Apply must open `ProjectContribution.php` to confirm the money column + whether a CAST is needed for the sum.
- **`collection_rate_pct` "issued this month" axis** — confirm whether to bucket by `issued_at` or `due_date`; pick the one the Collections team uses elsewhere (check `ChargeStatusService` / charge migration). Default to `due_date` if ambiguous (matches how morosidad reasons about periods).
- **`.npmrc` strict-peer setting** — apply should check if strict peers is already disabled before adding the flag.
- **`--chart-N` theme tokens** — apply must confirm/add them in the theme CSS.
- **`stack.md` correction** — update after install (doc-debt).

These are verification points for apply, deliberately left concrete-but-open because they require reading 2–3 more model files at implementation time; none changes the architecture above.

---

## 8. ADR-style decision log

### ADR-1: Read-only report permission entities seed `read`+`report` only (not full CRUD-6)
- **Status:** Accepted.
- **Context:** Module registration normally seeds 6 verbs; `MenuController` only consults `read`/`report` for plain entities; dashboards are read-only.
- **Decision:** Seed exactly `read.<entity>` + `report.<entity>` for the 5 report entities.
- **Consequences:** No dead permissions; menu visibility + backend authorize use the same `read.` ability; owner/company_admin inherit via `whereNotIn`; finance roles (president/treasurer/oversight) granted explicitly in `seedKivo7RolePermissions`.
- **Rejected:** Seed all 6 for "consistency" — creates 4 no-op rows per entity that no route checks; misleads maintainers.

### ADR-2: Recharts 2.15.x with non-strict peers (not Recharts 3.x)
- **Status:** Accepted.
- **Context:** React 19; no chart lib installed; shadcn `chart` snippet targets Recharts 2.x.
- **Decision:** `pnpm add recharts@^2.15.0` with strict-peer-deps disabled; `pnpm dlx shadcn add chart`.
- **Consequences:** Drop-in shadcn wrapper, lowest risk; one peer warning suppressed; `stack.md` updated to truth.
- **Rejected:** Recharts 3.x — cleaner peer range but would require validating/patching the shadcn wrapper for changed internals; unjustified scope.

### ADR-3: Aggregation via scoped Eloquent builders + `DB::raw` expressions (never `DB::` facade entry)
- **Status:** Accepted.
- **Context:** Single-DB row-level tenancy; `TenantScope` only applies through Eloquent models; money is bigint minor units behind `MoneyIntegerCast`.
- **Decision:** Enter every aggregation through a `BelongsToTenant` model query; use `selectRaw`/`groupBy`/`SUM(CAST(col AS BIGINT))`/`DB::raw` ON that scoped builder; read raw attributes when summing loaded collections. Reuse the proven `PublicSummaryController` + `delinquency` patterns. Bucket months by `tenant.timezone` via `whereBetween` windows (not `whereMonth/whereYear`) to avoid boundary off-by-one.
- **Consequences:** Generated SQL always carries `tenant_id`; no cross-tenant leak; minor-unit integers preserved; tz-correct series.
- **Rejected:** `DB::table(...)` raw aggregates (bypass TenantScope → leak); `whereMonth/whereYear` for multi-month series (tz-unsafe).

### ADR-4: Treasury owns 3 endpoints (P&L, cashflow, expenses); Collections owns aging; Projects owns funding
- **Status:** Accepted.
- **Context:** Income (Payment) + expenses (Expense) + accounts (BankAccount) converge in Treasury; `PublicSummaryController` already reaches Collections\Payment from Treasury.
- **Decision:** Keep that precedent; one `*ReportService` per owner module; shared `monthlyInflow/monthlyOutflow` private helpers inside `TreasuryReportService` to avoid duplication between P&L and cashflow.
- **Consequences:** Cohesive ownership, minimal cross-module reach (only the established Payment read), no duplicated month-series SQL.

### ADR-5: Module icons are Tabler names registered in `iconMap.ts` (correcting the lucide names in the proposal)
- **Status:** Accepted.
- **Context:** Sidebar renders `getIcon(module.icon)` from the curated Tabler `iconMap.ts`; unregistered names fall back to `IconCircle`. Proposal listed lucide names.
- **Decision:** Use `IconTrendingUp`, `IconAlertTriangle`, `IconWallet`, `IconReceipt`, `IconTargetArrow`; group icon `IconChartBar`. Add the 3 unregistered (`IconTrendingUp`, `IconWallet`, `IconReceipt`) to `iconMap.ts` imports + map.
- **Consequences:** Icons render correctly; no blank circles. Lucide stays for in-page component icons (where pages import it directly, as `TreasurerHome` does).
