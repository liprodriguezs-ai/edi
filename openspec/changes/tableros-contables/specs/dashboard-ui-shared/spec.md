# Dashboard UI — Shared Requirements Specification

## Purpose

Defines requirements that apply to ALL 5 dashboards. Dashboard-specific KPI/chart requirements are in their own spec files.

## Requirements

### Requirement: Read-only data pattern

Every dashboard page MUST use TanStack `useQuery` with a dedicated Resource class (one per dashboard), NOT `useBaseList` or any CRUD store. The Resource class MUST call the corresponding aggregation endpoint. The component MUST NOT contain any mutation logic.

#### Scenario: Dashboard fetches data on mount

- GIVEN a user with the required permission navigates to any dashboard route
- WHEN the component mounts
- THEN exactly one GET request is dispatched to the corresponding aggregation endpoint
- AND no POST/PATCH/DELETE requests are dispatched

---

### Requirement: Loading state

Every dashboard MUST show loading skeletons while data is being fetched. Skeletons MUST use `boneyard-js` (`<Skeleton loading={isLoading}>`). The `animate-pulse` Tailwind class MUST NOT be used for loading states.

#### Scenario: Skeleton shown during fetch

- GIVEN a dashboard is mounted and the network request is pending
- WHEN the component renders
- THEN boneyard-js skeleton elements are visible
- AND no chart or KPI values are rendered yet

---

### Requirement: Empty state

Every dashboard MUST display a clear, Spanish-language empty state message when the aggregation endpoint returns data with no series entries or empty collections (e.g., a freshly created cluster with no financial activity).

#### Scenario: Empty state for new cluster

- GIVEN a tenant with no charges, payments, or expenses
- WHEN any dashboard is opened
- THEN an empty state message is shown (not a blank screen or broken chart)

---

### Requirement: Error state

Every dashboard MUST handle API errors by:
1. Displaying an inline error message in the page body.
2. Calling `toast.error(message)` from `@/shared/hooks/useToast` (sileo). The `useToast` hook from shadcn MUST NOT be used. The `toast({ title, variant })` pattern MUST NOT be used.

#### Scenario: API error shows toast and inline message

- GIVEN the aggregation endpoint returns HTTP 500
- WHEN the dashboard renders
- THEN `toast.error(...)` is called once with a human-readable Spanish message
- AND an inline error indicator is visible within the dashboard

---

### Requirement: Permission gating

Every dashboard MUST import and use `usePermissions` to verify that the current user holds the corresponding `report_*` permission before rendering sensitive financial data. If the user lacks the permission, the component MUST render nothing or a permission-denied message — not the financial data.

#### Scenario: Unauthorized user sees no financial data

- GIVEN a user without `report_treasury` permission on the Treasury dashboard
- WHEN the component renders
- THEN no financial figures, charts, or tables are rendered

---

### Requirement: Responsive layout

Every dashboard MUST be usable on mobile (≥ 375 px), tablet (≥ 768 px), and desktop (≥ 1280 px). Charts MUST use `ResponsiveContainer` from Recharts. Tabular data sections (e.g., top debtors) MUST use `ResponsiveDataTable`. No content MUST require horizontal scrolling.

#### Scenario: Mobile viewport — no horizontal scroll

- GIVEN a dashboard is rendered at 375 px viewport width
- WHEN all sections are visible
- THEN no horizontal scrollbar is present and all KPIs, charts, and tables are readable

---

### Requirement: UI in Spanish, no TypeScript any

Every user-facing string in every dashboard MUST be in Spanish (labels, placeholders, empty messages, error messages, axis labels, tooltip content). No TypeScript `any` type MUST appear in any dashboard module file (page, schema, resource, wrapper).

#### Scenario: TypeScript compilation passes strict check

- GIVEN any dashboard module file
- WHEN TypeScript compiles with `strict: true`
- THEN zero `any` types are present

---

### Requirement: File structure per dashboard

Each dashboard MUST follow this module structure under `frontend/src/modules/dashboards/<nombre>/`:

```
<Nombre>Dashboard.tsx          — page component (composes KpiTile + charts + tables)
services/<Nombre>Resource.ts   — GET to aggregation endpoint, typed return
schema.ts                      — TypeScript interfaces for the payload (no any)
```

The `KpiTile` component from `frontend/src/modules/treasurerPanel/components/KpiTile.tsx` MUST be reused for KPI display. No duplicate KPI tile component MUST be created.

---

### Requirement: Frontend tests per dashboard

Each dashboard MUST have:
- A schema/interface test verifying that the TypeScript interface matches the endpoint's JSON shape.
- A render smoke test using Vitest + Testing Library that mounts the dashboard with mocked `useQuery` data and asserts that KPI values and at least one chart container are present.

