---
name: package-module-isolation
description: Package (paquetería / Module 15) tenant-isolation audit WU-1 — package/carrier/type tenant-scoped, package_status central, resident unit-scoping double-safe, pickup_code per-tenant unique, recipient_user_id validation gap (read-path protected), withoutGlobalScope guard relations not exposed.
metadata:
  type: pattern
---

# Package module (Module 15 — Portería) — tenant isolation (audited 2026-06-29, WU-1 Backend Spine)

Mirror module: Access. Verdict: SAFE / ship. No CRITICAL cross-tenant leaks. One WARNING (recipient_user_id).

## Tenant-scoped models (correct)
- `package` — BelongsToTenant + `BaseModel::columns($table, true)`. Partial unique `pkg_pickup_code_tenant_unique` on `(tenant_id, pickup_code) WHERE deleted_at IS NULL` (raw DB::statement in migration — Postgres partial index, correct).
- `package_carrier` — BelongsToTenant + columns(true) + unique(['tenant_id','name']).
- `package_type` — BelongsToTenant + columns(true) + unique(['tenant_id','name']).

## Central catalog (correct)
- `package_status` — extends CentralCatalogModel, columns(false), 5 fixed UUID constants (RECEIVED/READY/PICKED_UP/RETURNED/INCIDENT). Seeded once by CatalogSeeder::seedPackageStatuses via upsertCatalog. PackageStatusController BLOCKS store/update/destroy/restore (MethodNotAllowedHttpException) — read-only for tenants. Code branches on it (FSM) → correctly central.

## Resident unit-scoping (PackageController — DOUBLE-SAFE)
`scopeToResidentUnitsIfNeeded` (index+show): if `tokenCan('create.package')` or `tokenCan('*')` → return full query (guards/admins see ALL tenant packages, still TenantScope-bound). Else → `whereIn('unit_id', UnitUser::where('user_id',auth)->whereNull('end_date')->pluck('unit_id'))`. UnitUser HAS BelongsToTenant → subquery tenant-scoped. Package query also TenantScope-bound. unit_id is a global UUID so even if UnitUser weren't scoped, a foreign unit_id can't match a current-tenant package. No cross-tenant leak.
- Permission naming convention is `{action}.{key}` (create.package, read.package) — `tokenCan('create.package')` is CORRECT (matches Access/Chat/Projects pattern, e.g. read.access_log). Verified against permissions.csv.

## pickup_code (per-tenant, SAFE)
- Hidden by default ($hidden=['pickup_code']); revealed via `revealPickupCodeForUnitMembers` ONLY to active members of each package's unit (UnitUser tenant-scoped lookup, batched). afterIndex/afterShow/afterSearch hooks.
- `generateUniquePickupCode`: `Package::where('pickup_code',$code)->exists()` is TenantScope-bound + excludes soft-deleted (matches partial index WHERE deleted_at IS NULL). Same code can coexist across tenants. Bounded 10-attempt loop. No global collision assumption.

## Guard user relations — withoutGlobalScope(UserTenantScope) is SAFE here
- `receivedByUser`/`pickedUpByUser` bypass UserTenantScope (mirrors UnitUser::user() + access_log.guard_user_id: central user table, may be soft-deleted, no FK on received_by_user_id/picked_up_by_user_id columns).
- NOT in includes() (`['unit','status','carrier','type','recipientUser','media']`) → never exposed via Orion includes. Grep confirms no Http/Service usage beyond `received_by_user_id = $guard->id` write. received_by_user_id always = authenticated guard (current tenant). No cross-tenant resolution in practice.
- `recipientUser` (IS in includes) does NOT bypass UserTenantScope → a cross-tenant recipient_user_id resolves to NULL on read. Read-path protected.

