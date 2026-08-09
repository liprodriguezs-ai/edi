# SDD Proposal — Tableros contables (accounting dashboards)

- **Change:** `tableros-contables`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** propose
- **Artifact store:** hybrid (this file + engram `sdd/tableros-contables/proposal`)
- **Date:** 2026-06-16
- **Source of truth:** formalizes the approved design doc `docs/superpowers/specs/2026-06-16-tableros-contables-design.md` (do not contradict it).

---

## 1. Intent

### Problem
The current `/dashboard` is mislabeled: it is the treasurer panel (`frontend/src/modules/treasurerPanel/components/TreasurerHome.tsx`) showing 4 KPIs, recent payments, bank accounts, and two **fake** charts drawn with CSS-width bars (`<i style="width:%">`). There are NO real charts in the product. The accounting domain modules (Collections, Treasury, Projects, Providers) already exist on disk with real models, migrations, and FIFO/aging logic — so there is genuine financial data with no analytical surface on top of it.

### Why now
The cluster Admin/Treasurer needs to read the financial state of the cluster (results, delinquency, cash flow, expenses, community-project funding) to make decisions. The data exists; the read layer does not. Building a real, themed, accessible charting surface now also corrects a documentation lie (`stack.md` claims Recharts 2.15.x is installed — it is NOT: `frontend/package.json` has no charting library and `frontend/src/components/ui/chart.tsx` does not exist; both confirmed during this phase).

