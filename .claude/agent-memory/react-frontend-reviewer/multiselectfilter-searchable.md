---
name: multiselectfilter-searchable
description: MultiSelectFilter gained a client-side search Input inside the Popover; UnitListPage migrated 3 native Selects → MultiSelectFilter with Orion `in` filters
metadata:
  type: pattern
  scope: project
---

# MultiSelectFilter searchable + UnitListPage multiselect migration (2026-06-29)

`shared/components/filters/MultiSelectFilter.tsx` added a client-side search `<Input>` inside
the Popover. Change is 100% BACKWARD-COMPATIBLE — purely additive, no prop/signature change.
19 total `<MultiSelectFilter` usages across 7 files (charge 4, payment 4, access 3+3+1, unit 3);
the 16 pre-existing usages are unaffected. 4 consumer files already pass `onFirstOpen`; none
rely on the component's internal DOM structure.

Verified CORRECT:
- Search filters `visibleOptions = options.filter(label.includes(query))`; `value[]` is NEVER
  touched. A selected option scrolled out by the query STAYS selected (checkbox reads
  `selectedSet.has`, compact triggerLabel reads `value.length`, chips read `value`). No
  selection-lost-on-search bug.
- `query` resets on popover close via `onOpenChange(false) → setQuery('')`; Radix Popover has
  NO built-in typeahead (unlike Select/DropdownMenu) so typing into the Input is not intercepted.
- Empty-options branch shows "Sin opciones" with NO search box (lazy filters mid-load look the
  same as pre-change — not a regression). Filtered-to-empty shows "Sin resultados".

Two LATENT issues flagged (neither breaks today):
1. `onFirstOpen` is a MISNOMER — JSDoc says "the first time the popover opens" but impl fires on
   EVERY open (`onOpenChange: if (open) onFirstOpen?.()`). Safe only because all callers pass
   idempotent `enable()`. A non-idempotent caller (analytics "first open") would misfire. Same
   smell previously noted in [searchselect-rollout]; now lives inside the shared component.
2. Search `<Input>` has only `placeholder="Buscar…"`, no `aria-label`/`<label>`. Placeholder is
   not a reliable accessible name (WCAG). Recommend `aria-label="Buscar opciones"`. No aria-live
   on the "Sin resultados" / result count (SR users don't hear filtering) — enhancement.

UnitListPage: 3 native `<Select>` (unit_type_id, unit_status_id, tower_id) → `<MultiSelectFilter>`
with `string[]` state + Orion `{ operator: 'in' }` per non-empty array (empty omitted), matching
buildChargeFilters. `__all__` sentinel fully gone; onReset clears all 3 arrays. Backend
UnitController::filterableBy = ['unit_type_id','unit_status_id','tower_id','section_id','floor'] →
all 3 whitelisted. Each filter keeps its OWN `useLazyCatalogs(useMemo(...))` + `onFirstOpen={enable}`.
The prior non-memoized-request smell for UnitListPage (see [lazy-catalogs-refactor]) is now FIXED —
each request is `useMemo(() => ({...}), [])`.
