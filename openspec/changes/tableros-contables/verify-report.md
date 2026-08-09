# Verify Report — Tableros Contables

- **Change:** `tableros-contables`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** verify
- **Date:** 2026-06-17
- **TDD mode:** Standard (strict_tdd = FALSE)
- **Verdict:** **FAIL** — 1 CRITICAL, 4 WARNING, 3 SUGGESTION
- **Authority:** spec #1184, tasks #1191, design #1186, apply-progress #1192

---

## Executive summary

All real gates are GREEN (backend 32 tests / 120 assertions, frontend typecheck + 149 vitest tests + production build). **However, the gates encode a systemic frontend↔backend contract drift: 13 KPIs across 4 of the 5 dashboards render `0` or break at runtime against the real API** because the frontend reads a nested `data.totals.*` object with derived fields the backend never returns, and the Vitest tests mock the (wrong) frontend shape instead of the real backend payload. This is CRITICAL and blocks PR/archive.

---

## Gate results (actual output)

| Gate | Command | Result |
|---|---|---|
| Backend tests | `php artisan test --filter='Treasury\|Collections\|Projects ReportControllerTest'` | **32 passed, 120 assertions, 90.78s — GREEN** |
| FE typecheck | `pnpm typecheck` (`tsc -p tsconfig.app.json --noEmit`) | **PASS, 0 errors** |
| FE tests | `pnpm test` (`vitest run`) | **149 passed / 21 files — GREEN** |
| FE build | `pnpm build` (Vite, Recharts 3.x) | **built in 3.55s — GREEN** |
| FE lint | `pnpm lint` | **6 errors + 23 warnings — ALL in pre-existing files NOT touched by this change.** `eslint src/modules/dashboards src/shared/components/charts --max-warnings=0` → **EXIT 0** (zero problems in change files). Repo baseline, not a regression. |

---

## CRITICAL (blocks PR)

### C1 — Contract drift: 13 KPIs across 4 dashboards render 0 / break at runtime

**Root cause:** the frontend schemas + dashboards read a `data.totals` object with DERIVED fields that the backend services never emit. The backend returns FLAT top-level fields and computes no derived values. Resources do a raw `apiClient.get` with NO transform. The Vitest tests mock payloads matching the FRONTEND schema, so they pass — the tests encode the bug rather than catching it.

| Dashboard | Frontend reads | Backend actually returns | Runtime effect |
|---|---|---|---|
| Resultados | `totals.margin_pct` | `totals` = {income_minor, expense_minor, net_minor} only; `margin_pct` absent from entire backend | "Margen neto" KPI always **0%** (spec REQ-D1 expects 33% for 600000/200000) |
| Tesorería | `data.totals.{total_balance_minor, account_count, current_month_inflow_minor, current_month_outflow_minor}` | FLAT `total_balance_minor` + `accounts[]` + `series[]`; **no `totals` object** | **All 4 KPIs = 0** (charts + account list OK — they read `data.series`/`data.accounts`) |
| Gastos | `data.totals.{total_minor, recurring_minor, recurring_pct, provider_count}` + `by_provider[].label` | FLAT `total_minor/recurring_minor/one_off_minor`; no `totals`/`recurring_pct`/`provider_count`; `by_provider[].name` (not `label`) | **All 4 KPIs = 0** + provider chart labels broken |
| Cobranza | `data.totals.{total_outstanding_minor, collection_rate_pct, units_with_debt, avg_days_overdue}` + `data.buckets` | FLAT `total_outstanding_minor/collection_rate_pct/units_with_debt`; no `totals`/`avg_days_overdue`; aging under key `aging` (not `buckets`) | **All 4 KPIs = 0** + aging bar chart EMPTY |
| Proyectos | `data.totals.{active_count, goal_minor, raised_minor, pct}` | Real `totals` object with exactly these fields | **CORRECT** — the only fully-working dashboard |

**Fix:** choose ONE contract and align both sides.
- Option A (recommended, smaller backend churn): frontend Resources map the flat backend payload into the nested schema shape and compute the derived KPIs client-side (margin_pct, recurring_pct, provider_count, avg_days_overdue, current_month_inflow/outflow from `series` last entry, total_balance from `accounts`).
- Option B: backend services emit the nested `totals` + derived fields the frontend expects, and update backend feature tests to assert them.
- Also: `by_provider.label`→`name`, `data.buckets`→`data.aging`.
- **Rewrite the frontend Vitest tests to use the REAL backend payload shapes** so they catch contract drift instead of hiding it.