### Success looks like
- 5 read-only accounting dashboards ("tableros"), each a separate sidebar route under a **"Tableros"** menu group, available to the cluster Admin/Treasurer.
- Each dashboard renders **real** charts (Recharts on shadcn's `chart` wrapper) that inherit the theme and dark mode, are accessible (tooltips/focus/legends), and are responsive.
- Each dashboard is fed by a **new backend aggregation endpoint** returning amounts in **bigInteger minor units**, strictly **tenant-scoped**, and gated by a per-dashboard read permission.
- A shared, reusable charting foundation (themed wrappers) so future dashboards don't reinvent charts.
- UI 100% in Spanish; zero `any` in TypeScript; money never rendered as float on the client.

---

## 2. Scope

### In scope
1. **Charting foundation (shared, built first):**
   - Install `recharts` in `frontend/` (corrects the false `stack.md` claim).
   - Add shadcn's `frontend/src/components/ui/chart.tsx` (`ChartContainer`, `ChartTooltip`, `ChartTooltipContent`, `ChartLegend`).
   - Create thin themed wrappers in `frontend/src/shared/components/charts/`: `LineBarComboChart`, `GroupedBarChart`, `DonutChart`, `AreaTrendChart` — all consuming pre-aggregated data and formatting money via `formatMinorUnits` (`frontend/src/shared/utils/money.ts`, verified to exist).
2. **The 5 dashboards (the deliverable units):**
   - Estado de resultados — `/tableros/resultados`
   - Cobranza / Morosidad (aging + top deudores) — `/tableros/cobranza`
   - Tesorería / Flujo de caja — `/tableros/tesoreria`
   - Gastos — `/tableros/gastos`
   - Proyectos comunitarios — `/tableros/proyectos`
   Each is a read-only page (no CRUD) following the `TreasurerHome` pattern: TanStack `useQuery` + a custom `Resource` method (NOT `useBaseList`), composing `KpiTile` + charts + tables.
3. **Backend aggregation endpoints (one per dashboard):** new non-CRUD endpoints returning aggregated JSON in minor units. Aggregation logic lives in a **Service** per owner module; the controller only orchestrates (project's Orion + Service SRP convention). Tenant-scoped via `initializeTenancy` and Eloquent (never the `DB::` facade). Cobranza reuses the existing `PaymentController::delinquency` logic (verified in `Modules/Collections`).
4. **Module registration (regla 3 de CLAUDE.md)** for all 5 dashboards so they appear in the sidebar and routes are protected: `modules.csv`, `permissions.csv`, `module_system.csv`, `SystemTableSeeder` (permission entity, route, icon, visibility), `RolePermissionTableSeeder`, and a `<PermissionRoute>` in `App.tsx`. New read-only permission entities: `report_pnl`, `report_collections`, `report_treasury`, `report_expenses`, `report_projects`. Run `php artisan db:seed --class=ResetPermissionTableSeeder`.
5. **Icons:** each module gets an icon from `frontend/src/shared/utils/iconMap.ts` (`TrendingUp`, `AlertTriangle`, `Wallet`, `Receipt`, `Target`).
6. **Mandatory UI states per dashboard:** loading (`boneyard-js` `<Skeleton>`, never `animate-pulse`), empty (clear message for freshly created clusters), error (`toast.error` from sileo + inline error), permission gating (`usePermissions`), responsive (charts via `ResponsiveContainer`, tables via `ResponsiveDataTable`).
7. **Testing:** backend feature test per aggregation endpoint (minor-units correctness, tenant scope, permission gate); frontend tests for the Zod schemas/Resources + render smoke test per dashboard with mock data.

### Out of scope (YAGNI for now)
- Cross-tenant Super Admin dashboard (subscriptions/plans) — separate effort if requested.
- Resident-facing view.
- PDF/Excel export of dashboards (the Reports module will own that).
- Budget-vs-actual (no budget data exists today).
- Advanced filters / year-over-year comparatives (future iteration).
- Replacing or refactoring the existing `/dashboard` treasurer panel (it stays; these are additive routes).

---

## 3. Approach (intent/scope altitude — implementation belongs to sdd-design)

### Deliverable units & sequencing
Single PR with `size:exception` (decision already cached), built in commitable work-units in this order:
1. **Charting foundation** — Recharts + `chart.tsx` + 4 themed wrappers. Built first because all 5 dashboards depend on it.
2. **Backend aggregation endpoints** — Services + controllers + routes + tests, grouped by owner module: Treasury (resultados, flujo, gastos), Collections (aging), Projects (funding).
3. **Module registration** — the 6-step seeder/icon/permission/route registration for all 5 dashboards + `ResetPermissionTableSeeder`.
4. **Frontend dashboards** — page + Resource + schema (typed, no `any`) per dashboard, wired into `App.tsx`.
5. **Review** — `/review-stack` (laravel-architecture, react-frontend, integration, tenancy, database).

### The 5 dashboards (endpoint + headline content; full payload contracts live in the design doc §5)
| Dashboard | Route | Permission entity | Icon | New endpoint |
|---|---|---|---|---|
| Estado de resultados | `/tableros/resultados` | `report_pnl` | `TrendingUp` | `GET /treasury/income-expense-series?months=12` |
| Cobranza / Morosidad | `/tableros/cobranza` | `report_collections` | `AlertTriangle` | `GET /collections/aging` |
| Tesorería / Flujo de caja | `/tableros/tesoreria` | `report_treasury` | `Wallet` | `GET /treasury/cashflow-series?months=12` |
| Gastos | `/tableros/gastos` | `report_expenses` | `Receipt` | `GET /treasury/expenses-breakdown?months=12` |
| Proyectos comunitarios | `/tableros/proyectos` | `report_projects` | `Target` | `GET /projects/funding-summary` |

### Rationale for key decisions
- **Recharts + shadcn `chart` wrapper:** MIT, declarative, React-native (fits the component pattern); the shadcn wrapper binds charts to theme CSS variables (Tailwind v4 + shadcn already present) for free dark-mode + palette; ships accessibility (satisfies the WCAG rule); and it's the original `stack.md` intent. Rejected: Tremor (imposes its own design system), Chart.js (canvas, non-idiomatic in React), Nivo/visx (overkill).
  > React 19 note: Recharts 2.x runs on React 19 but may need `--legacy-peer-deps`, or use Recharts 3.x; exact version fixed in sdd-design/sdd-apply. The shadcn wrapper is compatible with both.
- **Read-only, no CRUD:** dashboards are aggregations, so `useQuery` + custom `Resource` method, NOT `useBaseList`/`CatalogCrudModule`. Aggregation endpoints are custom (non-Orion-resource) routes returning `{ ... }` JSON in minor units.
- **Aggregation in Services:** per the backend skill (rule 7), business/aggregation logic lives in a module Service; controller orchestrates. Reuse existing relations (e.g. Collections delinquency) and `selectRaw`/`groupBy` to avoid N+1 — while remaining tenant-scoped through Eloquent models.
- **Money discipline:** all amounts stay in bigInteger minor units end-to-end; the client formats only for display via `formatMinorUnits`. No `decimal`/`float`.

### Tenancy correctness (hard requirement)
All aggregation queries MUST be tenant-scoped: single shared DB, row-level isolation via `tenant_id` + `TenantScope`. Endpoints run under `['auth:sanctum', 'initializeTenancy']`; queries go through tenant-scoped Eloquent models (`BelongsToTenant`), never the `DB::` facade (a raw query bypasses `TenantScope` → cross-tenant leak). `selectRaw`/`groupBy` aggregations must be expressed on a scoped Eloquent builder so the `where tenant_id = <current>` is preserved; reviewers must verify the generated SQL contains `tenant_id`. Time-bucketing by month uses the tenant timezone (`tenant.timezone`).

### Module-registration impact (regla 3)
5 sidebar entries ⇒ 5 module registrations. Per dashboard, all 6 backend steps + the `App.tsx` route. Permission entities are read-only reports; **open question deferred to sdd-design:** seed the conventional 6 rows per entity for seeder consistency, or only `read` (and optionally `report`). `account.owner`/`company.admin` inherit via `whereNotIn`; Treasurer/President/Oversight get the relevant board explicitly in `RolePermissionTableSeeder`.

---

## 4. Risks & open questions

- **Permission granularity (open, → sdd-design):** 6 rows per report entity (seeder-convention consistency) vs only `read`/`report` (functionally accurate for read-only). Affects `permissions.csv` and `RolePermissionTableSeeder`.
- **Recharts × React 19 peer-deps:** may require `--legacy-peer-deps` or Recharts 3.x; resolve at install time in apply. Low risk (shadcn wrapper supports both) but could surprise the build.
- **Tenant-leak risk in aggregations:** `selectRaw`/`groupBy` for performance must NOT drop the tenant scope. Must verify generated SQL carries `tenant_id`; mandatory tenancy-reviewer pass.
- **Single PR with `size:exception`:** 5 dashboards + foundation + backend + seeders is a large diff; conscious decision, but raises review burden. Mitigated by work-unit commits and the `/review-stack` pass.
- **Month bucketing & timezone:** grouping by month must use `tenant.timezone` to avoid off-by-one-month boundary bugs; default window 12 months (parameterizable).
- **Seeder side effects:** running `ResetPermissionTableSeeder` must not regress existing role/permission assignments — verify against current roles.
- **`stack.md` inaccuracy:** documentation claimed Recharts was installed; after this change, update `stack.md` to reflect the actual library/version chosen (doc-debt to close).

---

## 5. Success criteria
- 5 dashboard routes render under "Tableros" with real, themed, dark-mode-aware, accessible, responsive charts.
- Each dashboard is permission-gated and only reachable by authorized roles (Admin/Treasurer + inheritors).
- Every aggregation endpoint returns amounts in minor units and is provably tenant-scoped (SQL contains `tenant_id`; feature tests assert isolation).
- No `any` in any new TypeScript; UI strings all in Spanish; money formatted only via `formatMinorUnits`.
- Loading/empty/error states present on every dashboard.
- Backend feature tests + frontend schema/render tests pass; `/review-stack` (incl. tenancy + database) clean.
- `stack.md` corrected to reflect the actual charting library.

---

## 6. Next phases
- **sdd-spec** and **sdd-design** can run in parallel (both depend only on this proposal).
- sdd-design must close: permission-row granularity, exact Recharts version + peer-deps handling, and the per-endpoint aggregation query strategy (tenant-scoped `selectRaw`/`groupBy`, timezone bucketing).
