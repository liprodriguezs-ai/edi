---
name: responsive-data-tables
description: Findings from the responsive-data-tables change — ResponsiveDataTable component, 7 list-view migrations, a11y fixes
metadata:
  type: project
---

## Introduced in change `responsive-data-tables`

**What:** New shared `ResponsiveDataTable<T extends IEntity>` component (desktop table + mobile dl-cards) migrated 7 list pages (Unit, Provider, BankAccount, Announcement, Expense, Payment, CatalogCrudModule). Also fixed: `aria-hidden` on decorative `<i>` dots in StatusPill/CatalogPill, login Checkbox label linkage.

**Key patterns established:**
- CSS-only dual-branch (both table and cards always in DOM, CSS hides one). Good: avoids `useIsMobile` hydration flicker.
- `role="list"` on the `<ul>`, `role` is implicit for `<ul>/<li>` but explicit here — ok.
- `renderActions` wrapper div uses `onClick={e.stopPropagation()}` — correct.
- All call sites correctly added `scope="col"` to `<TableHead>`.

**Known issues found in this change (see main review for details):**
- `BankAccountListPage` has a duplicated footer: the original desktop footer `mt-[-1px] hidden md:block` div was LEFT inside the `<>` alongside the new `ResponsiveDataTable`, AND a new `md:hidden` footer was added. But the original desktop footer is now orphaned (no longer inside the table wrapper). Visual bug on md+ — the footer border and rounding are disconnected from the table.
- `renderActions` aria-labels in card mode are generic (`"Editar cuenta bancaria"`, `"Editar gasto"`, `"Editar proveedor"`) instead of entity-specific, unlike the table mode counterpart.
- `renderCardTitle` for UnitListPage uses `unit.full_identifier ?? unit.number` — `unit.number` is typed as string, but `full_identifier` is probably a string too; low risk.
- The `onClick` div wrapping `renderActions` in the component is not a button and has no keyboard handler — `stopPropagation` is only on click, not keydown. If someone navigates into the card wrapper and presses Enter, the row-click fires before the action button gets it.
- ExpenseListPage `renderActions` aria-label for download buttons is generic ("Descargar comprobante") regardless of `collection_name` — loses the CFDI distinction present in the desktop `renderRow`.

**Why:** reference for future reviews on these pages; all these modules now use the ResponsiveDataTable abstraction.
