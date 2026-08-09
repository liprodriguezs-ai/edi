---
name: delinquency-restrictions
description: Cost review of the delinquency-restrictions change (2026-07-14) — settings batching/scoped memoization and all frontend gating verified clean; one per-row query loop in PackageService::batchRegister + one redundant Setting::getValue in the controller.
metadata:
  type: project
---

Cost audit of `delinquency-restrictions` (backend `Modules/Collections` + hooks in
AmenityReservations/Access/Package; frontend reservation/access/package/configuration).
Dated 2026-07-14.

## Verified CLEAN (do not re-flag)

- `DelinquencyRestrictionService::settings()` — really is ONE `Setting::whereIn` over the
  six `delinquency.*` slugs, memoized in `$settingsCache`. `Setting::getValue()` is
  uncached (1 SELECT/call), so the batching matters; it is never called in a loop here.
- `CollectionsServiceProvider::register()` binds the service with `$this->app->scoped(...)`.
  Confirmed correct: memoization spans exactly one request across all five hook points
  (`app(DelinquencyRestrictionService::class)` returns the same instance), and it avoids the
  Octane cross-tenant pinning that `singleton()` would cause.
- Per-check cost claims hold: master off → 1 query (settings, first call only; 0 after);
  enabled + not OVERDUE → 2; OVERDUE + threshold > 0 → 3.
- `restrictedUnitIds()` and `stateForUnits()` — genuinely batch, 3 queries max, NO per-unit
  query. `stateForUnits()` is per-unit in SEMANTICS only (per-unit payload), not per-unit
  in queries.
- Frontend gating is gold-standard. Both `VisitorFormModal` and `ReserveAmenityWizard` use a
  wrapper with `if (!isOpen) return null` and ZERO hooks before the early return, delegating
  to a `*Body` child that owns `useMyRestrictions()` + `useCatalogs()`. The fix works: no
  fetch on list render. `usePackageCatalogOptions` gates `['package-restricted-units']` on
  `enabled: unitsEnabled` from `useLazyCatalogs` (`useState(false)` → `enable()` from
  `onOpenChange`) — real gating, zero fetch on list mount.
- No polling. Global QueryClient defaults are `refetchOnWindowFocus: false`, `staleTime: 30s`.
- `ConfigurationPage` renders every section inside a Radix `TabsContent` WITHOUT `forceMount`,
  so inactive sections never mount. Config-section `useQuery`s (incl.
  `DelinquencyConfigSection`) fire only when their tab is active. **Do not flag config
  sections for "all sections fetch on page open" — they don't.**
- `overdueBalancesByUnit()` loads OVERDUE charge rows and sums in PHP via
  `ChargeStatusService::effectiveExpected()` instead of SQL SUM. DELIBERATE — avoids a 5th
  reimplementation of the outstanding formula; columns are already narrowed via `get([...])`
  and the row count is bounded by cluster size. Do not flag; the project values KISS here.

## Findings raised

1. WARNING — `Modules/Package/app/Services/PackageService.php:227` + `:161`.
   `batchRegister()` pre-checks the whole batch with `restrictedUnitIds()` (3 queries, correct),
   then calls `register()` per row, whose own `assertNotRestricted()` runs per ROW (not per
   UNIQUE unit): 1 `Unit` SELECT + (when `min_overdue_amount > 0` and the unit is OVERDUE)
   1 `Charge` SELECT per row → up to ~100 extra queries on a 50-row batch. Bounded (cap
   `max:50` in `PackageBatchRequest`) and only when restrictions are enabled. Notably
   INCONSISTENT with the same file's own pattern: `$settingCache` and the per-unique-unit
   resident pre-resolution exist precisely to avoid this. Fix: pass the already-computed
   `$restrictedUnitIds` into `register()` so the per-row assert is an in-memory `in_array`.

2. SUGGESTION — `Modules/Collections/app/Http/Controllers/DelinquencyRestrictionController.php:43`.
   `Setting::getValue(NOTICE_TEXT)` = 1 extra uncached SELECT, redundant: the service's
   memoized `settings()` already holds `notice_text`. Fix: expose it off the service.

## Anti-pattern to recognize next time

A **batch pre-check followed by a per-row assert inside the loop** looks N+1-proof from the
batch guard's docblock ("3 queries max") while the real cost is in the loop. Whenever a
service exposes both a `restrictedUnitIds()`-style batch API and a per-item
`assertNotRestricted()`, check whether the loop calls the per-item one anyway.
See [[direct-charge-feature]] for the sibling "already loaded, loaded again" shape.
