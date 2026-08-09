---
name: lazy-catalogs-refactor
description: Lazy catalog/select loading refactor — useLazyCatalogs hook, enabled:isOpen gate, display:contents filter wrappers, recurring smells
metadata:
  type: pattern
  scope: project
---

# Lazy Catalog Loading (infra-cost: avoid POST /selects on list mount)

Refactor (2026-06) made catalog `/selects` LAZY. Three patterns now coexist:

1. **FORM catalogs → load-on-form-open.** Two valid shapes:
   - Hook-free wrapper early-returns `if (!isOpen) return null` and delegates the
     form body + `useCatalogs` to a child that mounts only when open. Gold standard
     §2.9. Used by: CatalogFormModal→CatalogFormSheet, ProviderFormModal→ProviderFormSheet,
     ChargeConceptFormModal→ChargeConceptFormBody, ProjectFormModal→ProjectForm,
     StartPeriodModal→StartPeriodForm, ExpenseFormModal→ExpenseFormContent,
     RegisterPaymentSheet→RegisterPaymentSheetBody. ALL verified: submit/footer wiring,
     reset(defaultValues), and edit-path population intact. Child bodies that take an
     `isOpen`-style param now pass a literal `true` (ExpenseFormContent→useExpenseForm(true,…))
     because they only mount when open.
   - `useCatalogs(req, { enabled: isOpen })` — new option on useCatalogs. Used by
     bankAccount AccountFormModal (modal stays mounted, Radix toggles visibility, so the
     `enabled` gate is the lever). No permanent-empty: query fires on open, gcTime caches.
   - List conditionally mounts the modal `{open && <Modal/>}` (announcement). The modal then
     runs hooks unconditionally (no internal early-return) — safe because parent guarantees
     mounted⟺open.

2. **FILTER catalogs → load-on-dropdown-open** via new `useLazyCatalogs(req)` → returns
   useCatalogs shape + `enable()`/`isEnabled`; query disabled until enable(). enable() is
   idempotent (setEnabled(true), React bails on same value).
   - Native `<Select>` exposes `onOpenChange` → wired directly (UnitListPage, clean; also
     shows a disabled "Cargando…" SelectItem while `isLoading`).
     CORRECTION (2026-06-29): UnitListPage MIGRATED off native Select → `<MultiSelectFilter>`
     (3 filters, Orion `in`). It no longer shows a "Cargando…" item — MultiSelectFilter shows
     "Sin opciones" during the lazy fetch. Its non-memoized-request smell (below) is now FIXED.
     See [multiselectfilter-searchable].
   - Shared `MultiSelectFilter` has NO open callback → wrapped in a `display:contents` div
     with pointer/focus handlers to call enable().

## RECURRING SMELLS in this refactor (flag on sight)

- **Non-memoized `useLazyCatalogs({...})` request object** in ChargeListPage,
  PaymentFiltersPanel, UnitListPage. New object each render → useCatalogs' internal
  `catalogs` useMemo (dep [data, catalogRequest]) recomputes → downstream options useMemo
  (.map over potentially large UNIT) recomputes every render. The access pages + form modals
  correctly wrap in `useMemo(() => ({...}), [])`. queryKey stays stable (React Query hashes
  structurally) so NO refetch loop — perf only, not correctness. Same smell noted in
  [access-filter-feature]. Fix: memoize request, or stabilize inside useLazyCatalogs.

- **Duplicated lazy-trigger wrapper** across 5 panels (payment, charge, 3 access) because
  MultiSelectFilter lacks an open callback. TWO inconsistencies: (a) access uses bubble
  `onPointerDown`/`onFocus`, charge/payment use `onPointerDownCapture`/`onFocusCapture`;
  (b) payment/access use `className="contents"`, CHARGE omits it (plain div). Charge's missing
  `contents` is not a layout break (grid still renders 1 cell per filter, wrapper has no
  styling and stretches) but is inconsistent. Skill rule 5 (no duplicated logic). Best fix:
  add `onFirstOpen?: () => void` to shared MultiSelectFilter (it owns the Popover; add
  `<Popover onOpenChange={(o) => o && onFirstOpen?.()}>`), delete all wrappers.

## Verified SAFE / not regressions
- compact MultiSelectFilter triggerLabel shows `N seleccionados` count — NEVER reads
  `options` for pre-selected values. ALL lazy filters pass `compact`, so deep-link /
  pre-applied filter values render without the catalog. No label-blank regression.
- UnitListPage: removed the old "hide tower filter when no towers" gate (incompatible with
  lazy). Nothing else depended on it; tower filter always renders now (empty filter for
  zero-tower clusters is acceptable).
- residentInvitation PendingInvitationsList: all hooks (useUnitInvitations, useLazyCatalogs,
  useState, useEffect) run BEFORE the `if (isLoading || invitations.length===0) return null`
  — Rules of Hooks respected. enable() gated on hasPendingInvitations; relationship label
  pops in after lazy fetch (brief flash, acceptable).
- React Query v5 isLoading for a disabled query = false (status pending + fetchStatus idle),
  so "Cargando…" only shows during the real fetch after enable().
