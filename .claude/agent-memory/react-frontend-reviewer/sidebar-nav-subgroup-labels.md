---
name: sidebar-nav-subgroup-labels
description: Sidebar sub-grouping of collapsible nav items by owning module (group field) — groupNavSubItems pure helper, "Otros" label collision risk, untrimmed label bug, missing useMemo
metadata:
  type: project
---

## What shipped (uncommitted as of 2026-07-02)

`src/shared/utils/groupNavSubItems.ts` (+ test) sub-groups `SidebarMenuSub` items
inside a collapsible nav group (e.g. "Catálogos") by the module's `group` field
from the dynamic menu API. Renders a muted, non-focusable `<span>` label inside
a `<SidebarMenuSubItem>` (`<li>`) between blocks, with `border-t` as separator.
Falls back to flat rendering (`null` return) when 0-1 distinct group values.

Files: `nav-main.tsx`, `useNavSections.ts`, `useFilteredNavItems.ts`,
`MenuSystem.ts`, `groupNavSubItems.ts` (+test). Backend `group` column already
existed and aligned (`modules.csv`, `MenuController.php:134,152`) — no backend
gap.

## Verified NOT a bug (worth remembering to avoid re-litigating)

- `<span>` label inside `<SidebarMenuSubItem>` (a `<li>`) does NOT break `ul>li`
  HTML structure — `Fragment` doesn't render a DOM node, so `SidebarMenuSub`
  (`<ul>`) still only has `<li>` direct children. This is deliberately NOT
  `SidebarGroupLabel` (a `<div>`, meant for `SidebarGroup` which is a `<div>`,
  invalid inside a `<ul>`). Correct call by the author.
- Fallback path (no grouping) renders byte-identical JSX to pre-change code
  via the extracted `NavSubItemLink` component — verified line-by-line, no
  regression.
- Keys are stable: `Fragment key={block.label}` (from `Map` keys, inherently
  unique within one `groupNavSubItems` call) + `key={subItem.title}` (same as
  before).

## Real findings (not yet fixed as of this review)

1. **"Otros" label collision risk (Important):** `OTHERS_LABEL = 'Otros'` is a
   hardcoded sentinel for the ungrouped-items bucket. If a backend module's
   real `group` value is literally `"Otros"` (plausible generic Spanish
   catalog name), `itemsByLabel` already has an `"Otros"` key AND the code
   separately `blocks.push({ label: OTHERS_LABEL, ... })` — two blocks with
   the same label. In `nav-main.tsx`, `Fragment key={block.label}` then
   produces a **duplicate React key**, which is a real rendering bug (data-
   dependent, not caught by current tests since none test this collision).
   Fix: reserve/guard the sentinel (e.g. check `itemsByLabel.has(OTHERS_LABEL)`
   and pick a non-colliding label), or key by index too:
   `key={`${block.label}-${blockIdx}`}`.
2. **Untrimmed label used as Map key (Suggestion):** `hasGroup()` checks
   `item.group.trim().length > 0` but the label building (`groupNavSubItems.ts:31`,
   `const label = item.group as string`) uses the RAW untrimmed value. `"RRHH"`
   vs `" RRHH"` would produce two visually-near-identical but distinct blocks.
   Fix: `.trim()` when building the label too.
3. **Type escape hatch `as string` (Important per project convention):**
   `groupNavSubItems.ts:23,31` — `item.group as string` used twice instead of
   a type predicate. Fix: `function hasGroup(item): item is NavSubItem & { group: string }`.
   Same category as other `as never`/`as unknown` casts already tracked in
   `anti-pattern-violations.md`.
4. **No `useMemo` around `groupNavSubItems(item.items)` call (Important):**
   `nav-main.tsx:67` — `NavMenuItem` re-renders on every `pathname` change
   (parent `NavMain` calls `useLocation()`), recomputing the Set/Map/sort for
   EVERY collapsible group in the whole sidebar on EVERY route navigation,
   even though `item.items` is referentially stable across those renders
   (verified: `useNavSections`/`useFilteredNavSections` only recompute on
   `[systems]`/`[abilities, userRoles]` changes, not on pathname). Cheap to
   fix, real "unnecessary re-render" per project review criteria.
5. **A11y (Important, not a hard WCAG failure):** the group label has no
   programmatic relationship (`aria-labelledby`/`role="group"`) to the items
   that follow — grouping is conveyed only via linear DOM reading order +
   visual styling (border/uppercase/opacity). Screen reader users get the
   info sequentially (not a blocker) but it's a step below WAI-ARIA APG
   pattern for grouped list items. Flagged 🟡, not 🔴.

## Minor/style
- Arbitrary `text-[11px]` instead of design-system `text-xs` (12px) token.
- Import order in `nav-main.tsx` breaks the codebase's evident alphabetical-
  by-path `@/` import convention (`cn` from `@/lib/utils` placed after the
  `@/shared/hooks/...` type import instead of before). Not ESLint-enforced
  (no import/order rule configured), so cosmetic only.
