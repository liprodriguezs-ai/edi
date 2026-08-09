---
name: owner-ids-param-pattern
description: Pattern for sending top-level custom params (outside filters[]) to Orion search — requires ISearchParams extension or type cast; owner_ids is the first instance
metadata:
  type: project
---

When a filter requires a backend subquery (charge → unit → unit_user.is_billing_owner) that can't map to a direct `filterableBy()` field, the convention is:
- Backend: reads `$request->input('owner_ids')` directly in `buildSearchFetchQuery()` / `buildIndexFetchQuery()` overrides.
- Frontend: spreads `{ owner_ids: string[] }` alongside `filters: Filter[]` in the `onSearch()` call.

Problem: `ISearchParams` (list.types.ts) does NOT have an `owner_ids` field. The current code works around this with `as Parameters<typeof onSearch>[0]` cast (ChargeListPage.tsx:171). This suppresses the TS error but is fragile — if `onSearch` signature changes, the cast silently swallows the type mismatch.

**Recommended fix:** Extend `ISearchParams` with `[key: string]: unknown` index signature OR add a `extraParams?: Record<string, unknown>` field and thread it through `buildSearchExtras()` so the pattern is reusable and type-safe.

**Why:** Discovered during charges-filters review.
**How to apply:** Flag any future `as Parameters<typeof onSearch>[0]` cast as a drift warning. If the pattern recurs, push for the generic `extraParams` solution.
