---
name: lazy-catalog-convention
description: HARD convention — when and how to load catalog/select data lazily in the React frontend
metadata:
  type: project
---

# Lazy Catalog Loading — HARD Convention (2026-06-29)

`useCatalogs` fires `POST /selects` on mount. Load catalogs as late as possible.

## Form catalogs → load-on-form-open
Modal does `if (!isOpen) return null`; `useCatalogs` lives in the CHILD form that only mounts when open.
Gold standard: `DocumentFormModal`/`DocumentForm` (skill §2.9), `collaborator`, `unit`.
Three valid approaches:
1. **Extract child (gold):** hook-free modal wrapper + child with useCatalogs.
2. **Enabled gate:** `useCatalogs(req, { enabled: isOpen })`.
3. **Conditional render:** `{open && <XFormModal .../>}`.

## Filter catalogs → load-on-dropdown-open
Use `useLazyCatalogs(req)` — does NOT fire until `enable()` is called.
Wire: `<Select onOpenChange={(open) => open && enable()}>`.
Heavy/independent filters (UNIT, USER) get their OWN `useLazyCatalogs` for per-dropdown granularity.

## The leak to recognize
`useCatalogs` in a List component, or in a modal that the list renders UNCONDITIONALLY
(`<XFormModal isOpen={open}/>` — `isOpen` only toggles Radix visibility, NOT the React mount).
An `if (!isOpen) return` INSIDE a custom hook does NOT gate the fetch (not a component mount guard).

## Legit exception
A deep-linked/pre-applied filter may eager-load ONLY its own catalog to render the active label.

## Confirmed CLEAN (do not re-flag)
No app-bootstrap/login/global prefetch exists. `useBaseList`, `resourceApi`, `EntityStore`,
axios interceptors, QueryClient — none fetch catalogs.
