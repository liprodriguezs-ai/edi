# Export Excel Lists (SDD export-excel-lists, reviewed 2026-07-06)

- `useBaseList.onReport` now uses hook-local `isExporting` useState (NOT store.putLoading) — deliberate UX fix so export doesn't re-skeleton the list. `isExporting` is part of the hook's return surface; any new export button must use it, never `isLoading`.
- `onReport` RETHROWS after toasting (`throw error` in catch). All 15 call sites are bare `onClick={() => onReport(...)}` → unhandled promise rejection on failure. Recurring smell; ContributionsList's `void exportXlsx()` (no rethrow) is the cleaner idiom.
- Base `onReport` sends `limit`/`page` from buildParams to `POST /customReport` (pre-existing); `useContributionsList.exportXlsx` omits them. Export-scope semantics (current page vs all filtered rows) depend on backend ignoring pagination — flagged for integration verification.
- The 15-line Descargar button block (PermissionGuard + outline Button + Loader2/Download swap) is copy-pasted 14 times — candidate for a shared `ExportButton` molecule; flag any 15th copy.
- `CatalogCrudModule` gained optional `reportModel`/`reportTitle` (backward compatible). Only `reportModel` actually gates rendering; `reportTitle` alone is a silent no-op and the JSDoc says "set both".
- Permission keys `report.<entity>` all match router permissionEntity strings (verified against App.tsx).
- ChargeListPage toolbar mixes default-size export button with `size='sm'` siblings (height mismatch).
