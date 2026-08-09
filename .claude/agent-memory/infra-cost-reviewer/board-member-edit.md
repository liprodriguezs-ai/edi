---
name: board-member-edit
description: Board member add/edit/remove (boardPeriod frontend) confirmed CLEAN gold-standard — no eager catalogs, no over-fetch, bounded refetch; UserCombobox has a hasOpened gate
metadata:
  type: project
---

# Board member edit (Mesa Directiva) — CLEAN (2026-07-19)

Audited files (frontend, feat "edición de miembros de mesa directiva"):
- `src/modules/boardPeriod/components/BoardMemberModal.tsx` (new)
- `src/modules/boardPeriod/services/BoardMemberResource.ts` (new)
- `src/modules/boardPeriod/BoardPeriodListPage.tsx` (modified)
- `src/modules/boardPeriod/schema.ts` (modified)

**Why clean:**
- `BoardMemberModal` is a hook-free wrapper with `if (!isOpen) return null` BEFORE any hooks; `useCatalogs(BOARD_ROLE)` lives in child `BoardMemberForm` that only mounts when open (skill §2.9/§2.12 gold standard). List additionally conditional-renders `{memberModalPeriod && <BoardMemberModal/>}` — double gate. Zero `/selects` on list mount.
- `useCatalogs` (shared) confirmed with `staleTime: 30s`, `gcTime: 15min`, `refetchOnWindowFocus: false` — modal reopen within window paints from cache.
- `UserCombobox` (`modules/unit/components/UserCombobox.tsx`): `useQuery` gated by `hasOpened && !disabled` — no fetch on modal open, first request only on field focus; `skipNextSearch` ref avoids a wasted debounce cycle in edit mode. Deliberate UX: empty-term search on focus returns first page (documented, cached 30s) — accepted, do not re-flag.
- Mutations (add/edit/remove member) each do ONE `refresh()` (full list re-search with its 5 includes). Bounded per admin action; project-standard pattern. All 5 list includes (`board_period_status`, `members`, `members.board_role`, `members.user`, `media`) are rendered — no over-fetch.

**How to apply:** treat boardPeriod member CRUD as a reference-clean example alongside DocumentFormModal. Do not re-audit unless these files change again.
