---
name: pagination-filter-contract
description: Orion POST /search contract for this project — filters array, ISearchParams shape, filter operators supported
metadata:
  type: project
---

Orion `POST /{resource}/search` body shape:
- `filters`: array of `{ field, operator, value }` — operators include `>=`, `<=`, `in`, `=`, `like`, etc. NO native `between` operator; use paired `>= / <=` filters.
- `sort`, `includes`, `search`, `aggregates`, `scopes` — all supported.
- `filterableBy()` on the controller must whitelist every field used in filters[] or Orion returns 422.
- Response: `{ data: [], meta: { current_page, per_page, total, last_page, … } }` — frontend reads via `paginationFromMeta()`.

Frontend `ISearchParams` (list.types.ts) is the typed shape. Extra top-level params (like `owner_ids`) are NOT in this interface and must be cast or the TS compiler rejects the spread.

**Why:** Established during charges-filters review.
**How to apply:** Every new filter field needs both `filterableBy()` whitelisting (backend) and a `Filter` entry with correct operator (frontend).
