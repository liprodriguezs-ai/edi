---
name: delinquency-list-filters
description: Delinquency filters review (2026-07-19) — error-state-masked-as-success on useQuery pages, DelinquencyRow IEntity cast, pseudo-label spans in ad-hoc filter bars, single-debounced-params pattern
metadata:
  type: project
---

## Delinquency list filters — review 2026-07-19

Scope: payment/schema.ts (DelinquencyQueryParams), PaymentResource (delinquency/delinquencyReport),
DelinquencyPage filter bar. Typecheck clean; backend contract verified aligned
(PaymentController validates search / charge_concept_ids array max:50 / min_months_overdue /
min_amount / max_amount as integers in minor units).

### New RECURRING anti-pattern: error state masked as success
`queryClient.ts` has NO global query error toast (only retry policy). Pages using bare
`useQuery` without `isError` fall through to their empty state. DelinquencyPage:329 shows the
celebratory "🎉 …al corriente" message on a failed GET. Check EVERY useQuery page for isError
handling — the query client will not save them. Related: [[anti-pattern-violations]].

### Type-escape ledger grows
`(data?.data ?? []) as DelinquencyRow[]` where `DelinquencyRow = DelinquencyUnit & IEntity`
(DelinquencyPage:65,168) — asserts id/created_at that don't exist at runtime; safe only because
getRowKey is passed. Same family as TenantWizardPage `.uuid` and TenantBillingPanel `as string`.
Root cause: `ResponsiveDataTable<T extends IEntity>` constraint is too strict for aggregation
rows — suggested loosening to `{ id: string }` or mapping rows.

### A11y pattern split in filter bars
`MultiSelectFilter` does real `<label htmlFor>`; ad-hoc controls beside it (search Input,
months Select in DelinquencyPage:276,301) use unassociated `<span>` pseudo-labels → nameless
controls for SR. `RangeFilter` (shared, pre-existing) has the same span-label gap. When
reviewing filter bars, compare each control against MultiSelectFilter's labeling.

### Good pattern worth reusing (single-debounced-params)
ONE `useMemo` params object → ONE 350ms debounce → queryKey `['delinquency', debouncedParams]`
+ `placeholderData: keepPreviousData`. Export reuses `debouncedParams` so the xlsx always
matches the visible table (self-consistent even inside the debounce window). Gap: nothing
consumes `isFetching`/`isPlaceholderData`, so refetches after filter changes give zero
feedback on stale rows/KPIs.

### Confirmed compliant
- useLazyCatalogs + MultiSelectFilter onFirstOpen → zero /selects on page mount (§2.12).
- Local `isExporting` busy state (honors recorded user rule ux-local-busy-state).
- pesosToMinorUnits (Math.round) at param-build boundary; IMoney/displayMoney for display.
- Axios default array serialization `charge_concept_ids[]` parses fine in Laravel.
