---
name: access-filter-feature
description: Access module list-filter feature review findings — AccessLogListPage, VisitorListPage, BlacklistListPage collapsible filters
metadata:
  type: project
---

## Access Module Filter Feature (reviewed 2026-06-27)

### Filter merge pattern — CORRECT
`buildCombinedFilters(tab, panelFilters)` is the single source of truth in both tabbed pages (AccessLog, Visitor). Both `handleTabChange` and `applyFilters` pass their own value as a parameter and read the "other" from the dependency array. No wipe-on-tab-change bug. All paths set `page: 1`.

### resolveDateRange — only safe for DATE columns, NOT TIMESTAMP
**Why:** `resolveDateRange` was designed for `due_date`/`issued_at` (DATE columns). It returns `YYYY-MM-DD`. When applied to TIMESTAMP columns, the `to` bound becomes `YYYY-MM-DD 00:00:00`, cutting off all records from that day created after midnight.

**Affected:**
- `VisitorListPage` — `qr_expires_at` is `TIMESTAMP` (confirmed from migration); the `<= YYYY-MM-DD` upper bound is wrong
- `BlacklistListPage` — `created_at` is always a `TIMESTAMP` from BaseModel; same issue

**Fix:** Append ` 23:59:59` to the `to` value for timestamp columns.
**Safe usage:** only for DATE-only columns (like `due_date`).

### useCatalogs inline config — defeats memoization
Calling `useCatalogs({ ... })` with an inline object literal creates a new reference every render. Inside the hook, `useMemo([data, catalogRequest])` invalidates every render, cascading to all downstream `useMemo`s (unitOptions, motiveOptions, etc.). TanStack Query does NOT refetch (hash-based key), but memoization is broken.

**Fix:** Module-level constant or `useMemo(() => ({...}), [])` at the call site. Applies to all three access pages.

### BlacklistListPage formProps.onClose triggers refresh on cancel
`CatalogFormModal` has a single `onClose` for both success and cancel. `formProps.onClose` calls `onSearch`, so every modal dismiss (including cancel) fires a request. Not a bug, just wasteful.

### resource: BlacklistResource as any
`as any` needed because `createCrudApi` uses `Record<string,unknown>` in its V type parameter, making structural compatibility with `CatalogResource<T, BlacklistFormValues>` fail due to function parameter contravariance. Fix: `as unknown as CatalogResource<...>` — keeps `any` out of type graph.

### CatalogFormModal export — SAFE
`CatalogFormModal`, `CatalogFormProps`, `CatalogResource` correctly exported from CatalogCrudModule.tsx. No circular deps. BlacklistListPage successfully reuses CatalogFormModal for its Sheet CRUD.

### buildBlacklistDefaults — correctly outside component
Avoids spurious form resets from new function references on each render.

### is_null / is_not_null operators — VALID in list.types.ts
Both operators are in the `FilterOperator` union type. The two-condition AND for "Dentro" estado (both exited_at IS NULL AND denied_at IS NULL) is the correct Orion approach.

**How to apply:** When reviewing future filter additions — always check if the column is DATE vs TIMESTAMP before using resolveDateRange. Always memoize useCatalogs config.
