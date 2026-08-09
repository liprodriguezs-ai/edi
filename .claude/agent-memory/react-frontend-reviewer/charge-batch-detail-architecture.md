---
name: charge-batch-detail-architecture
description: ChargeBatchDetailPage is a monolithic Page (no Detail split + no dedicated hooks), and BatchUnitChargesTable does its own useQuery inline — both violate §2.1 and the "no data-fetch in presentational components" convention
metadata:
  type: project
---

## ChargeBatchDetailPage — architecture violations (2026-06-26)

`ChargeBatchDetailPage` IS the whole detail view (no `{Module}DetailPage` thin wrapper + `{Module}Detail` split). Skill §2.1 "módulos con detalle" canon: `{Module}DetailPage.tsx` is only a thin wrapper that reads `useParams` and renders `<{Module}Detail id={id} />`. All data-fetching (3 `useQuery` calls + 1 `useMutation`) and all render logic live directly in the `DetailPage`, which is the router-entry component.

**Established contrast:** `CollaboratorDetailPage` = 8 lines thin wrapper; data lives in `useCollaboratorDetail.ts` hook; view logic lives in `CollaboratorDetail.tsx`.

## BatchUnitChargesTable — fetch-in-presentational-component

`BatchUnitChargesTable` in `components/` does its own `useQuery` (`ChargeResource.search`) inline. A component in `components/` is an organism/presentational layer. Data-fetching belongs in a hook in `hooks/`. The component was deliberately designed for reuse in the cockpit drill (deferrable via `enabled` prop), but the right pattern is to pass a hook (or the result of one) rather than baking `useQuery` inside the component.

**Critical constraint to preserve on refactor:**
- `['charges','by-batch',id,'stats']` (shape: `ICharge[]`) vs `['charges','by-batch',id]` (shape: `{ charges, total }`) are different cache entries with different shapes — the suffix `'stats'` is intentional and must stay.
- `toChargeRows` non-array guard (`if (!Array.isArray(charges)) return []`) must survive.

## Recurring pattern in this codebase

`CommunityProjectDetailPage` commits the same monolithic-page sin but does it in the same file (no separate `hooks/` files), using private functions `useProjectData` and `useContributableUnits` in the component file itself. This may be the de-facto convention for "large detail pages" in this project — check before prescribing a strict split. If so, the minimum requirement is that data-fetch logic be isolated into private named functions/hooks in the same file, even if not extracted to a separate `hooks/` file.

**Why:** §2.1 is explicit: `hooks/use{ModuleName}Detail.ts` is the prescribed location. But no existing module in this codebase fully implements the split (no `{Module}Detail.tsx` separate from `{Module}DetailPage.tsx` with data in a `hooks/` file) except `Collaborator`. The project currently has two patterns in conflict.

**How to apply:** Flag the structural violation in reviews. Accept `CommunityProject`-style in-file private hooks as a pragmatic middle ground if explicit `hooks/` files are not yet established for all detail pages. Require at minimum: no raw `useQuery` at the top level of a `*DetailPage`, and no `useQuery` inside a `components/` presentational component.
