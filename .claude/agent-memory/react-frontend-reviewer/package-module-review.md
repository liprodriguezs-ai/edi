---
name: package-module-review
description: Package (paquetería) module final review — build was BROKEN (22 tsc errors); 5 root causes + functional bugs (reassign, detail relations, badge color)
metadata:
  type: discovery
---

# Package module (feat/package-reception) — final holistic review (2026-06-30)

Module under `frontend/src/modules/package/` (intake, pickup QR, custody, aging, config). At final-gate review `npx tsc -b` FAILED with **22 errors**, all inside the module → `pnpm build` (tsc -b && vite build) is broken. The per-slice reviews never ran a full typecheck. ALWAYS run `npx tsc -b` on a whole-module final gate.

## Build-breaking root causes (CRITICAL)
1. **`IPackage.description?: string | null`** conflicts with `IEntity.description?: string` → "incorrectly extends IEntity" (schema.ts:24/15). Cascades into ~10 errors: `createEntityStore<IPackage>`, `useBaseList<IPackage>`, `ResponsiveDataTable<IPackage>` constraint failures + bogus TS6133 "unused import" noise in PackageListPage. Fix: `description?: string`. LESSON: IEntity declares `name`/`description` as non-null `string`; module entity interfaces must NOT widen those to `| null`.
2. **`displayFormatDate(x, 'shortDate')`** — helper only accepts `'medium' | 'mediumDate'`. Type error + wrong runtime (falls to default = date WITH time). 6 sites. Use `'mediumDate'` for date-only.
3. **`new ApiError({ message: 'Error' }, 500)`** — ctor is `(message: string, status?, payload?)`. Pass a string: `new ApiError('Error', 500)`. 4 sites.
4. **`<TableEmptyState icon={<JSX/>}>`** — `icon` prop is `'plus'|'search'|'default'`, NOT a ReactNode. Remove the prop (default Inbox renders). 2 sites.
5. **`PackageResource.show(id, { includes: [{relation}] })`** (PackageDetailPage:153) — `show`'s 2nd param is `Partial<TForm>`; correct convention is `show(id, { include: 'unit,status,carrier,type,recipient_user,media' })` (comma string; Orion reads `?include=`). As-written it's a type error AND loads NO relations at runtime → detail page shows no photo/labels.

## Functional / UX bugs (WARNING)
- **ReassignDialog never loads units** (PackageRowActions:169-180): `enableUnits()` is wired inside Radix `Dialog onOpenChange` open-branch, but the dialog is CONTROLLED (opened via `isOpen` prop, no DialogTrigger) so onOpenChange never fires on programmatic open → unit catalog never enabled → reassign SearchSelect options stay `[]`. Reassign is unusable. Wire `enable()` to the parent's open handler instead.
- **All status badges render blue** (PackageListPage/Detail/MyPackages): every `PackageStatusBadge` is called with `statusKey={null} statusColor={null}`, so KEY_TONE/color logic is dead → picked_up/returned/incident all look identical (tone 'b'). Also `IPackage.status` is typed `INamed` ({id,name}) dropping the `key`/`color` the badge needs. Type `status?: IPackageStatus` and pass `statusKey={pkg.status?.key}`.
- **MyPackages pending/history split keyed on `pickup_code` presence**, not status (MyPackagesPage:190-191). When `pickup_code_enabled=false` (a supported admin toggle), ready packages have null code → wrongly land in "Historial". Gate on status key.
- **Hardcoded `PACKAGE_STATUS_READY = 'a2001001-…'` duplicated in 3 files** (PackageListPage, PackageDetailPage, PackageRowActions). DRY + fragile. Extract one shared constant (schema.ts).

## What was clean
carrier/type sub-modules (CatalogCrudModule + createCrudApi + createEntityStore), `usePackageCatalogOptions` lazy hook, `_filters` catalog param, MultiSelectFilter onFirstOpen/compact/isLoading wiring, PackagePickupModal setState-in-effect refactor (key-remount pattern is sound), PackageForm render-time mode reset, App.tsx lazy routes, iconMap IconBox.
