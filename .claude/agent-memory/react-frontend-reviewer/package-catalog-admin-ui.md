---
name: package-catalog-admin-ui
description: Package module WU-6 catalog admin UI (carrier/type) review findings — order:null seam bug + naming notes
metadata:
  type: project
---

## Package module — WU-6 catalog admin UI (carrier/type) — reviewed 2026-06-29

Branch `feat/package-reception`. Files: `modules/package/{carrier,type}/*`, `components/PackageStatusBadge.tsx`, App.tsx routes.
Both catalog pages use `CatalogCrudModule` (canonical Tower pattern). basePaths correct snake_case `/package_carrier`, `/package_type`. No `any`, Spanish UI, schema.ts types — all clean.

### WARNING (confirmed seam bug): `order: null` breaks carrier/type CREATE
- `carrier/schema.ts:7` + `type/schema.ts:7` use `order: z.number().int().nullable()`; `*ListPage.tsx:21` buildDefaults use `order: entity?.order ?? null`. `createCrudApi.create` JSON-sends `order: null` when "Orden de aparición" is left blank.
- Backend `PackageCarrierCreateRequest`/`PackageTypeCreateRequest` (+Update) validate `'order' => ['integer','min:0']` WITHOUT `nullable` — DIVERGES from canonical `TowerCreateRequest` which has `['nullable','integer','min:0']`. Column is `integer NOT NULL DEFAULT 0`.
- Laravel `integer` on a present-null value without `nullable` FAILS validation → **422 on the primary "add new carrier/type" flow** (field is not marked required; blank is the expected case).
- Robust in-scope fix: coerce to 0 in schema — `order: z.number().int().min(0).nullable().transform((v) => v ?? 0)` (and buildDefaults `?? 0`), so 0 (the DB default) is sent. Backend alt: add `nullable` to the 4 package carrier/type requests to match Tower (note: Tower's column is also NOT NULL default 0 and works with nullable, so Tower's null path is handled somewhere — verify before relying on it).
- Note: WU-1 backend tests create carriers WITH order set, so they pass while the UI blank-order path breaks. Classic factory-vs-UI gap.

## Package module — WU-7 frontend core — reviewed 2026-06-29 (VERDICT: block)

Branch `feat/package-reception`. Files: `modules/package/{schema.ts, services/PackageResource.ts, store/packageStore.ts, PackageListPage, PackageForm, PackagePickupModal, MyPackagesPage, components/PendingPackagesWidget}`, `modules/configuration/{components/PackageConfigSection, ConfigurationPage}`, `treasurerPanel/components/TreasurerHome`, `App.tsx`, `shared/hooks/useCatalogs`.
Good: no `any`, Spanish UI, createEntityStore/useBaseList/ResponsiveDataTable/sileo toast/boneyard Skeleton/RHF+Controller+Zod/qrcode.react all correct. Lazy catalogs wired right. PackageDetailPage (7.8) + tests (7.12) NOT implemented (tasks unchecked).

### CRITICAL (verified vs backend ConfigurationController + ReportController)
1. **Radix empty-string SelectItem crashes intake** — `PackageForm.tsx:190,217,407,433` `<SelectItem value="">Sin especificar</SelectItem>`. `ui/select.tsx` is radix-ui; Item with value="" throws on dropdown open. Only PackageForm does this (4×, count==4 repo-wide). Crashes single+bulk carrier/type dropdowns. Fix: remove clearing item, use placeholder + `value={field.value || undefined}`.
2. **PackageConfigSection key-prefix mismatch** — reads/writes `package.*` keys but backend `getPackageSettings()` strips the `package.` prefix (returns flat `auto_notify_on_arrival`…) and `updatePackageSettings()` `$request->only(array_keys($slugMap))` accepts ONLY flat keys. `useSectionEdits` does `{...server,...edits}` (no re-key). → GET shows all toggles blank/default; PUT persists nothing. Whole Paquetería config tab dead. Fix: flat keys throughout.
3. **PackageConfigSection phantom field** — renders `package.max_days_in_custody` (NOT a real slug) and OMITS `liability_disclaimer` textarea (the real 7th slug, required by task 7.9.1). Fix: replace with `<Textarea>` flat key `liability_disclaimer` max1000.
4. **PackagePickupModal policy prefix** — `PackagePickupModal.tsx:402-403` reads `data['package.requires_pickup_signature']`/`['…evidence']`; backend returns flat keys → always false → required signature/evidence upload fields never render → backend handover 422s and guard can't supply file → handover impossible under those policies. Fix: flat keys.
5. **Export wired to wrong endpoint** — `PackageListPage.tsx:235-237` uses `PackageResource.report({_model:'package',_report_type:'aging'})` → `POST /customReport` (legacy ReportController switch has NO package case → 500). Real route is `GET /package/report/aging/export`. Resource has `aging()` but no `exportAging()` blob method. Fix: add custom blob GET method.

### WARNING
6. **"Otro" carrier trap** — `PackageForm.tsx:194` `<SelectItem value="__other">` sets `package_carrier_id='__other'` (invalid uuid) with NO `carrier_other` input rendered → backend `exists:package_carrier,id` 422. `carrier_other` is in schema but never shown. Fix: conditional input + clear id, or drop the option.
7. **Export gate wrong ability** — `PackageListPage.tsx:500` `<PermissionGuard permission="report.package">` but backend export authorizes `read.report_package`. president/treasurer/oversight hold `read.report_package`+`report.report_package` but NOT `report.package` → export hidden for exactly the oversight roles meant to use it. Fix: gate on `read.report_package`.

### SUGGESTIONS (WU-7)
- `PackageStatusBadge` always gets `statusKey={null} statusColor={null}` (PackageListPage/MyPackagesPage/PackagePickupModal) → WU-6 badge color/label mapping is DEAD; only raw name renders. Root: `IPackage.status` typed `INamed` (id+name) drops key/color. Widen type + pass `status.key`/`status.color`.
- `recipientUser` not in PackageListPage includes (line 193) AND interface reads snake `recipient_user` while backend method `recipientUser()` emits camelCase key → registered-recipient name never displays (recurring camel/snake mismatch, see Access module memory).
- Date-range filter (task 7.4.1) not implemented; `received_at` whitelisted in backend filterableBy but unused.

## Package module — WU-6 catalog admin UI (carrier/type) — reviewed 2026-06-29

### SUGGESTIONS
- `IPackageCarrier`/`IPackageType` redeclare `tenant_id?: string` — already in `IEntity`; canonical ITower omits it.
- Resource exports named `CarrierResource`/`TypeResource` (files CarrierResource.ts/TypeResource.ts) — generic and inconsistent with `usePackageCarrierStore`/`IPackageCarrier`; `TypeResource` collides with the "type" concept. Prefer `PackageCarrierResource`/`PackageTypeResource`.
- `PackageStatusBadge.tsx:54` builds opacity via `${statusColor}20` (assumes 6-digit hex → 8-digit). Breaks on 3-digit/named colors; low risk (central seeder controls hex). No `#` validation.
- `PackageStatusBadge` has NO consumer in WU-6 (staged for WU-7 package list). Ships as unused code if WU-6 is its own stacked PR — acceptable per the 7-slice plan, but confirm WU-7 lands.
- Fallback tones (received=blue vs seeder gray; returned=yellow vs seeder orange) only used when API hex absent; seeder always seeds color so hex path dominates. Matches task 6.3.1. Not a bug.
