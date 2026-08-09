---
name: fp-unstable-querykey-content-hash
description: Do NOT flag inline (non-memoized) catalog request objects as a refetch storm. TanStack Query content-hashes query keys, so new-ref/same-content = same hash = no refetch.
metadata:
  type: feedback
---

# False positive: inline catalog request objects are NOT a refetch storm

When reviewing lazy-catalog code I almost flagged `useLazyCatalogs({ [CatalogType.X]: {...} })`
(inline object, new reference every render) as a CRITICAL "unstable queryKey → refetch every
render" regression. That is WRONG for this codebase.

**Why:** TanStack Query's default `queryKeyHashFn` (`hashKey`) serializes the key with a
key-SORTED `JSON.stringify`. Two structurally-equal objects produce the SAME hash, so the
query is identified by content, not by reference. A new object reference with identical
content does NOT trigger a refetch. A refetch storm only happens if the request CONTENT
varies per render (e.g. a `Date.now()` / changing value inside the request).

**How to apply:**
- CRITICAL refetch-storm = request CONTENT changes each render. Inspect for non-static values.
- Inline object with STATIC content (e.g. `{ _sort: 'name' }`) = SAFE. At most a SUGGESTION
  to memoize for consistency / to keep downstream option `useMemo`s stable (negligible CPU on
  small arrays). Never CRITICAL/WARNING.
- Files with inline (non-memoized) requests that are SAFE: PaymentFiltersPanel, ChargeListPage,
  UnitListPage. Files that memoized: the 4 access pages, bankAccount, expense, etc. Both fine.

Don't make readers chase a non-issue — state explicitly that it is not a refetch storm.
