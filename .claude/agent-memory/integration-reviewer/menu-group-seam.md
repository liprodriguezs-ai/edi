---
name: menu-group-seam
description: GET /api/menu module.group field (sidebar sub-grouping inside "Catálogos") — contract review 2026-07-02
metadata:
  type: project
---

Reviewed 2026-07-02. `module.group` (nullable string, `Modules/Module/database/migrations/2025_02_10_041724_create_module_table.php:19`) added to the `GET /api/menu` payload in `Modules/System/app/Http/Controllers/MenuController.php:134` (top-level module items) and `:152` (nested modules inside a `type: 'group'` item, e.g. "Catálogos"). Both branches share the SAME `$systems->map()` closure regardless of which of the 3 system-resolution paths ran (super-admin Core-only / tenant `getActiveSystems` / no-systems-configured fallback) — confirmed there is no 4th payload-building path that could omit `group`.

## Contract
- Backend: `group: string|null`, no `$hidden`/cast interference on `Module` model, no `->select()` restricting columns anywhere in the query chain (`SystemTenantService::getActiveSystems`).
- Frontend: `IMenuModule.group` / `IMenuItemModule.group` typed `string | null` in `frontend/src/shared/interfaces/MenuSystem.ts` — exact match, no drift.
- `useNavSections.ts:52` converts `mod.group ?? undefined` when building `NavSubItem` — same established pattern as `permission_entity ?? undefined`.

## By-design partial consumption (NOT a bug)
Top-level module items (`item.type === 'module'` branch in `systemItemToNavItem`, `useNavSections.ts:34-42`) do NOT propagate `group` into `NavItem` — only sub-items of a collapsible group (`item.items`, consumed by `groupNavSubItems.ts`) carry it forward. `IMenuItemModule.group` is received but intentionally unused for top-level items since `groupNavSubItems` only operates within an already-collapsible group's sub-items. Don't flag this as drift in future reviews unless the scope changes.

## Pure helper: groupNavSubItems.ts
`frontend/src/shared/utils/groupNavSubItems.ts` returns `null` when 0-1 distinct non-blank `group` values exist among sub-items (caller then renders flat, unchanged behavior) — defensively treats `''`/whitespace-only `group` as absent even though backend only ever sends `null` or a real value today. 6 unit tests cover multi-group alphabetical ordering (`localeCompare('es')`), the "Otros" bucket for groupless items trailing after named groups, and all null-return thresholds.

## Permission filtering unaffected
`useFilteredNavItems.ts`'s `isItemVisible`/`useFilteredNavSections` filter only by `permissionEntity`/`roles`, preserve `group` untouched via object spread (`{ ...item, items: filteredChildren }`) — grouping happens in `nav-main.tsx` AFTER permission filtering already ran, confirmed no permission bypass.

## Verdict
ALIGNED, 0 criticals, 0 warnings. Backend test `test_menu_module_items_include_group_field` passed (1 passed, 9 assertions) covering both top-level and nested branches. `groupNavSubItems.test.ts` passed (6/6). No other consumer (`command-palette.tsx` flattens sections without needing `group`; `app-sidebar.tsx` untouched) breaks.
