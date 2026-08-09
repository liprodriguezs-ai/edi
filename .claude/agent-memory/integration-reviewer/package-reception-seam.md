---
name: package-reception-seam
description: Package (paquetería) module seam back↔front. Custom + Orion endpoints, envelope map, pickup_code visibility, media collection-name bug, search/searchableBy drift. Reviewed 2026-06-30 on feat/package-reception (module still UNTRACKED — git diff main shows nothing; read files directly).
metadata:
  type: project
---

# Package Reception seam (reviewed 2026-06-30, branch feat/package-reception)

Whole module is UNTRACKED on both repos (`?? Modules/Package/`, `?? src/modules/package/`). `git diff main` shows NOTHING — read files directly, do not rely on diff.

## Endpoint envelope map (source of truth = PackageController / PackageReportController / PackageService)
- Custom routes declared BEFORE `Orion::resource('package')` so `package/{id}` wildcard doesn't swallow them. `{packageId}` is a STRING param resolved inside the controller (after initializeTenancy) — NOT implicit binding (avoids cross-tenant fetch before TenantScope is active).
- `GET /package/pickup-policy` → TOP-LEVEL `{requires_pickup_signature, requires_pickup_evidence}` (NOT wrapped). Requires `update.package` (so guards read it WITHOUT read.configuration). FE reads `data` directly. ALIGNED.
- `POST /package/verify-code` body `{pickup_code}` → TOP-LEVEL `{valid, unit:{id,full_identifier}|null, packages:[{id,description,storage_location,received_at,tracking_number,package_carrier:{id,name}|null,package_status:{id,name}|null}]}`. Uses SNAKE relation keys (package_carrier/package_status). pickup_code NEVER serialized. FE `IVerifyCodePackage` accepts BOTH snake (verify path) AND Orion keys (carrier/status, used when modal reuses a full IPackage via initialPackage). ALIGNED.
- `POST /package/handover` → `{data: Package[]}`. FE type says `{count:number}` — WRONG (drift, but no consumer reads .count; modal uses packageIds.length).
- `GET /my-packages` → `{data: Package[]}` (non-terminal only, pickup_code made visible). FE reads data.data.
- `POST /package/intake-batch` → `{data: Package[]}` 201. FE `batch()` reads data.data. (Name avoids Orion's POST /package/batch which expects `resources` not `packages`.)
- `POST /package/{id}/return|incident|reassign` → `{data: Package}`. FE reads data.data. Bodies: return/incident `{reason}` (required, max:500), reassign `{unit_id}` (required uuid). ALIGNED.
- `GET /package/report/aging` → TOP-LEVEL `{generated_at, total_pending, buckets:{d0_2,d3_7,d7_plus}:{count,packages:[{id,unit_full_identifier,carrier,received_at,storage_location,days_pending}]}}`. FE reads data directly. ALIGNED (IAgingReport exact).
- `GET /package/report/pending-count` → TOP-LEVEL `{total, d7_plus}`. ALIGNED.
- `GET /package/report/aging/export` → xlsx blob (responseType blob). Dedicated, does NOT use legacy POST /customReport. ALIGNED.

## Catalog sub-resources
- Orion resources: `package_status` (index/show only — central FSM CentralCatalogModel), `package_carrier`, `package_type` (withSoftDeletes, tenant-scoped). FE CarrierResource/TypeResource basePath `/package_carrier` /`/package_type`. Forms send name/is_active/order — match PackageCarrier/TypeCreateRequest. ALIGNED.
- `/selects` mapper (AngularController) HAS `PackageCarrier`/`PackageType` registered (StudlyCase keys, Str::studly('package_carrier')='PackageCarrier'). usePackageCatalogOptions uses CatalogType.PACKAGE_CARRIER/PACKAGE_TYPE/UNIT — all resolve. `_filters`/`_sort` handled by ApiHandler::parseMultiple inside selects. ALIGNED.
- PackageStatus UUID constants == FE hardcoded UUIDs (READY ...002, PICKED_UP ...003, RETURNED ...004, INCIDENT ...005). Branched on in FE list/detail/rowactions. ALIGNED.

## PackageController whitelists
- includes(): unit, status, carrier, type, recipient_user, media. Detail page requests EXACTLY these subset (unit,status,carrier,type,recipient_user,media). ALIGNED. NOTE: received_by_user / picked_up_by_user are model relations but NOT in includes() → cannot be eager-loaded; FE hardcodes "Personal de portería" so no 422.
- filterableBy: unit_id, package_status_id, package_carrier_id, package_type_id, received_at. FE list filters status/unit/carrier with operator 'in'. ALIGNED.
- sortableBy: received_at, picked_up_at. searchableBy: tracking_number, carrier_other, description, storage_location.

## Issues found (2026-06-30)
- **CRITICAL — media collection name**: PackageDetailPage.tsx:36 `m.collection_name === 'package_photo'` but backend collection is `'photo'` (Package model addMediaCollection('photo'); PackageService toMediaCollection('photo')). Intake photo NEVER renders. Fix FE → 'photo'.
- **WARNING — handover type**: PackageResource handover typed `{count:number}` but BE returns `{data:Package[]}`. Latent; align to IPackage[].
- **WARNING — search drift**: list placeholder "Buscar por destinatario o tracking…" but searchableBy lacks `recipient_name` → name search returns nothing. Add recipient_name to searchableBy (real column).
- **WARNING — pickup_code as status proxy**: MyPackagesPage splits pending/history by `pickup_code` presence. pickup_code is gated by setting `package.pickup_code_enabled` AND unit membership; my-packages already excludes terminal statuses (only READY returned). If code disabled cluster-wide, pending bucket is empty & residents get no QR. Prefer package_status_id===READY.

## pickup_code visibility contract (security)
Package $hidden=['pickup_code']. Revealed via makeVisible ONLY to active unit members: index/show afterIndex/afterShow + afterSearch reveal; my-packages reveals to owning resident; verify-code NEVER reveals. List scope: create.package OR read.report_package OR '*' see all tenant packages; residents scoped to own active units (unit_user without end_date).