---

## WARNING

- **W1 — Recharts 3.8.0 installed; design DECISION 2 chose 2.15.x and explicitly REJECTED 3.x** (shadcn `chart.tsx` targets Recharts 2.x). Build/typecheck/tests pass on 3.x so it functions, but it deviates from the approved design.
- **W2 — `stack.md` falsely claims "Recharts 2.15.x (installed)"** — actual is 3.8.0. The WU-1.1.4 doc-debt fix recorded the wrong version.
- **W3 — `tasks.md` checkboxes for WU-2..WU-5, WU-9, WU-10, WU-11 remain unchecked `[ ]`** while engram apply-progress #1192 marks all complete. State/checkbox divergence; the code IS present.
- **W4 — `pnpm lint` fails at repo level (6 pre-existing errors).** Not caused by this change, but the WU-11.2.5 gate ("pnpm lint, zero errors") cannot pass as written until the baseline is fixed or the gate is scoped to changed files.

## SUGGESTION

- **S1** — Cobranza "Antigüedad promedio" and Tesorería current-month KPIs are derivable from the existing payload (aging weighting / `series` last entry); compute in the Resource/dashboard to minimize backend changes when fixing C1.
- **S2** — Backend test files live in central `backend/tests/Feature/{Module}/`, not `Modules/{Module}/tests/Feature/`. Works, but inconsistent with module-local convention.
- **S3** — chart chunk 352kB / index 846kB (>500kB Vite warning). Consider code-splitting the dashboards route group.

---

## What passed (verified against code, not checkboxes)

- **Backend HARD rules:** money in minor-unit integers everywhere (`CAST AS BIGINT` / `getRawOriginal` / `getAttributes`); NO decimal/float; NO `DB::` facade as query source (only `DB::raw` inside scoped `sum`/`selectRaw`); all aggregations enter via `BelongsToTenant` models (Payment/Expense/BankAccount/Charge/CommunityProject) → `TenantScope` injects `tenant_id`; controllers extend `QCH\Core\...\Controller` + `authorize('read.report_*')`; `months` validated `min:1 max:24` default 12; middleware `['auth:sanctum','rejectRefreshToken','authActive','initializeTenancy']`.
- **Tenant isolation:** feature tests seed 2 tenants and assert tenant-B data absent for all 5 endpoints. Green.
- **Frontend HARD rules:** zero `any` in dashboards + charts; boneyard-js `Skeleton` (no `animate-pulse`); sileo `toast` from `@/shared/hooks/useToast`; `usePermissions` gating per dashboard; `ResponsiveContainer`/`ResponsiveDataTable`; Spanish UI; no barrel files; `formatMinorUnits` on all money.
- **Module registration:** modules.csv (5), permissions.csv (10 = read+report only per ADR-1), module_system.csv (5 on system `b266838b…` under "Tableros"/IconChartBar), SystemTableSeeder (entities/routes/icons), RolePermissionTableSeeder (report perms in `$commonPermissions` NOT `$permissionsOnlySuperAdmin` → account.owner/company.admin inherit via `whereNotIn`; explicit grants for president/treasurer/oversight_committee; none for resident/renter).
- **Icons:** 5 Tabler names registered in `iconMap.ts` as imports + map entries — matches design's Lucide→Tabler correction.
- **App.tsx:** 5 lazy imports + 5 `PermissionRoute` blocks with correct `permissionEntity`.
- **chart.tsx:** exports ChartContainer/ChartTooltip/ChartTooltipContent/ChartLegend/ChartLegendContent/ChartStyle; `--chart-N` theme tokens present.

---

## Next recommended

`sdd-apply` to fix **C1** (align FE↔BE contracts; rewrite FE tests against real payloads). Optionally address W1/W2 (Recharts version + stack.md). Then re-run `sdd-verify`. Do NOT archive until C1 is resolved.

---

*Generated by sdd-verify on 2026-06-17. Authority: spec #1184, tasks #1191, design #1186, apply-progress #1192.*
