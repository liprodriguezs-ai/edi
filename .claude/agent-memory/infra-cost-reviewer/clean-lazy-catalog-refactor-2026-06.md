---
name: clean-lazy-catalog-refactor-2026-06
description: 18 files + CatalogCrudModule confirmed clean (2026-06-29) — no POST /selects on list mount. Don't re-flag these unless they regress.
metadata:
  type: project
---

# Lazy-catalog refactor — confirmed clean (2026-06-29)

Audited the lazy-catalog-loading refactor. Verdict: NO `POST /selects` fires on list mount
across all 18 converted files. Zero CRITICAL findings.

**Why:** explicit business goal is to minimize backend spend; catalogs fetched on every list
view × every tenant were the canonical leak. Refactor moved them to load-on-form-open /
load-on-dropdown-open. See [[pattern-lazy-catalog-loading]].

**How to apply:** treat these as clean; only re-review on regression. Quick re-check = grep the
file for `useCatalogs(`/`useLazyCatalogs(` and confirm each is in a mount-on-open child, gated
with `{enabled}`, or lazy with `enable()` wired.

## Confirmed-clean modules (by wiring)
- Mount-on-open child: provider, chargeConcept, expense, communityProject, boardPeriod (StartPeriodForm),
  payment/RegisterPaymentSheet.
- **CatalogCrudModule (CENTRALIZED)**: `CatalogFormModal` early-returns null, `CatalogFormSheet`
  child holds `useCatalogs(catalogRequest memo'd on [fields])`. This single fix covers EVERY
  simple catalog module (tower/section/unitType/unitStatus/unitFeature/expenseCategory/…). High leverage.
- Parent mount-gate `{open && …}`: announcement (AnnouncementComposeModal).
- `{enabled:isOpen}` gate: bankAccount.
- Native Select onOpenChange→enable: unit (cleanest filter pattern).
- display:contents + pointer/focus wrapper: payment/PaymentFiltersPanel, charge, access ×4
  (VisitorListPage, AccessLogListPage, BlacklistListPage, AccessNowPage).
- Data-driven enable(): residentInvitation/PendingInvitationsList (enable only when there ARE
  pending invitations).

## Bonus infra-cost wins in the same diff
- announcement: list switched `{relation:'reads'}` include → `aggregates:[{relation:'reads',type:'count'}]`
  → `reads_count`. Kills per-row N+1 (hundreds of AnnouncementRead rows per row → one int).
  Backend `AnnouncementController::aggregates()` returns `['reads']` → whitelisted, no 422.
- VisitorListPage: added 350ms debounce on the plate input → no search request per keystroke.

## Open SUGGESTIONS (not blocking, handed to owning modules)
- Centralize the display:contents+pointer/focus hack into `MultiSelectFilter` via an `onFirstOpen`
  prop fired from its Popover `onOpenChange`; removes ~9 ad-hoc wrappers and the capture-vs-bubble +
  `contents`-vs-bare-`<div>` inconsistency (ChargeListPage uses bare `<div>`, others use `contents`).
- AccessNowPage ManualEntryModal uses useLazyCatalogs + useEffect(isOpen→enable); `{enabled:isOpen}`
  would be simpler (micro). residentInvitation's data-driven enable is fine as-is.
- Inline (non-memoized) request objects in PaymentFiltersPanel/ChargeListPage/UnitListPage: memoize
  for consistency only — NOT a refetch storm. See [[fp-unstable-querykey-content-hash]].
