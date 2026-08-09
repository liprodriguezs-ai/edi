---
name: board-period-module
description: BoardPeriod/BoardMember frontend patterns — modal-form fragment pattern, §7.3.2 invariant only enforced in startPeriodSchema (not in single-member CRUD), duplicated memberSchema
metadata:
  type: project
---

# Board Period / Board Member module (frontend)

Reviewed 2026-07-19 (edición de miembros de mesa directiva).

- Module style: NOT useBaseCrud — uses `useMutation` + manual `refresh()` (alias of `onSearch({...paramsSearch})`), consistent with StartPeriodModal. Accept this as module convention.
- Modal pattern: wrapper with `if (!isOpen) return null` + child `XForm` rendering DialogHeader/Footer as fragment inside DialogContent (no submitRef). Lazy catalogs OK (§2.12).
- **Invariant gap found**: §7.3.2 (mesa requires Presidente/Tesorero/Secretario) is enforced ONLY in `startPeriodSchema.superRefine`. The individual BoardMember CRUD (add/edit/remove) has NO guard front NOR back (`BoardMemberController::performDestroy/performUpdate` only sync Spatie roles; FormRequests only check `required`). Removing/re-roling the last Tesorero silently breaks the invariant. Flagged 2026-07-19; recheck on future Board reviews whether a guard landed.
- Duplicate-person rule (§7.5) is duplicated: private `memberSchema` in startPeriodSchema AND exported `boardMemberSchema` (identical shape). Flagged for consolidation.
- Backend `BoardMemberController::performStore` does NOT invoke `app(BoardMemberCreateRequest::class)->validated()` (laravel-backend-architecture §2.12 gap) — belongs to laravel reviewer but affects the seam: client Zod is the only duplicate-user guard.
- Prettier here uses `jsxSingleQuote: true` — single quotes in JSX are correct project style, not a finding.

Related: [[project-conventions]]
