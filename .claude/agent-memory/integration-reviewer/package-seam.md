---
name: package-seam
description: Package (Paquetería) module back↔front contract seam. WU-6 catalog admin UI review (carrier/type). Two CRITICAL contract bugs found 2026-06-29.
metadata:
  type: project
---

# Package module seam (back ↔ front)

Reviewed WU-6 (catalog admin UI: package_carrier + package_type CatalogCrudModule pages, PackageStatusBadge, App.tsx routes) on 2026-06-29.

## ALIGNED (verified)
- basePaths: FE `/package_carrier` & `/package_type` ↔ BE `Orion::resource('package_carrier'|'package_type')`. snake_case, correct.
- Permission gating: FE `PermissionRoute permissionEntity="package_carrier"` → `read.package_carrier` OR `report.package_carrier`; `CatalogCrudModule` gates create/update/delete via `{verb}.package_carrier`. BE seeds read/create/update/delete.package_carrier + .package_type. Roles: security=read only (buttons hidden), administrator=full CRUD, account.owner/company.admin inherit via whereNotIn. Fully aligned.
- Entity shape IPackageCarrier/IPackageType (name, is_active?, order?, tenant_id? + IEntity) ↔ BE columns (name, is_active bool cast, order int) + BaseModel. Match.
- PackageStatusBadge: keys (received/ready/picked_up/returned/incident) + hex colors match CatalogSeeder (#6B7280/#3B82F6/#10B981/#F59E0B/#EF4444). statusColor `${hex}20` alpha trick valid because seeder stores 6-digit hex. StatusPill tones g/y/r/b valid.

## CRITICAL bug pattern — `order: null` rejected (carrier + type)
- Shared `CatalogCrudModule.tsx` L271 `setValueAs: v => (v===''||v==null ? null : Number(v))` → a blank number field submits **null**. buildDefaults defaults `order: entity?.order ?? null`. FE Zod `order: z.number().int().nullable()` passes null. createCrudApi sends body verbatim (no null strip).
- BE `PackageCarrierCreateRequest`/`PackageTypeCreateRequest` use `'order' => ['integer','min:0']` — **missing `nullable`** → present-null fails `integer` → 422. (Update requests same, with `sometimes`.)
- DB: `package_carrier.order` & `package_type.order` are **NOT NULL** (live: is_nullable=NO). Canonical `tower.order` is **nullable=YES**. So even past validation, INSERT null → 500.
- Canonical tower works because: tower FormRequest `['nullable','integer','min:0']` AND tower.order column nullable. Package diverged on BOTH layers.
- Fix: add `'nullable'` to `order` in all 4 package carrier/type FormRequests + ALTER package_carrier/package_type.order to nullable (match tower). Breaks the default create flow (admin adds "DHL", leaves Orden blank → fails).
- LESSON: any catalog built on `CatalogCrudModule` with an optional number field MUST have that column `nullable` in DB + `nullable` in the FormRequest, because the shared component always sends null for empty. tower is the contract reference.

## CRITICAL — menu route mismatch
- BE `SystemTableSeeder::seedModuleRoutes` L313-314: 'Transportistas'=>'/package-carrier', 'Tipos de Paquete'=>'/package-type' (also 'Paquetería (Reportes)'=>'/package/reports').
- FE App.tsx routes (WU-6): `/package/carriers`, `/package/types`.
- `/package-carrier` ≠ `/package/carriers`. Menu link (built from module.route) → no matching App.tsx route → 404. WU-6 pages unreachable via seeded menu.
- Fix: align the two strings on one convention; reseed.

## SUGGESTION (WU-6)
- FE carrier/type Zod `name` has no `.max(100)` mirroring BE `max:100` → oversized name passes FE, 422 from BE. Add `.max(100)` for inline feedback.

## WU-7 Frontend core review (2026-06-29) — 4 CRITICAL seam breaks

1. **verifyCode param name**: FE `PackageResource.verifyCode` posts `{ code }` (PackageResource.ts:31); BE `PackageController::verifyCode` validates `pickup_code` required (PackageController.php:142-146). Every QR-scan verify 422s → scan→handover flow 100% broken. (Handover from the list "Entregar" button still works — it skips verify-code via `initialPackage`.) Fix: send `{ pickup_code: code }`.

2. **/config/package-settings key prefix mismatch (ROOT CAUSE for 2 bugs)**: BE `getPackageSettings`/`updatePackageSettings` use FLAT keys (slugMap maps flat→`package.*`, ConfigurationController.php:1050-1116). Unlike collections (dotted on BOTH sides), the package endpoint deliberately strips the prefix.
   - `PackageConfigSection.tsx` (137-195) reads/writes `package.*`-prefixed keys → GET shows all-blank; PUT `$request->only([flat])` drops everything → persists nothing but returns 200 + "guardada" toast (silent no-op, lies to user). Also renders `package.max_days_in_custody` (NOT a backend slug) and OMITS `liability_disclaimer` (real slug + spec field).
   - `PackagePickupModal.tsx` (401-404) reads `policyQuery.data?.['package.requires_pickup_*']` → always undefined→false. Evidence field never shows → clusters with `requires_pickup_evidence=true` can NEVER hand over (server enforces, modal has no field → permanent 422).
   - LESSON: package config endpoint uses FLAT keys; collections uses DOTTED. They are NOT the same convention — FE was written against the collections (dotted) mold but BE diverged to flat.

3. **/selects mapper missing package catalogs**: `AngularController::$mapperSelects` (app/Http/Controllers/AngularController.php:70-137) has NO `PackageCarrier`/`PackageType`/`PackageStatus`. `selects()` does `Str::studly('package_carrier')='PackageCarrier'` → not in mapper → `continue` → empty. So intake carrier/type dropdowns (PackageForm) + carrier filter (PackageListPage) are ALWAYS empty. UNIT is mapped so unit picker works. Admin catalog CRUD (WU-6) uses Orion `/package_carrier` directly so it's unaffected. Fix: add PackageCarrier/PackageType (+PackageStatus) to `$mapperSelects`.

## WU-7 WARNINGs
- **Excel export miswired**: PackageListPage.tsx:235-237 calls `PackageResource.report({_model:'package',_report_type:'aging'})` → generic `POST /customReport` (resourceApi.ts:106). customReport resolves the Package MODEL's `reportColumns()` = BaseModel default (Nombre/dates), dumps ALL packages unfiltered, ignores aging/READY. The dedicated, correct `GET /package/report/aging/export` (with the controller's aging columns) is NEVER called. PackageResource has no `export` method. Fix: add `export()` custom method → GET that route (responseType blob).
- **batch toast "undefined"**: PackageForm.tsx:325 reads `result.count`; BE `batchRegister` returns `{ data: Package[] }` (no count). Toast literally renders "undefined paquetes registrados". Batch itself succeeds. Fix: FE read `data.length` or BE add `count`.
- **verify-code response shape drift**: PackageService::verifyPickupCode (PackageService.php:237-246) returns packages with key `package_carrier` + reduced fields (no `status`/`tracking_number`); FE types them `IPackage[]` and reads `pkg.carrier?.name`/`pkg.status?.name`/`pkg.tracking_number` (PackagePickupModal SelectStep) → all undefined → carrier/status/tracking never render in the pickup select step. Fix: BE emit `carrier` (not `package_carrier`) + include status/tracking, or FE type a reduced interface.
- **carrier "Otro" sends invalid id**: PackageForm single intake SelectItem value `__other` (PackageForm.tsx:194) → submits `package_carrier_id='__other'` → BE `exists:package_carrier` 422; no `carrier_other` text input is rendered. Fix: on `__other`, clear package_carrier_id + show carrier_other input (schema already has the field).

## WU-7 ALIGNED (verified)
- `/package/intake-batch` (FE) ↔ BE route (avoids Orion's `/package/batch`). ✓
- returnPackage/incident/reassign: FE `{data:IPackage}` unwrap ↔ BE `{data:$updated}`. ✓
- myPackages: FE `data.data` IMyPackage[] ↔ BE `{data:$packages}` with pickup_code makeVisible + carrier/type/status loaded. ✓
- aging/pendingCount: BE `response()->json($service->...())` flat (no envelope) ↔ FE returns `data` directly. IAgingReport buckets {d0_2,d3_7,d7_plus}{count,packages} + IPendingCount {total,d7_plus} match. ✓
- handover request: FE FormData `package_ids[]`+proxy+`pickup_signature`/`pickup_evidence` ↔ BE PackagePickupRequest. ✓ (only the policy-display reading is broken, see #2)
- Routes/integration mounts: App.tsx `/package`+`/my-packages` (permissionEntity="package"), ConfigurationPage Paquetería tab + PackageConfigSection, TreasurerHome PendingPackagesWidget — all wired. ✓
