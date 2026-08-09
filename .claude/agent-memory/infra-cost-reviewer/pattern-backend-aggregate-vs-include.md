---
name: pattern-backend-aggregate-vs-include
description: Prefer Orion aggregates (withCount) over eager hasMany includes when the view only renders a count. Kills per-row N+1. Needs controller aggregates() whitelist.
metadata:
  type: pattern
---

# Backend cost win: aggregate count instead of eager hasMany include

When a list row renders only a COUNT of a hasMany (e.g. "lecturas: 5"), eager-loading the
full relation (`includes: [{ relation: 'reads' }]`) loads every child row per parent just to
call `.length`. In a cluster with hundreds of residents that is hundreds of rows per
announcement row, per list view, per tenant.

**Fix:** request the count via Orion search `aggregates`:
```ts
aggregates: [{ relation: 'reads', type: 'count' }]   // → entity.reads_count
```
and read `entity.reads_count` in the UI (drop `entity.reads?.length`).

**Backend requirement (or Orion 422s):** the controller MUST whitelist the relation in
`public function aggregates(): array { return ['reads']; }`. Verified present in
`Modules/Announcements/app/Http/Controllers/AnnouncementController.php`. When auditing a new
aggregate usage on the frontend, confirm the controller whitelists it — otherwise the cost
"fix" breaks the list. (Whitelist gap = integration/correctness issue → @integration-reviewer.)

**How to apply:** flag any list `includes` of a hasMany whose only consumer is `.length`/count
as a WARNING with this remediation.
