# Charting Foundation Specification

## Purpose

Establishes the shared, reusable chart infrastructure that all 5 accounting dashboards depend on. This is a prerequisite to every dashboard — it MUST be built first.

## Requirements

### Requirement: Recharts Installation

The project MUST have `recharts` installed as a dependency in `frontend/package.json`. The version selected MUST be compatible with React 19; if Recharts 2.x requires `--legacy-peer-deps`, Recharts 3.x MUST be chosen instead. After installation, `stack.md` MUST be updated to reflect the actual library and version installed.

#### Scenario: Library resolves without peer-dep errors

- GIVEN `frontend/package.json` does not contain `recharts`
- WHEN `recharts` is installed with the chosen strategy
- THEN `pnpm install` completes with zero peer-dependency errors
- AND `pnpm build` produces a valid bundle

#### Scenario: stack.md corrected

- GIVEN `stack.md` contains an inaccurate Recharts entry
- WHEN the installation is complete
- THEN `stack.md` reflects the actual installed library name and version

---

### Requirement: shadcn chart wrapper

The project MUST include `frontend/src/components/ui/chart.tsx` providing `ChartContainer`, `ChartTooltip`, `ChartTooltipContent`, and `ChartLegend`. This wrapper MUST bind chart colors to the existing shadcn/Tailwind CSS variables so charts inherit the active theme and dark mode automatically.

#### Scenario: Chart inherits theme colors

- GIVEN the application is rendered in dark mode
- WHEN a chart component using `ChartContainer` is displayed
- THEN chart colors and tooltip colors match the active shadcn theme CSS variables
- AND no hardcoded color strings appear in any chart component

---

### Requirement: Four themed chart wrappers

The project MUST provide four thin, reusable, pre-themed chart wrappers in `frontend/src/shared/components/charts/`:

| Wrapper | Type | Primary use |
|---|---|---|
| `LineBarComboChart` | Bars + overlay line | Income/expense + net |
| `GroupedBarChart` | Grouped bars | Aging buckets, comparisons |
| `DonutChart` | Donut/pie | Category/status distribution |
| `AreaTrendChart` | Area line | Balance evolution, trend |

Each wrapper MUST:
- Accept pre-aggregated data (no data fetching inside the component)
- Format all monetary values exclusively via `formatMinorUnits` from `frontend/src/shared/utils/money.ts`
- MUST NOT perform any float arithmetic or direct number display of minor-unit values
- Use `ResponsiveContainer` from Recharts so charts resize correctly on all viewport sizes
- Expose axis labels, tooltips, and legends in Spanish
- Carry no TypeScript `any` type

#### Scenario: Wrapper renders on mobile viewport

- GIVEN a `GroupedBarChart` with 12 monthly data points
- WHEN rendered at 375 px viewport width
- THEN the chart is visible and not clipped, tooltip is reachable, and no horizontal scrollbar appears

#### Scenario: Money formatting enforced

- GIVEN a `DonutChart` receiving `amount_minor: 150000`
- WHEN the tooltip is shown
- THEN the displayed value is `$1,500.00` (or locale equivalent) — never `150000`

#### Scenario: No TypeScript any

- GIVEN any of the four wrapper files are inspected
- WHEN TypeScript strict compilation runs
- THEN zero `any` types are present

