---
name: complaint-module-review
description: Complaint (Denuncias) module review 2026-07-19 — build broken by test mock typing; desktop row-click dead (ResponsiveDataTable onRowClick is cards-only)
metadata:
  type: project
---

# Complaint module review (2026-07-19, uncommitted)

- **Build broken AGAIN by a test file**: `ComplaintListPage.test.tsx` mockImplementation selector typed `(s: object) => unknown` is incompatible with `IEntityStore<IComplaint>` → `tsc -b` fails (2 errors) while vitest passes (24/24 green, no typecheck). Same failure signature as the Package gate. **Rule: always run `tsc -b` — green vitest proves nothing about the build.**
- **ResponsiveDataTable gotcha (recurring)**: `onRowClick` prop ONLY wires the mobile cards branch. Desktop `renderRow` must attach its own `onClick` on the `<TableRow>` (correct precedent: `PaymentListPage.tsx:283`). Complaint shipped `cursor-pointer` with NO onClick → detail unreachable on ≥lg. Check this on every ResponsiveDataTable consumer with navigation.
- Zod `.max(n)` without Spanish message + no global Zod locale → English validation errors reach the UI. Check every schema for bare `.max()`/`.min()` beyond the first.
- Client-side FSM mirror (`TRANSITIONS` in ComplaintDetailPage) duplicates `ComplaintService::isValidTransition` — drift risk, flagged.
- `resolution_days_target` setting is display-promised in config UI but aging chip hardcodes 7/15 days — dead setting.
- Config sections pattern still masks GET errors (bare `useQuery` without `isError`) — same as [[delinquency-list-filters]].
- Positive: lazy catalogs done perfectly (useLazyCatalogs + onFirstOpen/onOpenChange/onMenuOpen everywhere); local `mutation.isPending` per action (matches user pref); CatalogCrudModule for the 2 sub-catalogs; direct useMutation instead of useBaseCrud avoids the double-toast bug ([[collections-form-page-migration]]).
- Naming seam: contract field `folio` (`D-YYYY-NNNNNN`) chosen by spec despite CLAUDE.md rule 6 disambiguation — FE mirrors API, flag lives at spec level.
