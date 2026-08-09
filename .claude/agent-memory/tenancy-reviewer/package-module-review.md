---
name: package-module-review
description: Final holistic tenancy review of Modules/Package (Module 15, portería) on feat/package-reception — classification + isolation verdict SAFE
metadata:
  type: project
---

# Package Module (Module 15 — Portería) — Final Tenancy Review

Reviewed 2026-06-30 on branch `feat/package-reception` (module files untracked on disk; seeders committed). Tenant-isolation verdict: SAFE / ship.

**Classification (all correct):**
- `package_status` = CENTRAL FSM catalog. `BaseModel::columns($table, false)`, extends `CentralCatalogModel`, fixed UUID constants (`PackageStatus::READY` etc., prefix `a2001001-...`), seeded once by `CatalogSeeder::seedPackageStatuses()`. Controller routed `->only(['index','show'])` so tenants cannot mutate the platform FSM. Correct.
- `package_carrier`, `package_type` = TENANT-scoped label catalogs. `BaseModel::columns($table, true)` + `BelongsToTenant`, soft-delete-aware partial unique `(tenant_id, name) WHERE deleted_at IS NULL`, seeded per-tenant by `TenantCatalogSeeder`. Correct.
- `package` = TENANT-scoped. `columns($table, true)` + `BelongsToTenant`. Partial unique `(tenant_id, pickup_code) WHERE deleted_at IS NULL`. Correct.
- Package settings (`package.*` slugs) seeded per-tenant via `TenantCatalogSeeder::seedPackageSettings()`; `Setting` is `BelongsToTenant`, so `Setting::getValue` is per-tenant. Correct.

**Isolation mechanics verified:**
- User relations: `received_by_user` / `picked_up_by_user` strip `UserTenantScope` (guard may be soft-deleted / cross-scope) AND are deliberately NOT in `includes()` → cannot be eager-loaded via Orion (422 on non-whitelisted include) → no cross-tenant user leak. `recipient_user` KEEPS `UserTenantScope` and IS in includes → safe to expose. Raw `*_by_user_id` columns serialize as bare UUIDs of current-tenant guards (no PII).
- `pickup_code` is `$hidden`. Revealed only to active unit members via `makeVisible` in `afterIndex/afterShow/afterSearch` (`revealPickupCodeForUnitMembers`, scoped by memoized `UnitUser` active-unit ids) and in `myPackages` (own units). `verifyPickupCode` NEVER reveals it. Guards listing all packages do NOT get it (not unit members) → must scan QR.
- `buildIndexFetchQuery` + `buildShowFetchQuery` apply `scopeToResidentUnitsIfNeeded`: holders of `create.package` (guards/admins) OR `read.report_package` (board read-only: president/treasurer/oversight) OR `*` see all tenant packages; everyone else (`read.package` only = residents/renters) is `whereIn('unit_id', own active units)`. Base query is on `Package` (TenantScope) so even the "see all" branch is tenant-bounded. Orion `search()` delegates to `index()` → same `buildIndexFetchQuery`, so POST /package/search is scoped identically.
- Custom mutating routes use string `{packageId}` (NOT implicit model binding — implicit binding runs in SubstituteBindings BEFORE InitializeTenancyFromUser, which would fetch cross-tenant). Resolution is `Package::findOrFail()` inside the controller, AFTER `initializeTenancy` → tenant-scoped → foreign-tenant id 404s. `handover` uses `whereIn('id',$ids)->get()` + count check (tenant-scoped). `reassignUnit` validates target via `Unit::find()` (tenant-scoped) → cannot reassign to another tenant's unit.
- Retention cron `package:process-retention` scheduled once globally; iterates `Tenant::all()` + `$tenant->run()`, resolving a FRESH `PackageService` per tenant inside the context (no singleton binding — per-instance `settingCache` cannot leak across tenants). All reads via `Package`/`Setting` Eloquent (tenant-scoped). Uses tenant timezone.
- No raw `DB::` query on tenant data (only `DB::transaction`/`DB::afterCommit` control flow + `DB::statement` DDL for partial indexes). No `->on()/connection()` switching.
- Notifications go through `NotifiesResidents` trait → `unitResidentUserIds` uses tenant-scoped `UnitUser`. Safe.

**Role wiring (RolePermissionTableSeeder):** residents/renters get ONLY `read.package` + `read.package_status` (→ own-unit scope); security/president full ops; treasurer/oversight read+report_package (cluster-wide, intended). No resident gets create.package or read.report_package → no within-tenant neighbor leak.

**Non-isolation SUGGESTIONS (not blockers):**
1. `PackageReportService::pendingCount()` uses server tz (`now()->subDays(8)`) for the d7_plus cutoff while `aging()`/`agingExportRows()` use tenant tz — widget count can disagree with the report by ~1 day near boundaries for far-tz tenants. Both queries are tenant-scoped (no leak); pure correctness nit.
2. Global Spatie roles (documented accepted platform limitation): a user holding a board role (e.g. treasurer) from cluster A who is ALSO a plain resident of cluster B will, in cluster B context, see ALL of B's packages via `read.report_package`. Never cross-tenant (TenantScope-bounded to active tenant); identical to every other board-gated feature (Collections/Treasury reports). Not introduced by Package. Awareness only.
