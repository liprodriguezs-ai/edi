---
name: clean-searchselect-migration-2026-06
description: SearchSelect rollout (2026-06-29) verified non-regressing — the inner <Select>→<SearchSelect> swap left every lazy-catalog gate intact; SearchSelect itself never fetches in client mode.
metadata:
  type: project
---

# `<SearchSelect>` migration — lazy loading NOT regressed (2026-06-29)

A rollout swapped inner `<Select>` JSX for the new `<SearchSelect>`
(`src/shared/components/SearchSelect.tsx`) across the recently-lazified files.
Verdict: **ZERO regressions, zero CRITICAL.** No `POST /selects` fires on list/page
mount in any re-touched file. See [[pattern-lazy-catalog-loading]] and
[[clean-lazy-catalog-refactor-2026-06]].

## SearchSelect is cost-neutral by construction
`SearchSelect` has NO fetch primitives (no `useCatalogs`/`useQuery`/`axios`/`fetch`/`useEffect`).
- **Client mode** (`options` passed): renders `react-select` `ReactSelect` over already-loaded
  `options`. Pure presentational — never hits the network.
- **Remote mode** (`loadOptions` passed): only then renders `AsyncSelect` with a 300ms-debounced
  typeahead. **No changed consumer passes `loadOptions`** (grepped) → all are client mode.
- So the data still comes from the SAME `useCatalogs`/`useLazyCatalogs` upstream; SearchSelect
  just consumes `catalogs[X] ?? []`. The swap cannot move a fetch earlier.

## Per-file confirmation (8 audited targets)
- **access/AccessNowPage**: `useLazyCatalogs(useMemo(...))` + `useEffect(isOpen → enableCatalogs())`
  UNCHANGED; only inner Select→SearchSelect. Lazy ✓.
- **bankAccount/BankAccountListPage**: `useCatalogs(req, { enabled: isOpen })` gate intact (L114-122). ✓
- **chargeConcept/ChargeConceptFormModal**: `ChargeConceptFormBody`(L71) holds `useCatalogs`(L102);
  wrapper `ChargeConceptFormModal`(L243) `if(!isOpen) return null`(L244). Child-on-open ✓.
- **communityProject/CommunityProjectListPage**: `ProjectForm`(L78) holds `useCatalogs`(L108);
  `ProjectFormModal`(L371) `if(!isOpen) return null`(L372). ✓.
- **expense/ExpenseFormModal**: `ExpenseFormContent`(L460) holds `useCatalogs`(L468); wrapper(L630)
  `if(!isOpen) return null`(L637). Note: the `if(!isOpen) return` at L245 is INSIDE `useExpenseForm`
  (a reset-effect guard) — NOT the mount gate; the real gate is the wrapper early-return, so no leak. ✓.
- **payment/RegisterPaymentSheet**: `RegisterPaymentSheetBody`(L548) holds `useCatalogs`(L550);
  wrapper(L526) `if(!isOpen) return null`(L527). Per-unit charges `useQuery` gated
  `enabled: isOpen && Boolean(unitId)`(L186). ✓.
- **provider/ProviderListPage**: migration STRENGTHENED the gate — split `ProviderFormModal` into a
  wrapper `if(!props.isOpen) return null` + child `ProviderFormSheet` that holds `useCatalogs`(L134). ✓.
- **shared/catalog/CatalogCrudModule**: same strengthening — `CatalogFormModal` wrapper early-returns
  null, child `CatalogFormSheet` holds `useCatalogs(catalogRequest)`(L165). Centralized → high leverage. ✓.

## Note on git layering (so future-me isn't confused)
HEAD did NOT yet contain the lazy refactor; BOTH the lazy refactor AND this SearchSelect migration
were in the working tree uncommitted at audit time. `git diff` therefore shows the lazy gates being
ADDED in the same diff as the Select→SearchSelect swap (esp. provider + CatalogCrudModule). The END
STATE is what matters and it is lazy-correct. `useCatalogs.ts` in the same set adds the `{enabled}`
option + `useLazyCatalogs`; both correctly map to React Query's `enabled` flag (gate truly stops the
request) and caching (`gcTime 15min`, `refetchOnWindowFocus:false`) is preserved.