## WARNING: recipient_user_id validation gap (NOT a cross-tenant leak)
PackageCreateRequest:17 / PackageUpdateRequest:16 / PackageBatchRequest:17 — `recipient_user_id` is `['nullable','uuid','string']` with NO `Rule::exists` and NO tenant scope, while unit_id/package_carrier_id/package_type_id all use `Rule::exists(...)->where('tenant_id', tenant('id'))`. A guard could store an arbitrary/cross-tenant user UUID (DB FK requires a real global user). Read-path is UserTenantScope-protected (resolves null) so NO PII leak; only a data-integrity gap + extremely weak 201-vs-500 existence oracle (UUIDs non-enumerable). Fix: scope recipient_user_id to current-tenant users (or the unit's active residents).

## Notifications + Settings (correct)
- PackageService::register → maybeNotifyArrival → unitResidentUserIds (NotifiesResidents): `UnitUser::where('unit_id',...)` tenant-scoped. notifyUsers defers via DB::afterCommit. Settings gate via `Setting::getValue('package.*')` — Setting HAS BelongsToTenant → tenant-scoped read.
- Seeders: package_status CENTRAL once (CatalogSeeder). PackageCarrier/PackageType + 7 `package.*` settings PER-TENANT via TenantCatalogSeeder (runs inside tenancy, firstOrCreate stamps tenant_id, never overwrites admin edits). Correct.

## Routes / middleware (correct)
All routes under `['auth:sanctum','rejectRefreshToken','authActive','initializeTenancy']`. No public/super-admin route skips tenancy. Custom routes declared before Orion::resource. WU-2/WU-5 endpoints are 501 stubs.

## No dangerous patterns
No whereRaw / DB::select / Tenant::all() / ->on('central') in Modules/Package. module.json has no stale "tenancy" key (correct — classification is by columns(true)+BelongsToTenant).

---

# WU-2 (Pickup & Custody) — audited 2026-06-29. Verdict: BLOCK. One CRITICAL.

## CRITICAL: implicit route-model binding on tenant routes resolves cross-tenant (return/incident/reassign)
- Routes `package/{package}/return|incident|reassign` use Laravel IMPLICIT binding (`Package $package` in PackageController::returnPackage/flagIncident/reassignUnit). This is the ONLY place in the whole project that uses implicit binding on a tenant model — every sibling (Housing `{unitId}`, Tenant `{tenantId}`, SubscriptionPlan `{planId}`, Reports `{key}`) uses a STRING id param + manual `Model::find()` inside the controller.
- PROVEN middleware order for `api/package/{package}/return` (via tinker SortedMiddleware): STEP2 `SubstituteBindings` runs BEFORE STEP6 `InitializeTenancyFromUser`. There is NO custom `$middlewarePriority` in bootstrap/app.php, so default priority places SubstituteBindings early.
- Stancl `TenantScope::apply` returns EARLY (no tenant_id filter) when `! tenancy()->initialized` (vendor/stancl/.../TenantScope.php). So at binding time the Package query has NO tenant scope → resolves a package of ANY tenant by raw id.
- Controllers do NO in-method tenant re-check on $package (returnPackage/flagIncident only check status==READY; reassignUnit checks the NEW unit's tenant via Unit::find but NOT the bound package). `authorize('update.package')` is a token-ability check (tenant-independent) so any Tenant-A security/admin passes.
- Impact: Tenant A guard → `POST /api/package/{B_pkg_id}/return` flips B's READY package to RETURNED (vandalism); `/incident` → INCIDENT; `/reassign` sets B's package unit_id to an A unit (tenant_id stays B since BelongsToTenant only stamps on create). Cross-tenant WRITE breach.
- Why tests are green & missed it: pickup/lifecycle tests only pass SAME-tenant package ids to these routes. `test_reassign_to_unit_in_different_tenant_returns_422` tests a foreign UNIT (caught by in-controller Unit::find), NOT a foreign PACKAGE — false confidence.
- FIX (match sibling convention): change routes to `{packageId}` string param; resolve `Package::findOrFail($packageId)` INSIDE each controller method (runs after STEP6 → TenantScope active → foreign id 404). Add regression test: Tenant-A guard hitting Tenant-B package id → 404. (Global alt: register InitializeTenancyFromUser in `$middlewarePriority` before SubstituteBindings — fixes all implicit bindings but riskier; convention here is string-id + manual find.)
- CORRECTION to WU-1 note "Routes / middleware (correct)" line above: that held for WU-1 (no model-bound custom routes). WU-2 introduced the first `{package}` bindings, which are NOT safe under this middleware order.

## SAFE in WU-2 (verified)
- `verifyPickupCode`: `Package::where('pickup_code',$code)` runs in service AFTER tenancy init → tenant-scoped 404 cross-tenant; response maps a fixed shape, NEVER makeVisible(pickup_code). No code leak.
- `handover`: `Package::whereIn('id',$packageIds)->get()` tenant-scoped; `count !== count($ids)` → 422 (foreign/duplicate id rejected, no foreign pkg retrievable); response returns models with pickup_code still $hidden. Policy pre-flight (signature/evidence) before txn. Notifies first unit's residents (tenant-scoped unitResidentUserIds). Atomic.
- `myPackages`: staff-exclusion (tokenCan create.package → 403); UnitUser + Package both tenant-scoped; makeVisible(pickup_code) only on caller's own units.
- TenantCatalogSeeder::seedPackageSettings: 7 `package.*` Setting::firstOrCreate(['slug'=>...]) — Setting is BelongsToTenant, runs per-tenant in tenancy → each tenant its own rows, admin edits preserved. Correct.
