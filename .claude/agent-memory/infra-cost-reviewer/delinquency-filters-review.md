---
name: delinquency-filters-review
description: 2026-07-19 audit of server-side delinquency filters (DelinquencyPage + PaymentController::delinquencyRows) — verified lazy/debounce claims true; main cost = PHP-side aggregation over unbounded charge load
metadata:
  type: project
---

# Delinquency server-side filters — infra-cost review (2026-07-19, uncommitted)

**Verified TRUE (frontend claims):**
- `DelinquencyPage.tsx` catalog IS lazy: `useLazyCatalogs(CHARGE_CONCEPT)` with memoized request + `MultiSelectFilter onFirstOpen={enable}` — zero `POST /selects` on mount.
- Single 350ms debounce (`params` useMemo → `debouncedParams` state) covers ALL 5 filters (search, concepts, months, min/max pesos). No filter bypasses it; no immediate duplicate on mount (post-debounce `{}` → `{}` is a structural-hash no-op for TanStack queryKey).
- `queryKey: ['delinquency', debouncedParams]` — state-stable reference + structural hashing → no re-fetch from unstable refs.
- Export = exactly 1 request (`GET /delinquency/report` with active `debouncedParams`), local `isExporting` (project UX rule). No extra fetches.
- Query inherits global `staleTime: 30s` + `refetchOnWindowFocus: false` from `shared/api/queryClient.ts` — do NOT re-flag missing staleTime on queries that rely on these global defaults (learned this review; WU-7 flags predated checking the global config... verify: WU-7 components used their own 0ms? The global default was already 30s — check before re-flagging staleTime anywhere).

**Findings (open as of review):**
- WARNING `PaymentController.php:513-538` — `delinquencyRows()` loads EVERY overdue/partial charge (all columns + unit/unitType/tower eager) and aggregates in PHP `groupBy('unit_id')`. SQL `GROUP BY unit_id` + `SUM(GREATEST(...))`/`MIN(due_date)`/`COUNT(*)` (+ HAVING for min_months/min/max) would return 1 row per unit instead of 1 per charge. Fires per dashboard view + per debounced filter change + per export.
- SUGGESTION `PaymentController.php:537` — `'charge_status'` eager-loaded but never read (query already filters by status ID; relation not serialized). Dead eager-load, 1 extra SELECT/request.
- SUGGESTION — no pagination on `/delinquency` (deliberate: avgAge/worst KPIs computed client-side over the full set; export mirrors table). Acceptable per report-endpoint convention (same as `aging()`), revisit if tenants grow.
- SUGGESTION `DelinquencyPage.tsx:91` — `conceptIds` array order participates in queryKey hash; `[...conceptIds].sort()` when building params would dedupe cache entries for same selection in different click order. Micro.

**Clean (do NOT re-flag):**
- No query duplication KPIs vs rows: `delinquency()` calls `delinquencyRows()` ONCE; `units_with_debt`/`total_outstanding` are in-memory `->count()`/`->sum()`.
- Search `whereHas` chain = EXISTS subqueries inside ONE query, not per-row N+1.
- `unit.unitType`+`unit.tower` eager-loads needed by `full_identifier` (known false positive).
