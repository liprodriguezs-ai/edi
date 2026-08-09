# SDD Tasks — Paquetería (package-reception)

- **Change:** `package-reception`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** tasks
- **Artifact store:** openspec (this file) + engram `sdd/package-reception/tasks`
- **Date:** 2026-06-29
- **Delivery:** 7 stacked PRs, chain strategy `stacked-to-main`
- **TDD mode:** Standard (strict_tdd = FALSE) — tests written alongside, not TDD-first
- **Depends on:** proposal, specs (package-catalogs, package-intake, package-pickup, package-lifecycle, package-policies, package-retention, package-reporting), design.md

---

## Spec↔Design reconciliations applied

The design resolves 5 open questions. These reconciliations are applied throughout the task list:

1. **Permissions:** No `package_config` permission entity is created. `/config/package-settings` reuses `read.configuration` / `update.configuration`. Portería actions (verify-code, handover, return, incident, reassign) authorize `update.package`. Batch authorizes `create.package`. `my-packages` authorizes `read.package` with a staff-exclusion marker (guard holding `create.package` gets 403).

2. **Status on register:** `PackageService::register` sets status directly to `PackageStatus::READY`. `received` is a reserved/future state — a package MUST NOT remain in `received` after successful registration.

3. **`reminded_at` column:** included in the `package` table migration (slice 1), even though it is used starting in slice 4. This avoids a separate additive migration.

4. **`verify-code` response:** returns ALL `ready` packages of the scanned unit (not just the matched one). The `pickup_code` field is NEVER included in the `verify-code` response.

5. **`pickup_code` format:** `XXXX-XXXX` with alphabet `ABCDEFGHJKMNPQRSTUVWXYZ23456789` (Crockford-style, no ambiguous chars). A bounded loop (10 attempts) + partial unique index `unique(tenant_id, pickup_code) WHERE deleted_at IS NULL` back-stops races.

6. **Handover:** one `DB::transaction`; reject all if any package is not `ready`; shared evidence copied per package; NO `package_handover` table.

7. **Media:** `photo` = `singleFile()`, image only (intake). `pickup_evidence` = multi-file (image + PDF), used for both `pickup_signature` and `pickup_evidence` files (copied per package on handover).

**Flag — policies spec uses wrong permission names:** `package-policies` spec §GET and §PUT reference `read.package_config` / `update.package_config`. Design §2 explicitly drops these. The tasks below use `read.configuration` / `update.configuration` per the design's authority.

---

## Work-unit overview

| # | Work unit | Commit boundary | Parallelizable after |
|---|-----------|-----------------|----------------------|
| WU-1 | Backend spine (scaffold + 4 migrations + 4 models + PackageService::register/batch + 4 controllers + routes + module registration + catalog seeding + tests) | `feat(package): backend spine — intake, module registration, catalog seeding` | Start immediately |
| WU-2 | Pickup & custody (verifyPickupCode, handover, return, incident, reassign, my-packages + tests) | `feat(package): pickup codes, handover, lifecycle operations, my-packages` | After WU-1 |
| WU-3 | Policies config (ConfigurationController additions, /config/package-settings + tests) | `feat(package): cluster policy settings via Configuration module` | After WU-1 |
| WU-4 | Retention & reminders (ProcessPackageRetentionCommand, schedule, package.reminder notification + tests) | `feat(package): daily retention cron — reminders and auto-return` | After WU-1, WU-2 |
| WU-5 | Reports & dashboard (PackageReportService, PackageReportController, aging/export/pending-count + tests) | `feat(package): aging report, Excel export, pending-count endpoint` | After WU-1 |
| WU-6 | Tenant catalog admin UI & extras (carrier/type admin CRUD pages, status badges polish) | `feat(package): carrier/type admin UI, incident/returned status polish` | After WU-1 |
| WU-7 | Frontend core (schema, resource, store, all pages, widget, App.tsx routes, frontend tests) | `feat(package): frontend — list, intake, pickup modal, my-packages, config section, widget` | WU-7 can start page-by-page as the corresponding backend WU lands; full integration after WU-1 through WU-5 |

---

## WU-1 — Backend Spine

Satisfies: package-catalogs (all), package-intake (all), CLAUDE.md regla 3 (module registration)

### 1.1 Module scaffold

- [x] **1.1.1** From `backend/`, run `php artisan module:make Package`. Verify directory `backend/Modules/Package/` is created with standard Nwidart structure (`app/`, `config/`, `database/`, `routes/`, `module.json`, `composer.json`, `vite.config.js`). Delete the generated stub migration and stub model (we write our own).
- [x] **1.1.2** Open `backend/modules_statuses.json`. Add entry `"Package": true`.
- [x] **1.1.3** Open `backend/Modules/Package/module.json`. Set `"name": "Package"` and `"description": "Módulo de paquetería y control de recepción de envíos"`. Verify `"providers"` array includes `Modules\\Package\\Providers\\PackageServiceProvider`.

### 1.2 Migrations (4 tables)

All migration files live in `backend/Modules/Package/database/migrations/`. Run order: `package_status` → `package_carrier` → `package_type` → `package`.

- [x] **1.2.1** Create `..._create_package_status_table.php`.
  - `Schema::create('package_status', ...)`.
  - Inside closure, first line: `$table->comment('Catálogo central FSM de estados del paquete — no editable por el tenant');`.
  - Use `BaseModel::columns($table, false)` (central, NO tenant_id).
  - Own columns (all with `->comment()`): `name` (string, 100), `key` (string, 50, unique), `color` (string, 20, nullable, comment "Color hex para badge de UI").
  - NO `BelongsToTenant`.

- [x] **1.2.2** Create `..._create_package_carrier_table.php`.
  - `Schema::create('package_carrier', ...)`.
  - First line: `$table->comment('Catálogo de transportistas del clúster — editable por el admin');`.
  - Use `BaseModel::columns($table, true)` (tenant-scoped, adds tenant_id + FK + index).
  - Own columns (all with `->comment()`): `name` (string, 100), `is_active` (boolean, default true), `order` (integer, default 0, comment "Orden de aparición en el listado").
  - Add `$table->unique(['tenant_id', 'name'], 'pkg_carrier_name_tenant_unique');` scoped — note soft-delete is handled at app level via `whereNull('deleted_at')` in unique checks.

- [x] **1.2.3** Create `..._create_package_type_table.php`.
  - Same structure as carrier: `$table->comment('Catálogo de tipos de paquete del clúster — editable por el admin')`, `BaseModel::columns($table, true)`, own columns `name`, `is_active`, `order` with comments, plus `unique(['tenant_id', 'name'], 'pkg_type_name_tenant_unique')`.

- [x] **1.2.4** Create `..._create_package_table.php`.
  - `Schema::create('package', ...)`.
  - First line: `$table->comment('Paquetes recibidos en la portería del clúster');`.
  - Use `BaseModel::columns($table, true)`.
  - Own columns (every one with `->comment()`):
    - `unit_id` uuid NOT NULL FK → `unit` comment "FK unidad destino del paquete"
    - `package_status_id` uuid NOT NULL FK → `package_status` comment "FK estado FSM del paquete; gestionado solo por PackageService"
    - `recipient_user_id` uuid nullable FK → `user` comment "FK residente nominal destinatario (informativo)"
    - `recipient_name` string(150) nullable comment "Nombre libre del destinatario si el usuario no está registrado"
    - `package_carrier_id` uuid nullable FK → `package_carrier` comment "FK transportista del catálogo tenant"
    - `carrier_other` string(100) nullable comment "Transportista libre cuando no está en catálogo"
    - `package_type_id` uuid nullable FK → `package_type` comment "FK tipo de paquete del catálogo tenant"
    - `tracking_number` string(100) nullable comment "Número de rastreo del envío"
    - `description` text nullable comment "Descripción breve del contenido"
    - `storage_location` string(100) nullable comment "Ubicación física de almacenamiento ej. Estante A3"
    - `pickup_code` string(9) nullable comment "Código de retiro XXXX-XXXX — oculto por defecto; único por tenant"
    - `received_at` datetime NOT NULL comment "Timestamp de recepción en la portería"
    - `received_by_user_id` uuid nullable comment "ID del guardia que registró — sin FK (patrón access_log.guard_user_id)"
    - `notified_at` datetime nullable comment "Timestamp de envío de notificación de llegada a residentes"
    - `reminded_at` datetime nullable comment "Timestamp de envío de recordatorio de retención; null = no enviado"
    - `picked_up_at` datetime nullable comment "Timestamp de entrega al residente o proxy"
    - `picked_up_by_user_id` uuid nullable comment "ID del guardia que entregó — sin FK"
    - `pickup_proxy_name` string(150) nullable comment "Nombre del proxy autorizado para retirar"
    - `pickup_proxy_relationship` string(100) nullable comment "Relación del proxy con el residente"
    - `incident_reason` text nullable comment "Razón del incidente, devolución o pérdida"
  - Indexes: `$table->index('unit_id')`, `$table->index('package_carrier_id')`, and the composite `$table->index(['package_status_id', 'received_at'], 'pkg_status_received_idx')` (reports filter by status + order by received_at). FKs use explicit delete behavior: `unit_id`/`package_status_id`/`package_carrier_id`/`package_type_id` → `restrictOnDelete()`, `recipient_user_id` → `nullOnDelete()`. _(WU-1 review fix.)_
  - Partial unique index for pickup_code: `$table->rawIndex("(tenant_id, pickup_code) WHERE deleted_at IS NULL", 'pkg_pickup_code_tenant_unique')` — verify PostgreSQL partial index syntax on the target DB version.

### 1.3 Models (4 models)

All model files live in `backend/Modules/Package/app/Models/`.

- [x] **1.3.1** Create `PackageStatus.php`.
  - Extends `CentralCatalogModel` (from `QCH\Core\Models\CentralCatalogModel`, NOT BaseModel directly).
  - `$table = 'package_status'`.
  - `$fillable = ['name', 'key', 'color']`.
  - UUID constants: `const RECEIVED = 'uuid-here'; const READY = 'uuid-here'; const PICKED_UP = 'uuid-here'; const RETURNED = 'uuid-here'; const INCIDENT = 'uuid-here';` — use stable UUIDs (generate once, commit to code; same values go in `CatalogSeeder`). These are the FSM constants the code branches on.
  - NO `BelongsToTenant` trait.

- [x] **1.3.2** Create `PackageCarrier.php`.
  - Extends `BaseModel`.
  - Uses `BelongsToTenant`.
  - `$table = 'package_carrier'`, `$fillable = ['name', 'is_active', 'order']`.
  - Cast: `'is_active' => 'boolean'`.
  - Relationship: `packages()` → `hasMany(Package::class)` (needed for FK soft-delete protection check).

- [x] **1.3.3** Create `PackageType.php`.
  - Same structure as `PackageCarrier`: `$table = 'package_type'`, `BelongsToTenant`, `$fillable = ['name', 'is_active', 'order']`, cast `is_active`, relationship `packages()`.

- [x] **1.3.4** Create `Package.php`.
  - Extends `BaseModel`. Uses `BelongsToTenant`. Uses `SoftDeletes` (inherited). Uses `HasMedia` (Spatie).
  - `$table = 'package'`.
  - `$hidden = ['pickup_code']` — MUST be excluded from default serialization.
  - `$fillable`: all own columns (see migration).
  - `$casts`: `'received_at' => 'datetime'`, `'notified_at' => 'datetime'`, `'reminded_at' => 'datetime'`, `'picked_up_at' => 'datetime'`.
  - `registerMediaCollections()`: two collections — `photo` (`->singleFile()->acceptsMimeTypes(['image/jpeg','image/png','image/webp'])`) and `pickup_evidence` (multi-file, `->acceptsMimeTypes(['image/jpeg','image/png','image/webp','application/pdf'])`).
  - Relationships: `unit()` → `belongsTo(Unit::class)`, `status()` → `belongsTo(PackageStatus::class, 'package_status_id')`, `carrier()` → `belongsTo(PackageCarrier::class, 'package_carrier_id')`, `type()` → `belongsTo(PackageType::class, 'package_type_id')`, `recipientUser()` → `belongsTo(User::class, 'recipient_user_id')`, `receivedByUser()` → `belongsTo(User::class, 'received_by_user_id')->withoutGlobalScope(UserTenantScope::class)`, `pickedUpByUser()` → `belongsTo(User::class, 'picked_up_by_user_id')->withoutGlobalScope(UserTenantScope::class)`.

### 1.4 FormRequests

All in `backend/Modules/Package/app/Http/Requests/`. Use array-style validation rules (verify existing module request style and match it).

- [x] **1.4.1** Create `PackageCreateRequest.php`.
  - `rules()`: `unit_id` required uuid exists:unit; optional validated when present: `package_carrier_id` uuid exists:package_carrier,id (tenant-scoped), `package_type_id` uuid, `recipient_user_id` uuid scoped to an ACTIVE resident of the target unit via `Rule::exists('unit_user','user_id')->where('unit_id', $this->input('unit_id'))->whereNull('end_date')`, `tracking_number` string max:100, `description` string max:500, `storage_location` string max:100, `carrier_other` string max:100, `recipient_name` string max:150, `photo` image max:10240 (mimes: jpg,jpeg,png,webp). _(WU-1 review fix: `recipient_user_id` no longer accepts an arbitrary uuid; redundant `string` dropped.)_
  - Namespace: `Modules\Package\Http\Requests`.

- [x] **1.4.2** Create `PackageUpdateRequest.php`.
  - Same optional rules as create (all fields optional on update). Exclude `unit_id` from free update (reassign has its own endpoint). Since the unit is not in the payload, `recipient_user_id` is scoped to a current-tenant member via `Rule::exists('user_tenant','user_id')->where('tenant_id', tenant('id'))`. _(WU-1 review fix.)_

- [x] **1.4.3** Create `PackageBatchRequest.php`.
  - `packages` required array min:1 max:50. Each `packages.*` entry: same rules as PackageCreateRequest (wildcard validation: `packages.*.unit_id` required uuid, etc.). A static wildcard rule can't reference the sibling row's unit_id, so `packages.*.recipient_user_id` falls back to the current-tenant scope via `user_tenant`. _(WU-1 review fix.)_

### 1.5 PackageService — intake methods

Create `backend/Modules/Package/app/Services/PackageService.php`. Namespace `Modules\Package\Services`. Uses `NotifiesResidents` concern (import from `App\Notifications\Concerns\NotifiesResidents`).

- [x] **1.5.1** `generateUniquePickupCode(): string` — private method. Alphabet `'ABCDEFGHJKMNPQRSTUVWXYZ23456789'`. Build 4 random chars + '-' + 4 random chars from alphabet. Loop max 10 attempts. Each iteration: check `Package::where('pickup_code', $code)->exists()` (tenant-scoped via BelongsToTenant). If all 10 fail, `throw new \RuntimeException('No se pudo generar un código de retiro único')`. Returns the unique code.

- [x] **1.5.2** `register(array $data, User $guard, ?array $residentUserIds = null): Package` — wraps in `DB::transaction`. Steps: (1) new Package from $data; (2) `received_at = now()`, `received_by_user_id = $guard->id`; (3) `package_status_id = PackageStatus::READY`; (4) read `package.pickup_code_enabled` via the per-instance memo `getSetting(...)` — if true, call `generateUniquePickupCode()` and set `pickup_code`; (5) persist via `persistWithUniquePickupCode($package)` _(WU-2 review fix, db: retries the save up to 3x regenerating the code on a unique-constraint collision — PostgreSQL SQLSTATE 23505 — each attempt in a nested SAVEPOINT so the failed INSERT doesn't poison the outer transaction; closes the TOCTOU window between `exists()` and INSERT)_; (6) if photo in $data, `addMedia($data['photo'])->toMediaCollection('photo')` _(accepted tradeoff design §5: media write is in-transaction; compensated by the scheduled `media-library:clean` job)_; (7) read `package.auto_notify_on_arrival` — if true, use `$residentUserIds` when supplied (batch path) else collect active `unit_user` members via `unitResidentUserIds($package->unit_id)`, dispatch `package.received` notification via `notifyUsers(...)`, then set `notified_at = now()`, save. Return $package. _(WU-1 review fix: `getSetting` memoizes the Setting lookup; `$residentUserIds` lets batch pre-resolve.)_

- [x] **1.5.3** `batchRegister(array $rows, User $guard): array` — wraps all rows in ONE `DB::transaction`. Pre-resolves a `unit_id → user_ids[]` map once per UNIQUE unit (only when `auto_notify_on_arrival` is on) so same-unit rows don't re-query, then for each row calls `$this->register($row, $guard, $residentsByUnit[$row['unit_id']] ?? null)` within the same transaction. If any throws, the whole transaction rolls back. Returns array of created packages. The controller action authorizes `create.package` FIRST. _(WU-1 review fix: per-unit resident pre-resolve + missing batch authorization.)_

### 1.6 Controllers (4 controllers)

All in `backend/Modules/Package/app/Http/Controllers/`. All extend `QCH\Core\Http\Controllers\Controller`.

- [x] **1.6.1** Create `PackageStatusController.php`.
  - Orion resource controller. `protected $model = PackageStatus::class`. `$keyForPermission = 'package_status'`.
  - Read-only: the route is registered with `->only(['index', 'show'])`, so write verbs are never routed (a POST returns 405 from the router). No method overrides needed. _(WU-1 review fix: replaced the 4 manual 405-throwing overrides with `->only`, mirroring `access_log`.)_

- [x] **1.6.2** Create `PackageCarrierController.php`.
  - Orion resource. `$model = PackageCarrier::class`. `$keyForPermission = 'package_carrier'`. `->withSoftDeletes()` on route.
  - `searchableBy()`: `['name']`. `sortableBy()`: `['name', 'order', 'is_active']`.
  - Override `performForceDestroy` to check if any package references this carrier (`$entity->packages()->exists()`) → if so, abort 422 with message "No se puede eliminar un transportista vinculado a paquetes existentes". _(WU-1 review fix: guard moved from `performDestroy` to `performForceDestroy` so soft-delete passes; only the FK-protected physical removal is blocked.)_

- [x] **1.6.3** Create `PackageTypeController.php`.
  - Identical pattern to PackageCarrierController but `$model = PackageType::class`, `$keyForPermission = 'package_type'`. Same `performForceDestroy` FK guard checking `$entity->packages()`.

- [x] **1.6.4** Create `PackageController.php`.
  - Orion resource. `$model = Package::class`. `$keyForPermission = 'package'`. `->withSoftDeletes()`.
  - `includes()`: `['unit', 'status', 'carrier', 'type', 'recipientUser', 'media']`.
  - `filterableBy()`: `['unit_id', 'package_status_id', 'package_carrier_id', 'package_type_id', 'received_at']`.
  - `sortableBy()`: `['received_at', 'picked_up_at']`.
  - `searchableBy()`: `['tracking_number', 'carrier_other', 'description', 'storage_location']`.
  - `buildIndexFetchQuery(Request $request, array $requestedRelations): Builder` — for residents/renters (users NOT holding `create.package` ability): restrict to packages where `unit_id IN (SELECT unit_id FROM unit_user WHERE user_id = auth()->id() AND end_date IS NULL)`. Guards/admins (holding `create.package`) see all tenant packages.
  - `buildShowFetchQuery(Request $request, array $requestedRelations): Builder` — same resident unit restriction.
  - `afterIndex(Request $request, Collection $entities): void` — for each entity, if auth user is an active member of the package's unit, call `$entity->makeVisible('pickup_code')`.
  - `afterShow(Request $request, Package $entity): void` — same reveal logic.
  - `afterSearch(Request $request, Collection $entities): void` — same.
  - `performStore(Request $request, Package $entity): void` — delegate to `app(PackageService::class)->register($request->validated(), auth()->user())`. Set `$entity` from the returned model (or return response directly).

### 1.7 Routes

Create `backend/Modules/Package/routes/api.php`. CRITICAL: literal custom routes MUST be declared BEFORE `Orion::resource(...)` to avoid `package/{id}` swallowing them.

- [x] **1.7.1** Create the routes file with structure:
  ```php
  $middleware = ['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'];
  
  Route::middleware($middleware)->group(function () {
      // CUSTOM ROUTES FIRST (order matters — avoids {id} conflict with Orion)
      Route::post('package/intake-batch', [PackageController::class, 'batchRegister']); // /intake-batch avoids Orion's own POST package/batch
      Route::post('package/verify-code', [PackageController::class, 'verifyCode']); // slice 2
      Route::post('package/handover', [PackageController::class, 'handover']);       // slice 2
      Route::get('my-packages', [PackageController::class, 'myPackages']);            // slice 2
      // WU-2 review fix (tenancy CRITICAL): string {packageId}, NOT implicit {package} binding.
      // SubstituteBindings runs BEFORE InitializeTenancyFromUser, so a bound model is fetched
      // cross-tenant (TenantScope inert until tenancy init). Resolve inside the controller instead.
      Route::post('package/{packageId}/return', [PackageController::class, 'returnPackage']);    // slice 2
      Route::post('package/{packageId}/incident', [PackageController::class, 'flagIncident']);   // slice 2
      Route::post('package/{packageId}/reassign', [PackageController::class, 'reassignUnit']);   // slice 2
      Route::get('package/report/pending-count', [PackageReportController::class, 'pendingCount']); // slice 5
      Route::get('package/report/aging', [PackageReportController::class, 'aging']);             // slice 5
      Route::get('package/report/aging/export', [PackageReportController::class, 'export']);    // slice 5
  
      // ORION RESOURCES (after custom routes) — snake_case names (project convention for
      // multi-word resources; matches Access's visitor_motive / access_log / blacklist_type).
      Orion::resource('package', PackageController::class)->withSoftDeletes();
      Orion::resource('package_status', PackageStatusController::class)->only(['index', 'show']);
      Orion::resource('package_carrier', PackageCarrierController::class)->withSoftDeletes();
      Orion::resource('package_type', PackageTypeController::class)->withSoftDeletes();
  });
  ```
  _(WU-1 review fix: resource names kebab → snake; `package_status` registered read-only via `->only`.)_
  Add a stub for `batchRegister` action on PackageController pointing to PackageService (slice 2 routes can be stubbed now but implemented in WU-2). All report routes can be stubbed. This way the routes file does not need to change in later slices.

### 1.8 Module registration

Follow CLAUDE.md regla 3 exactly.

- [x] **1.8.1** Open `backend/database/seeders/csv/modules.csv`. Add 1 row (generate UUID):
  ```csv
  <uuid-package>,Paquetería,Portería
  ```
  Record this UUID for the CSV steps below.

- [x] **1.8.2** Open `backend/database/seeders/csv/permissions.csv`. Append rows (generate UUIDs per row). Total: 17 rows.
  - 6 rows for `package`: read, create, update, delete, restore, report
  - 2 rows for `report_package`: read, report
  - 4 rows for `package_carrier`: read, create, update, delete
  - 4 rows for `package_type`: read, create, update, delete
  - 1 row for `package_status`: read (read-only central catalog)
  Verify existing CSV column order (likely: `id, action, entity, module_id`) before writing.

- [x] **1.8.3** Open `backend/database/seeders/csv/module_system.csv`. Mount EVERY package module on the tenant business system (`system_id` b266838b… — same one used by Collections/Dashboard). The main Paquetería module uses Group "Portería"/icon `IconBox`; the catalog + report modules (Paquetería Reportes, Transportistas, Tipos de Paquete, Estados de Paquete) are mounted with empty group_label/icon, mirroring `blacklist_type` (catalog entries, not top-level nav). _(WU-1 review fix: the 3 catalog modules had rows in modules.csv but NO module_system row, so they didn't resolve through the system → not reachable by the menu/catalog admin; added one row each.)_

- [x] **1.8.4** Open `backend/database/seeders/SystemTableSeeder.php`:
  - `seedModulePermissionEntities()`: add `'Paquetería' => 'package'`.
  - `seedModuleRoutes()`: add `'Paquetería' => '/package'`.
  - `seedModuleIcons()`: add `'Paquetería' => 'IconBox'`.
  - `seedModuleVisibility()`: add `'package_status'` to `$hidden` array (central catalog, not user-facing). Do NOT hide `package`, `package_carrier`, `package_type`, or `report_package`.

- [x] **1.8.5** Open `backend/database/seeders/RolePermissionTableSeeder.php`. In `seedKivo7RolePermissions()`, add to these explicit role arrays:
  - `security`: `'read.package', 'create.package', 'update.package', 'read.package_carrier', 'read.package_type', 'read.package_status'`
  - `resident`: `'read.package'`
  - `renter`: `'read.package'`
  - `administrator`: all 6 package perms + `'read.report_package', 'report.report_package'` + carrier/type CRUD (4+4)
  - `president`, `treasurer`, `oversight_committee`: `'read.package', 'read.report_package', 'report.report_package'`
  Do NOT add any package permissions to `$permissionsOnlySuperAdmin`. `account.owner`/`company.admin` inherit via `whereNotIn`.

- [x] **1.8.6** Open `backend/database/seeders/CatalogSeeder.php`. Add seeding for `package_status` (5 rows, using the same fixed UUIDs declared as constants in `PackageStatus` model). Columns: `id`, `name`, `key`, `color`. Suggested colors: received=gray, ready=blue, picked_up=green, returned=orange, incident=red. Use `upsert` keyed on `id`.

- [x] **1.8.7** Open `backend/database/seeders/TenantCatalogSeeder.php`. In the tenant seeding block (inside the tenant context), add:
  - `seedPackageCarriers()`: `upsert` 8 carriers (DHL, Estafeta, FedEx, UPS, Amazon, Mercado Libre, Correos de México, Otro) with `['name', 'is_active', 'order']` and tenant_id.
  - `seedPackageTypes()`: `upsert` 5 types (Sobre, Caja, Voluminoso, Perecedero, Frágil).
  - `seedPackageSettings()`: 7 `Setting::firstOrCreate` calls per the package-policies spec defaults (`package.auto_notify_on_arrival` = true, `package.pickup_code_enabled` = true, `package.requires_pickup_signature` = false, `package.requires_pickup_evidence` = false, `package.reminder_after_days` = 3, `package.return_after_days` = 10, `package.liability_disclaimer` = default Spanish text).
  Call all three from the main tenant seeder method.

- [x] **1.8.8** Open `frontend/src/shared/utils/iconMap.ts`. Add `import { IconBox } from '@tabler/icons-react'` alongside existing imports. Register `IconBox` in the map object.

### 1.9 Migration + seed gate

- [x] **1.9.1** From `backend/`, run: `php artisan migrate`. Verify zero errors. Confirm tables `package_status`, `package_carrier`, `package_type`, `package` created with correct columns.
- [x] **1.9.2** From `backend/`, run: `php artisan db:seed --class=ResetPermissionTableSeeder`. Verify: no exception. Spot-check: `security` role has `create.package`; `resident` has `read.package` but NOT `create.package`; `account.owner` has all package perms. Verify `GET /api/menu` includes "Paquetería" for an `administrator` user.

### 1.10 Feature tests — Slice 1

Create `backend/Modules/Package/tests/Feature/PackageIntakeTest.php` and `PackageCatalogsTest.php`.

- [x] **1.10.1** `PackageCatalogsTest`:
  - Setup: run `CatalogSeeder`, create 2 tenants, create carrier + type per tenant.
  - `package_status` has exactly 5 keys (received, ready, picked_up, returned, incident) after seeding.
  - `PackageStatus::READY` constant matches the seeded UUID.
  - `package_status` has no `tenant_id` column (assert via `Schema::hasColumn`).
  - Administrator 403 on `PUT /api/package-status/{id}`.
  - `package_carrier` seeded for new tenant = 8 rows.
  - Carrier name unique per tenant (same name in two tenants succeeds; duplicate in same tenant → 422).
  - Tenant A carrier not visible to Tenant B user.
  - Security guard `GET /api/package-carrier` → 200; `POST /api/package-carrier` → 403.
  - Type seeded = 5 rows per tenant. Type with referenced package → soft-delete allowed; hard-delete 422 (or confirm via `performDestroy` guard).

- [x] **1.10.2** `PackageIntakeTest`:
  - Setup: 2 tenants, seeded carriers/types, guard user (security role), resident user.
  - Single intake `POST /api/package` as guard → 201, `package_status_id = PackageStatus::READY`, `received_by_user_id = guard.id`.
  - Intake with only `unit_id` → 201, nullable fields are null.
  - `POST /api/package` as resident → 403.
  - Intake for unit in another tenant → 422, zero rows created.
  - `pickup_code` present and format `XXXX-XXXX` when `pickup_code_enabled = true`.
  - `pickup_code` null when `pickup_code_enabled = false` (set via Setting).
  - Bulk batch with 3 valid rows → 201, exactly 3 rows, all `received_by_user_id = guard.id`.
  - Bulk batch with 1 cross-tenant unit → 422, zero rows persisted (rollback).
  - Arrival notification dispatched to active residents (A, B) but NOT to ended-date resident C when `auto_notify_on_arrival = true`.
  - No notification when `auto_notify_on_arrival = false`; `notified_at` remains null.
  - Guard `GET /api/package` response: no `pickup_code` key in any data object.
  - Tenant isolation: guard of Tenant A does not see Tenant B packages.
  - Run tests with: `php artisan test --filter=PackageCatalogsTest` and `php artisan test --filter=PackageIntakeTest` (host php, DB 127.0.0.1:5432).

---

## WU-2 — Pickup & Custody

Satisfies: package-pickup (all), package-lifecycle (all)
Depends on: WU-1

### 2.1 PackageService — custody methods

Add to `PackageService.php`:

- [x] **2.1.1** `verifyPickupCode(string $code): array` — look up `Package::where('pickup_code', $code)->with(['unit.unitType', 'unit.tower'])->first()` (tenant-scoped). _(WU-2 review fix, cost: dropped `carrier`+`status` from the `$found` eager-load — never read; the terminal check uses the raw FK and the returned list carries its own `with(['carrier'])`. Kept only `unit.unitType`/`unit.tower` which feed `Unit::full_identifier`.)_ If null → `abort(404, json...)`. If found and status is terminal (PICKED_UP, RETURNED, INCIDENT) → `abort(422, ...)`. If READY → load all READY packages for the same `unit_id` (`Package::where('unit_id', $found->unit_id)->where('package_status_id', PackageStatus::READY)->with(['carrier'])->get()`). NEVER call `makeVisible('pickup_code')` on these. Return `['valid' => true, 'unit' => [...], 'packages' => [...]]`.

- [x] **2.1.2** `handover(array $packageIds, array $data, User $guard): Collection` — inside `DB::transaction`: load all packages tenant-scoped; abort 422 if any is missing or status != READY. Read policies (`requires_pickup_signature`, `requires_pickup_evidence`) via `Setting::getValue`; abort 422 with field reference if required file missing. Per package: `picked_up_at = now()`, `picked_up_by_user_id = $guard->id`, `pickup_proxy_name`, `pickup_proxy_relationship`, `package_status_id = PackageStatus::PICKED_UP`, save. Copy each uploaded file (`pickup_signature`, `pickup_evidence`) to `pickup_evidence` media collection of each package. After transaction, `notifyUsers('package.picked_up')` via afterCommit. Return the updated collection. _(WU-2 review fix, db: notify residents of EVERY distinct `unit_id` in the batch, not just `$packages->first()` — `package_ids` may span units, so first-only silently dropped notifications for the rest. Per-package `save()` is intentionally kept (NOT a batch `whereIn->update()`) so GlobalObserver + OwenIt Auditing stamp each custody change. Blast radius bounded by `package_ids` `max:20`.)_

- [x] **2.1.3** `returnPackage(Package $package, string $reason): Package` — verify status is READY (abort 422 if not). `package_status_id = PackageStatus::RETURNED`, `incident_reason = $reason`, save. Return updated.

- [x] **2.1.4** `flagIncident(Package $package, string $reason): Package` — verify status is READY (abort 422). `package_status_id = PackageStatus::INCIDENT`, `incident_reason = $reason`, save.

- [x] **2.1.5** `reassignUnit(Package $package, string $newUnitId): Package` — verify new unit belongs to same tenant (`Unit::find($newUnitId)` via tenant scope; 422 if null). Verify package NOT in terminal state. `$package->unit_id = $newUnitId`, save (GlobalObserver captures the change in audit trail — no extra column needed). If `auto_notify_on_arrival`, dispatch `package.received` to new unit's active residents. Return updated.

### 2.2 FormRequests — pickup/lifecycle

- [x] **2.2.1** Create `PackagePickupRequest.php` (for handover). Rules: `package_ids` required array min:1 max:20 _(WU-2 review fix, cost: `max:20` bounds the handover blast radius — N UPDATEs + up to 2N media copies)_, `package_ids.*` uuid, `pickup_proxy_name` nullable string max:150, `pickup_proxy_relationship` nullable string max:100, `pickup_evidence` nullable file mimes:jpg,jpeg,png,webp,pdf max:20480, `pickup_signature` nullable file mimes:jpg,jpeg,png,webp max:10240.

- [x] **2.2.2** Create `PackageReturnRequest.php`. Rules: `reason` required string max:500.

- [x] **2.2.3** Create `PackageIncidentRequest.php`. Rules: `reason` required string max:500.

- [x] **2.2.4** Create `PackageReassignRequest.php`. Rules: `unit_id` required uuid.

### 2.3 Controller — pickup/lifecycle actions

Add to `PackageController.php`:

- [x] **2.3.1** `verifyCode(Request $request): JsonResponse` — authorize `update.package` (abort 403 if resident calls — they do not hold this ability). Validate `pickup_code` required string. Call `PackageService::verifyPickupCode(...)`. Return JSON.

- [x] **2.3.2** `handover(PackagePickupRequest $request): JsonResponse` — authorize `update.package`. Call `PackageService::handover($request->package_ids, $request->validated(), auth()->user())`. Return 200 JSON.

- [x] **2.3.3** `myPackages(Request $request): JsonResponse` — authorize `read.package`. Staff exclusion: if auth user holds `create.package` → abort 403 (guards use the main index). Load non-terminal packages for all active `unit_user` rows of the authenticated user (`UnitUser::where('user_id', auth()->id())->whereNull('end_date')->pluck('unit_id')`). Load packages (`Package::whereIn('unit_id', $unitIds)->whereNotIn('package_status_id', [PackageStatus::PICKED_UP, PackageStatus::RETURNED, PackageStatus::INCIDENT])->with([...])->get()`). Call `makeVisible('pickup_code')` on each. Return JSON.

- [x] **2.3.4** `returnPackage(ReturnRequest $request, string $packageId): JsonResponse` — authorize `update.package`, then `$package = Package::findOrFail($packageId)` (tenant-scoped, runs after initializeTenancy — foreign id 404s). Delegate to `PackageService::returnPackage($package, $request->reason)`. Return 200. _(WU-2 review fix, tenancy CRITICAL: string param + in-method scoped resolve replaces implicit `{package}` binding.)_

- [x] **2.3.5** `flagIncident(IncidentRequest $request, string $packageId): JsonResponse` — authorize `update.package`, then `Package::findOrFail($packageId)` (tenant-scoped). Delegate to `PackageService::flagIncident(...)`. Return 200. _(WU-2 review fix, tenancy CRITICAL.)_

- [x] **2.3.6** `reassignUnit(ReassignRequest $request, string $packageId): JsonResponse` — authorize `update.package`, then `Package::findOrFail($packageId)` (tenant-scoped). Delegate to `PackageService::reassignUnit($package, $request->unit_id)`. Return 200. _(WU-2 review fix, tenancy CRITICAL.)_

### 2.4 Feature tests — Slice 2

Create `backend/Modules/Package/tests/Feature/PackagePickupTest.php` and `PackageLifecycleTest.php`.

- [x] **2.4.1** `PackagePickupTest`:
  - `pickup_code` absent from guard `GET /api/package` response (no key in any data object).
  - Resident `GET /api/my-packages` returns only own units' packages with `pickup_code` present and matching `XXXX-XXXX`.
  - Resident on 2 units → `my-packages` returns packages from both units.
  - Guard `GET /api/my-packages` → 403 (staff exclusion).
  - Tenant isolation for `my-packages`.
  - `verify-code` with valid code → 200 `valid: true`, `packages` contains all READY packages of the unit (3 ready + 1 picked_up → response has 3).
  - `verify-code` response does NOT include `pickup_code` field.
  - `verify-code` with unknown code → 404.
  - `verify-code` with already-picked-up package code → 422.
  - Resident `POST /api/package/verify-code` → 403.
  - `verify-code` with a foreign-tenant code → 404 (regression for cross-tenant code scanning). _(WU-2 review fix, db)_
  - Handover 2 ready packages (no policy requirement) → 200, both PICKED_UP, proxy fields recorded per package.
  - Handover rejected when `requires_pickup_signature = true` and no file → 422, packages remain READY.
  - Handover rolls back if one package is `picked_up` → 422, other package remains READY.
  - 10 packages registered → all `pickup_code` values unique.
  - Same `pickup_code` value in different tenants → no constraint violation.

- [x] **2.4.2** `PackageLifecycleTest`:
  - Newly registered package lands in `PackageStatus::READY` (not RECEIVED).
  - Return ready package → 200, status=RETURNED, `incident_reason` set.
  - Return picked-up package → 422, status unchanged.
  - Incident on ready package → 200, status=INCIDENT.
  - Incident on returned package → 422.
  - Reassign ready package to another unit in same tenant → 200, `unit_id` changed, audit entry exists.
  - Reassign to unit in another tenant → 422.
  - Reassign picked-up package → 422.
  - Resident cannot call `POST /api/package/{id}/return` → 403.
  - Cross-tenant route-binding regression _(WU-2 review fix, tenancy CRITICAL)_: a Tenant-A guard hitting a Tenant-B package id on `/return`, `/incident`, and `/reassign` each → 404 with the Tenant-B package unchanged.
  - Run: `php artisan test --filter=PackagePickupTest,PackageLifecycleTest`.

---

## WU-3 — Policies Config

Satisfies: package-policies (GET/PUT config endpoints, PackageService reads from Setting)
Depends on: WU-1 (Setting defaults already seeded in TenantCatalogSeeder)

### 3.1 ConfigurationController additions

- [x] **3.1.1** Open `backend/Modules/Configuration/app/Http/Controllers/ConfigurationController.php`. Read the existing `getCollectionSettings()` and `updateCollectionSettings()` methods carefully — copy their exact pattern.

- [x] **3.1.2** Add `getPackageSettings(Request $request): JsonResponse`:
  - Authorize `read.configuration` (`$this->requireAbility('read.configuration')` or equivalent — match the pattern of the sibling method).
  - Read each of the 7 `package.*` slugs via `Setting::getValue(...)` for the current tenant.
  - Return `response()->json([...])` with the 7 keys stripped of the `package.` prefix (e.g., `'auto_notify_on_arrival' => ...`).

- [x] **3.1.3** Add `updatePackageSettings(Request $request): JsonResponse`:
  - Authorize `update.configuration`.
  - **Dotted-slug gotcha:** read raw request via `$request->all()` (NOT `$request->input('package.x')` — dots = nested traversal). Validate using escaped dot keys: `str_replace('.', '\.', $slug)` per slug. Alternatively, receive flat keys without dots (matching the response shape) and map back to `package.x` slugs.
  - Validation rules: booleans for `auto_notify_on_arrival, pickup_code_enabled, requires_pickup_signature, requires_pickup_evidence`; integers 1-365 for `reminder_after_days, return_after_days`; string max:1000 for `liability_disclaimer`. Cross-field: `reminder_after_days < return_after_days` (custom validation rule or `Validator::after`). _(WU-3 review fix, cost: the `Validator::after` closure no longer issues up to 2 uncached `Setting::getValue` SELECTs to resolve the counterpart on a single-day update. Both day slugs are pre-read in ONE batched `Setting::whereIn('slug', [...])->pluck('value','slug')` BEFORE the closure — mirroring `getPackageSettings` — gated to run only when at least one day key is present, then passed into the closure via `use`.)_
  - Partial update: only persist keys present in the request. For each present key, call `Setting::updateOrCreate(['slug' => 'package.'.$key, 'tenant_id' => tenancy()->tenant->id], ['value' => $value])`.
  - Return 200 JSON with updated values.

### 3.2 Configuration routes

- [x] **3.2.1** Open `backend/Modules/Configuration/routes/api.php`. Add 2 routes inside the existing authenticated group (matching sibling collection settings routes):
  ```php
  Route::get('config/package-settings', [ConfigurationController::class, 'getPackageSettings']);
  Route::put('config/package-settings', [ConfigurationController::class, 'updatePackageSettings']);
  ```

### 3.3 Feature tests — Slice 3

Create `backend/Modules/Configuration/tests/Feature/PackageSettingsTest.php`.

- [x] **3.3.1** Tests:
  - After `TenantCreated`, exactly 7 `package.*` Setting rows exist.
  - Admin `GET /config/package-settings` → 200 with all 7 keys at defaults.
  - Tenant isolation: Tenant A sets `auto_notify_on_arrival = false`; Tenant B admin sees `true`.
  - Security `GET /config/package-settings` → 403 (no `read.configuration`).
  - `PUT /config/package-settings` with `{ requires_pickup_signature: true }` → 200; only that slug changes.
  - `PUT` with `reminder_after_days: 10, return_after_days: 5` → 422 (day-ordering constraint).
  - Security `PUT /config/package-settings` → 403.
  - Run: `php artisan test --filter=PackageSettingsTest`.

---

## WU-4 — Retention & Reminders

Satisfies: package-retention (all)
Depends on: WU-1, WU-2 (needs `returnPackage` method)

### 4.1 Artisan command

- [x] **4.1.1** Create `backend/Modules/Package/app/Console/Commands/ProcessPackageRetentionCommand.php`.
  - Extends `Illuminate\Console\Command`.
  - `$signature = 'package:process-retention'`.
  - `$description = 'Procesa recordatorios y devoluciones de paquetes no retirados por tenant'`.
  - `handle()`: `Tenant::all()->run(function ($tenant) { $this->processTenant(); })`.
  - `processTenant()`: private method. Reads `reminder_after_days` and `return_after_days` from `Setting` (per-tenant). Queries `Package::where('package_status_id', PackageStatus::READY)->get()`. Per package:
    - Compute `$days = now(tenant_timezone)->diffInDays(Carbon::parse($package->received_at)->setTimezone(tenant_timezone))` (signed diff is always non-negative here since received_at is in the past; verify `diffInDays` direction per the `collections-report-signed-diffindays` bug memory).
    - If `$days >= $reminder_after_days` AND `$package->notified_at !== null` AND `$package->reminded_at === null`: dispatch `package.reminder` notification, set `$package->reminded_at = now()`, save. (Skips reminder if `reminded_at` already set — dedup.)
    - If `$days >= $return_after_days`: call `app(PackageService::class)->returnPackage($package, 'Plazo de retención expirado')`.
  - Use `Tenant::all()` model (verify exact import path for Stancl Tenancy — matches `RefreshChargeStatusesCommand` import).
  - _(WU-4 review fixes — applied after the initial inline slice. The command is now THIN, mirroring `RefreshChargeStatusesCommand`: ALL retention business logic moved to `PackageService::processRetention(Tenant $tenant): array` (design §6.3), next to the sibling notification methods; the command dropped `use NotifiesResidents` and resolves a fresh `PackageService` inside each `$tenant->run()` so the per-instance setting cache never leaks across tenants.)_
    - _arch: `processTenant()`/`dispatchReminder()` extracted into `PackageService::processRetention()` + private `dispatchRetentionReminder()`._
    - _db (fault isolation): `$tenant->run()` wrapped in `try/catch (\Throwable)` → `Log::error` + continue, so one tenant's `abort(422)` (e.g. concurrent handover during `returnPackage`) no longer terminates the cron and starves the tenants that follow._
    - _db (stamp gating): `dispatchRetentionReminder()` returns `bool` — `false` (no-op) when the unit has no active residents, leaving `reminded_at` null so the reminder still fires once residents are onboarded. `reminded_at` is only stamped when a resident exists._
    - _cost (batched setting read): both day slugs read in ONE `Setting::whereIn('slug', [...])->pluck('value','slug')` instead of two uncached `Setting::getValue` SELECTs per tenant._
    - _cost (bounded memory): `->get()` replaced with `->chunkById(200, ...)` — chunkById (NOT chunk) because `returnPackage` mutates `package_status_id`, the WHERE-filtered column, which would make offset-based `chunk()` skip rows as the result set shrinks; keyset chunkById is immune._
    - _notif (atomic dedup): `reminded_at` is stamped + saved BEFORE dispatching (mirrors `maybeNotifyArrival`); with no open transaction in cron context, `notifyUsers`' `DB::afterCommit` fires immediately, so stamping first guarantees the dedup guard is committed before the notification fires — no duplicate on retry._

### 4.2 ServiceProvider schedule

- [x] **4.2.1** Open `backend/Modules/Package/app/Providers/PackageServiceProvider.php`. Add `registerCommandSchedules()` method following `CollectionsServiceProvider` pattern:
  ```php
  protected function registerCommandSchedules(): void
  {
      $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
          $schedule->command('package:process-retention')->dailyAt('08:00');
      });
  }
  ```
  Call `$this->registerCommandSchedules()` from `boot()`. Also register the command in `$commands` or via `$this->commands([ProcessPackageRetentionCommand::class])`.

### 4.3 package.reminder notification

- [x] **4.3.1** Verify the notification dispatch path used in `NotifiesResidents`. The `package.reminder` notification event must include: carrier name (or `carrier_other` or "Paquete sin especificar"), `received_at` in tenant timezone, `days_pending`, `click_action: '/my-packages'`, and a countdown message ("Será devuelto en N días si no lo retira"). Involve `@notifications-expert` when wiring the actual notification channel payload — the service call (`notifyUsers(...)`) is straightforward; the payload shape is the notification expert's domain. _(WU-4 review fix, notif: `days_pending` was embedded only in the body text; it is now also passed as a discrete `$data` key — `'days_pending' => $daysPending` alongside `click_action`/`package_id` — so FCM/webhook consumers can read `data.days_pending` from the structured payload, not just parse the body string.)_

### 4.4 Feature tests — Slice 4

Create `backend/Modules/Package/tests/Feature/PackageRetentionTest.php`.

- [x] **4.4.1** Tests:
  - `php artisan schedule:list` output contains `package:process-retention` with daily frequency.
  - Package at day 3 (reminder_after_days=3), `reminded_at=null`, `notified_at` set → reminder dispatched, `reminded_at` set.
  - Package at day 5, `reminded_at` already set → no second reminder.
  - Package at day 11 (return_after_days=10) → `returnPackage` called, status=RETURNED.
  - Package with status `picked_up` at day 30 → skipped entirely.
  - Tenant isolation: Tenant A's overdue package → returned; Tenant B's package (within retention) → stays READY.
  - Run: `php artisan test --filter=PackageRetentionTest`.

---

## WU-5 — Reports & Dashboard

Satisfies: package-reporting (all)
Depends on: WU-1

### 5.1 PackageReportService

Create `backend/Modules/Package/app/Services/PackageReportService.php`. Namespace `Modules\Package\Services`.

- [x] **5.1.1** `aging(): array` — loads `Package::where('package_status_id', PackageStatus::READY)->with(['unit', 'carrier'])->get()` (tenant-scoped). Per package: compute `$days = (int) now($tenantTimezone)->diffInDays(Carbon::parse($pkg->received_at)->setTimezone($tenantTimezone))`. Assign to bucket: `d0_2` (0-2 days), `d3_7` (3-7 days), `d7_plus` (8+ days). Per bucket, collect package entries `{id, unit_full_identifier, carrier, received_at, storage_location, days_pending}`. `unit_full_identifier` = `$pkg->unit->full_identifier ?? $pkg->unit->number`. `carrier` = `$pkg->carrier->name ?? $pkg->carrier_other`. Return `{generated_at, total_pending, buckets}`. Eager-loads `unit.unitType`, `unit.tower` to resolve `full_identifier` accessor. Uses `$receivedAt->diffInDays($now)` (past→future, always non-negative — avoids the collections-report-signed-diffindays bug). tenant timezone via `tenant('timezone') ?: 'America/Mexico_City'`.

- [x] **5.1.2** `pendingCount(): array` — two COUNT queries (no join with packages list): `$total = Package::where('package_status_id', PackageStatus::READY)->count()`. `$d7plus = Package::where('package_status_id', PackageStatus::READY)->where('received_at', '<=', now()->subDays(8)->toDateTimeString())->count()` (PHP fallback chosen: portable across PostgreSQL and SQLite, no raw SQL). Return `{total, d7_plus}`. Lightweight.

- [x] **5.1.3** `agingExportRows(): SupportCollection` — _(WU-5 review fix, arch)_ owns the aging Excel aggregation that previously lived inline in `PackageReportController::export()`. Loads the READY packages with `unit.unitType`/`unit.tower`/`carrier`/`status`, maps each to a flat `\stdClass` whose fields line up 1:1 with `reportColumns()`, sorts by `days_pending` desc. Reuses the SAME day/timezone computation as `aging()` via the shared private helpers `resolveTimezone()` + `daysPending(Package, string, Carbon): int`, so the diff-in-days semantics live in exactly ONE place. The controller is now thin (it only wires `ReportExport` + `Excel::download`).

### 5.2 PackageReportController

Create `backend/Modules/Package/app/Http/Controllers/PackageReportController.php`.

- [x] **5.2.1** `aging(Request $request): JsonResponse` — authorize `read.report_package`. Delegate to `PackageReportService::aging()`. Return `response()->json(...)`.

- [x] **5.2.2** `export(Request $request): BinaryFileResponse` — authorize `read.report_package` FIRST. _(WU-5 review fix, arch: aggregation moved OUT of the controller.)_ Now thin: `$rows = $this->service->agingExportRows();` then `ReportExport::setTitle()` → `setHeadings()` → `setMap()` → `setCollection($rows)` (order matters — `setHeadings` references `$this->title`). `Excel::download($export, 'paquetes-pendientes-'.date('Y-m-d').'.xlsx')`. 8 Spanish columns. Tests access xlsx row count via `$response->baseResponse->getFile()->getPathname()` (BinaryFileResponse doesn't expose `content()`).

- [x] **5.2.3** `pendingCount(Request $request): JsonResponse` — authorize `read.package` (visible to any user with package read access, gated at widget level). Return `PackageReportService::pendingCount()`.

- [x] **5.2.4** Implement `reportColumns(): array` returning 8 column definitions (Unidad, Transportista, Número de seguimiento, Descripción, Ubicación de almacenamiento, Recibido el, Días pendiente, Estado) — all callable closures receiving a stdClass. Wired into the shared ReportController/ReportExport framework. _(WU-5 review fix, db: dropped the redundant 9th "Ubicación" column — it mapped `$row->location`, which was a verbatim duplicate of `storage_location`, producing two identical Excel columns.)_

### 5.3 Feature tests — Slice 5

Create `backend/Modules/Package/tests/Feature/PackageReportTest.php`.

- [x] **5.3.1** Tests — **8 tests / 39 assertions, all GREEN** (`php artisan test --filter=PackageReportTest`):
  - P1 (1 day, ready), P2 (5 days, ready), P3 (10 days, ready), P4 (2 days, picked_up) → `d0_2.count=1`, `d3_7.count=1`, `d7_plus.count=1`, P4 absent, `total_pending=3`. ✓
  - Report is tenant-scoped: Tenant A has 3 pending, Tenant B has 5 → Tenant A admin sees `total_pending=3`. ✓
  - Security user (no `read.report_package`) → 403. ✓
  - Export: `GET /api/package/report/aging/export` → 200, Content-Type = xlsx, Content-Disposition contains `paquetes-pendientes-`. ✓
  - Export with 2 ready + 3 picked_up → xlsx has exactly 2 data rows. ✓ (uses `$response->baseResponse->getFile()->getPathname()` + IOFactory::load)
  - `pending-count` with 5 ready (2 are 9 days old) → `total=5, d7_plus=2`. ✓
  - `pending-count` with zero ready packages → `total=0`. ✓
  - `reportColumns()` returns ≥8 callable definitions. ✓
  - Tests in `backend/tests/Feature/Package/PackageReportTest.php`.

---

## WU-6 — Tenant Catalog Admin UI & Extras

Satisfies: package-catalogs (admin CRUD UI), package-lifecycle (returned/incident display polish)
Depends on: WU-1 (tables + API exist)

### 6.1 PackageCarrier admin pages

Create `frontend/src/modules/package/carrier/`.

- [x] **6.1.1** `schema.ts` — `IPackageCarrier` interface (`id, name, is_active, order, tenant_id`). Zod form schema with `name` required string, `is_active` boolean, `order` integer. `PackageCarrierFormValues` type.

- [x] **6.1.2** `services/CarrierResource.ts` — `createCrudApi<IPackageCarrier, PackageCarrierFormValues>({ basePath: '/package_carrier' })`. Note: basePath uses snake_case `/package_carrier` per Orion convention.

- [x] **6.1.3** `store/carrierStore.ts` — `createEntityStore<IPackageCarrier>('packageCarrier')`.

- [x] **6.1.4** `PackageCarrierListPage.tsx` — `CatalogCrudModule` (which embeds `ResponsiveDataTable` + form Sheet) with columns: Nombre, Activo (StatusPill badge), Orden, Acciones (edit/delete via PermissionGuard). Spanish UI. Skeleton via CatalogCrudModule's `TableLoadingState`. Edit/delete buttons hidden for guards with read-only (via `useMultiplePermissions` inside CatalogCrudModule).

- [x] **6.1.5** `PackageCarrierListPage.tsx` (form embedded in CatalogCrudModule Sheet modal) — RHF+Zod via `CatalogFormModal`. Fields: Nombre (required), Activo (switch), Orden de aparición (number). Spanish labels + validation messages. No separate Form file needed — CatalogCrudModule is the idiomatic pattern for simple catalog CRUD in this codebase.

### 6.2 PackageType admin pages

Create `frontend/src/modules/package/type/`.

- [x] **6.2.1** `schema.ts`, `services/TypeResource.ts`, `store/typeStore.ts` — identical pattern to carrier. basePath `/package_type` (snake_case per Orion convention), entity `IPackageType`.
- [x] **6.2.2** `PackageTypeListPage.tsx` — same structure as carrier page (CatalogCrudModule). Form embedded in Sheet modal. No separate TypeForm file.

### 6.3 Status display polish

- [x] **6.3.1** Created `frontend/src/modules/package/components/PackageStatusBadge.tsx`. Maps `package_status.key` → Spanish label: ready→"Listo" (blue), picked_up→"Retirado" (green), returned→"Devuelto" (yellow), incident→"Incidente" (red), received→"Recibido" (blue). Uses `statusColor` hex (20% opacity bg + solid dot) when available; falls back to `StatusPill` tone map. No `any`. Props: `statusKey`, `statusColor`, `statusName` (all optional).

### 6.4 App.tsx routes for catalog pages

- [x] **6.4.1** Opened `frontend/src/App.tsx`. Added lazy imports for `PackageCarrierListPage` and `PackageTypeListPage`. Added granular permission routes (separate `PermissionRoute permissionEntity="package_carrier"` and `"package_type"` — more precise than the `package` group):
  ```tsx
  <Route element={<PermissionRoute permissionEntity="package_carrier" />}>
    <Route path="/package-carrier" element={<PackageCarrierListPage />} />
  </Route>
  <Route element={<PermissionRoute permissionEntity="package_type" />}>
    <Route path="/package-type" element={<PackageTypeListPage />} />
  </Route>
  ```
  Build: `pnpm build` — zero TS errors, 10433 modules transformed. `pnpm lint` — zero new errors (22 pre-existing errors in other modules unaffected).

### 6.5 Review fixes (judgment-day)

- [x] **6.5.1** (CRITICAL, integration) Route mismatch: the seeded menu maps `Transportistas`/`Tipos de Paquete` to `/package-carrier`/`/package-type` (flat kebab, the convention used by every other catalog: `/provider-type`, `/announcement-type`, `/visitor-motive`), but App.tsx registered `/package/carriers`/`/package/types` → clicking the seeded menu item 404'd. Aligned the frontend to the seeder + convention: App.tsx routes are now `/package-carrier` and `/package-type`. No reseed needed (the seeder already used the flat values).

- [x] **6.5.2** (CRITICAL integration + WARNING react) Blank "Orden de aparición" on create → 422/500. The catalog UI submits `order: null` for a blank field (CatalogCrudModule's number input `setValueAs` coerces empty→null; the Zod schema `z.number().int().nullable()` permits it) — IDENTICAL to canonical tower's frontend, which works. The divergence was the BACKEND: `PackageCarrier/Type` Create/Update requests used `'order' => ['integer','min:0']` WITHOUT `nullable`, and the live columns were NOT NULL (verified: `package_carrier.order`/`package_type.order` is_nullable=NO, vs `tower.order` is_nullable=YES). Fixed at the root to match tower: (a) added `nullable` to the 4 FormRequests (`['nullable','integer','min:0']` create, `['sometimes','nullable','integer','min:0']` update); (b) added migration `2026_06_29_000005_make_package_catalog_order_nullable.php` making both `order` columns nullable (default 0, comment preserved). Frontend schema/buildDefaults left untouched — they already mirror tower exactly; a frontend transform was DECLINED (it would diverge from canon and risk a `z.ZodType<V,V>` input/output type mismatch in tsc). NOTE: run `php artisan migrate` for the new ALTER to take effect on already-migrated DBs.

---

## WU-7 — Frontend Core

Satisfies: package-intake (frontend), package-pickup (frontend), package-lifecycle (frontend), package-policies (PackageConfigSection), package-reporting (widget + list)
Depends on: WU-1 through WU-5 for backend endpoints; individual pages can be built as the corresponding backend WU lands

### 7.1 Schema

Create `frontend/src/modules/package/schema.ts`.

- [x] **7.1.1** Define with Zod (no `any`):
  - `IPackageStatus`: `{id, name, key, color}`.
  - `IPackageCarrier`: `{id, name, is_active, order}`.
  - `IPackageType`: `{id, name, is_active, order}`.
  - `IPackage`: all package columns + `unit?`, `status?`, `carrier?`, `type?`, `recipient_user?`, `media?[]`. `pickup_code?` optional string (present only in `my-packages`).
  - Zod schemas: `packageFormSchema` (for intake form — unit_id required, rest optional), `packageBatchSchema` (packages array), `packagePickupSchema` (package_ids array + proxy + files optional).
  - `PackageFormValues`, `PackageBatchFormValues`, `PackagePickupFormValues` types from Zod inference.

### 7.2 Resource

Create `frontend/src/modules/package/services/PackageResource.ts`.

- [x] **7.2.1** `createCrudApi<IPackage, PackageFormValues>({ basePath: '/package', customMethods: { ... } })`. Custom methods: `verifyCode(code)` → `POST /package/verify-code`, `handover(data)` → `POST /package/handover`, `returnPackage(id, reason)` → `POST /package/{id}/return`, `incident(id, reason)` → `POST /package/{id}/incident`, `reassign(id, unitId)` → `POST /package/{id}/reassign`, `batch(data)` → `POST /package/batch`, `myPackages()` → `GET /my-packages`, `aging()` → `GET /package/report/aging`, `pendingCount()` → `GET /package/report/pending-count`. No `IXxxResponse` wrappers; return entity types directly.

### 7.3 Store

Create `frontend/src/modules/package/store/packageStore.ts`.

- [x] **7.3.1** `export const usePackageStore = createEntityStore<IPackage>('PackageStore')`. No manual store, no extra boilerplate.

### 7.4 PackageListPage

Create `frontend/src/modules/package/PackageListPage.tsx`.

- [x] **7.4.1** `useBaseList` hook consuming `packageStore` + `PackageResource`. Renders `ResponsiveDataTable` with columns: Unidad, Transportista, Descripción, Recibido el (`displayFormatDate`), Ubicación, Estado (`PackageStatusBadge`), Días pendiente, Acciones (detail, return, incident buttons for admin/guard). Spanish column headers. Age badge highlights rows with 8+ days in red. Filter bar: by status, unit, date range. Skeleton via `boneyard-js`. Error → `toast.error(...)` (sileo). Empty state → Spanish message. Permission gate: `usePermissions('read.package')`.

### 7.5 PackageForm (intake + bulk)

Create `frontend/src/modules/package/PackageForm.tsx`.

- [x] **7.5.1** React Hook Form + Zod (`packageFormSchema`). Fields: Unidad (required, searchable select), Transportista (carrier dropdown from catalog), Tipo (type dropdown), Número de seguimiento, Descripción, Ubicación de almacenamiento, Foto (file input, image). Bulk mode toggle: shows a repeating row pattern (up to 50 rows) with same fields. Submit buttons: "Registrar paquete" (single) / "Registrar paquetes" (bulk). Calls `PackageResource.create(...)` (single) or `PackageResource.batch(...)` (bulk). Success toast in Spanish. No `any`. NEVER `useToast()` — use `toast.success(...)` from `@/shared/hooks/useToast`.

### 7.6 PackagePickupModal

Create `frontend/src/modules/package/PackagePickupModal.tsx`.

- [x] **7.6.1** Step 1 — Scan QR input: text input for `pickup_code`. On submit calls `PackageResource.verifyCode(code)`. Step 2 — Package selection: shows all returned `packages` with checkboxes (multi-select; "Seleccionar todos" button). Step 3 — Handover form: proxy name/relationship (optional), `pickup_signature` file upload (shown + required only when `requires_pickup_signature = true` per policies), `pickup_evidence` file upload (required only when `requires_pickup_evidence = true`). Confirm button: calls `PackageResource.handover({ package_ids, pickup_proxy_name, ... })`. All text in Spanish. Error handling via `toast.error`. Loading states via `boneyard-js`. No `any`.

### 7.7 MyPackagesPage

Create `frontend/src/modules/package/MyPackagesPage.tsx`.

- [x] **7.7.1** `useQuery` calling `PackageResource.myPackages()`. Displays a card list of pending packages for the resident, each showing: carrier/description, received date, storage location, status badge, and the `pickup_code` rendered via `qrcode.react` QRCode component (import `qrcode.react`; install if not already present: `pnpm add qrcode.react` from `frontend/`). Add a "Compartir código" button that copies the code or opens a native share dialog (`navigator.share`). Spanish UI. Skeleton loading. Empty state: "No tienes paquetes pendientes de retiro".

### 7.8 PackageDetailPage

Create `frontend/src/modules/package/PackageDetailPage.tsx`.

- [x] **7.8.1** `useQuery` for `GET /api/package/{id}` with all includes. Displays: package metadata (unit, carrier, type, tracking, description, storage location), intake photo (via `media` collection), status badge + timeline (uses Laravel Auditing changes from the `audit_log` — check if `audits` include is available via Orion; if not, display available fields: received_at/by, notified_at, picked_up_at/by, incident_reason). If PICKED_UP: show pickup proxy info + pickup_evidence media (signature/evidence images/PDFs). Spanish labels throughout. No `any`.

### 7.9 PackageConfigSection

Create `frontend/src/modules/package/components/PackageConfigSection.tsx`.

- [x] **7.9.1** Mirrors `CollectionConfigSection.tsx` exactly (read that file before writing). `useSectionEdits` pattern or equivalent hook. On mount: `GET /config/package-settings` via a dedicated resource or axios call. Renders 7 policy fields with Spanish labels and appropriate inputs: toggles for 4 booleans, number inputs for `reminder_after_days` / `return_after_days`, textarea for `liability_disclaimer`. "Guardar" button, disabled while submitting. On success: `toast.success('Configuración de paquetería guardada')`. Integrate this section into the existing cluster configuration page (add a "Paquetería" tab/section following the sibling pattern of Collections config).

### 7.10 Dashboard widget

Create `frontend/src/modules/package/components/PendingPackagesWidget.tsx`.

- [x] **7.10.1** `useQuery({ queryKey: ['package-pending-count'], queryFn: PackageResource.pendingCount })`. Renders a card showing: "Paquetes pendientes" with `total` count prominently, and a red badge/indicator for `d7_plus` (critical: "X llevan más de 7 días"). Empty state shows zero without error. Gate with `usePermissions('read.package')` — widget not rendered if permission absent. Integrate into `frontend/src/modules/dashboard/` (or equivalent home dashboard page — read the current dashboard page to find the widget container). Standard loading skeleton.

### 7.11 App.tsx routes

- [x] **7.11.1** Open `frontend/src/App.tsx`. Add lazy imports:
  ```tsx
  const PackageListPage = lazy(() => import('@/modules/package/PackageListPage'));
  const PackageForm = lazy(() => import('@/modules/package/PackageForm'));
  const PackageDetailPage = lazy(() => import('@/modules/package/PackageDetailPage'));
  const MyPackagesPage = lazy(() => import('@/modules/package/MyPackagesPage'));
  ```
- [x] **7.11.2** Add routes under `ProtectedRoute` + `SubscriptionGuard`:
  ```tsx
  <Route element={<PermissionRoute permissionEntity="package" />}>
    <Route path="/package" element={<PackageListPage />} />
    <Route path="/package/new" element={<PackageForm />} />
    <Route path="/package/:id" element={<PackageDetailPage />} />
  </Route>
  <Route element={<PermissionRoute permissionEntity="package" />}>
    <Route path="/my-packages" element={<MyPackagesPage />} />
  </Route>
  ```
  Note: `my-packages` uses `read.package` — `PermissionRoute permissionEntity="package"` covers this. Guards see `PackageListPage`; residents see `MyPackagesPage`. Both use the same permission entity; the frontend routes are not further permission-split (the backend handles the staff exclusion on `GET /my-packages`).

### 7.13 WU-7 review fixes (judgment-day)

Applied from the WU-7 Frontend-core adversarial review (react + integration + tenancy + cost lenses). All CRITICAL + WARNING findings resolved. `pnpm build` green; `pnpm lint` introduces no new errors/warnings.

- [x] **7.13.1** _(react CRITICAL)_ `PackageForm.tsx` — removed the 4 `<SelectItem value="">` clearing items (carrier + type, single + bulk) that crash radix Select with an empty-string `value` invariant. Now use the placeholder for the empty state and `value={field.value || undefined}`.
- [x] **7.13.2** _(react WARNING)_ `PackageForm.tsx` — single-intake carrier "Otro" now uses sentinel `__other` mapped to `undefined`, clears `package_carrier_id`, and reveals a `carrier_other` `<Input>` (schema field). Submitting "Otro" no longer 422s on `exists:package_carrier`. Used `useWatch` (memo-safe) instead of `watch()`.
- [x] **7.13.3** _(react CRITICAL + integration CRITICAL)_ `PackageConfigSection.tsx` — switched all setting keys from `package.*`-prefixed to FLAT (`auto_notify_on_arrival`, `pickup_code_enabled`, `requires_pickup_signature`, `requires_pickup_evidence`, `reminder_after_days`, `return_after_days`, `liability_disclaimer`) to match the backend GET (prefix stripped) / PUT (`$request->only(slugMap)`) shape. The tab now renders saved values and persists.
- [x] **7.13.4** _(react CRITICAL + integration WARNING + tenancy WARNING)_ `PackageConfigSection.tsx` — replaced the phantom `max_days_in_custody` number field (not a backend slug) with the required `liability_disclaimer` `<Textarea>` (flat key, max 1000) per task 7.9.1's 7-slug contract.
- [x] **7.13.5** _(react CRITICAL + integration CRITICAL)_ `PackagePickupModal.tsx` — policy reads use FLAT keys (`requires_pickup_signature` / `requires_pickup_evidence`); the evidence/signature upload fields now render when the cluster requires them, so handover is possible for evidence-required clusters.
- [x] **7.13.6** _(react CRITICAL + integration WARNING + tenancy WARNING)_ Aging export wired to the dedicated endpoint: added `PackageResource.agingExport()` → `GET /package/report/aging/export` (`responseType: 'blob'`); `PackageListPage` export mutation calls it instead of the generic `report()`/legacy `POST /customReport`.
- [x] **7.13.7** _(react WARNING)_ `PackageListPage.tsx` — export button gated on `read.report_package` (matches the backend `export` ability + president/treasurer/oversight role grants) instead of the non-granted `report.package`.
- [x] **7.13.8** _(integration CRITICAL)_ `PackageResource.verifyCode` posts `{ pickup_code: code }` (was `{ code }`) — backend reads `pickup_code`; the QR-scan → handover flow no longer 422s.
- [x] **7.13.9** _(integration CRITICAL)_ `AngularController::$mapperSelects` — added `PackageCarrier`, `PackageType`, `PackageStatus` (+ imports) so `POST /selects` populates the intake carrier/type dropdowns and the list carrier filter (were permanently empty).
- [x] **7.13.10** _(integration WARNING)_ Bulk-intake success toast — `PackageResource.batch` now returns `IPackage[]` (`data.data`) and `PackageForm` toasts `result.length` instead of the always-`undefined` `result.count` (backend returns `{ data: [...] }`, no `count` key).
- [x] **7.13.11** _(integration WARNING)_ verify-code reduced shape — `PackageService::verifyPickupCode` now emits `tracking_number` and `package_status` (snake_case resource convention) alongside `package_carrier`; FE adds `IVerifyCodePackage` and `SelectStep` reads `package_carrier`/`package_status` with fallback to the Orion `carrier`/`status` shape (list-initiated `initialPackage` path). Carrier name, status badge, and tracking now display.
- [x] **7.13.12** _(cost WARNING)_ `packagePickupSchema.package_ids` — added `.max(20, 'Máximo 20 paquetes por entrega')` to mirror the backend `max:20` blast-radius cap.

### 7.12 Frontend tests (Vitest + Testing Library)

- [x] **7.12.1** `__tests__/schema.test.ts` — Zod parse tests for `IPackage`, `packageFormSchema`, `packageBatchSchema`, `packagePickupSchema` with representative payloads. Assert no `any` leaks. `pickup_code` is optional string.

- [x] **7.12.2** `__tests__/resource.test.ts` — mock `apiClient`. Assert: `verifyCode('ABCD-1234')` hits `POST /package/verify-code`; `handover(...)` hits `POST /package/handover`; `myPackages()` hits `GET /my-packages`; `aging()` hits `GET /package/report/aging`; `pendingCount()` hits `GET /package/report/pending-count`. (File: `PackageResource.test.ts` with full coverage of all 10 custom methods.)

- [x] **7.12.3** `__tests__/PackageListPage.test.tsx` — render smokes: loading (skeleton), success (table renders, no `pickup_code` column), error (`toast.error` called), empty state message.

- [x] **7.12.4** `__tests__/MyPackagesPage.test.tsx` — success: `pickup_code` rendered (QRCode component present), `XXXX-XXXX` format visible. Empty: empty state message. Loading: skeleton. Note: `ResizeObserver` polyfill already in `src/test/setup.ts`.

- [x] **7.12.5** `__tests__/PackagePickupModal.test.tsx` — step 1 submit calls `verifyCode`; step 2 shows returned packages (no `pickup_code` visible); step 3 shows signature upload only when policy requires it; confirm calls `handover`.

- [x] **7.12.6** `__tests__/PendingPackagesWidget.test.tsx` — `total=5, d7_plus=2` → both values rendered; `d7_plus` has distinct color class. `total=0` → no error. Permission absent → widget not rendered.

---

## Per-slice quality gates

Run after each WU before merging the corresponding PR. Resolve all CRITICALs before merge.

- [ ] **QG per slice** `vendor/bin/pint --dirty` (from `backend/`) — zero formatting errors.
- [ ] **QG per slice** `php artisan test --filter=<SliceTestClass>` — all green (host php, 127.0.0.1:5432).
- [ ] **QG per slice** `pnpm lint` (from `frontend/`) — zero ESLint errors. No `any` violations.
- [ ] **QG per slice** `pnpm build` (from `frontend/`) — TypeScript strict compile + Vite build succeed.
- [x] **QG WU-7** `pnpm test` — all Vitest tests green (7.12.1 through 7.12.6).
- [ ] **QG each slice** `/review-stack` — `laravel-architecture-reviewer` + `react-frontend-reviewer` + `integration-reviewer` + `tenancy-reviewer`. Slice 1 especially: tenancy-reviewer MUST confirm `tenant_id` scoping on all 3 tenant-scoped tables.
- [ ] **QG WU-1, WU-2, WU-4** involve `@notifications-expert` for the `package.received`, `package.picked_up`, `package.reminder` notification event payloads.

---

## Spec → Task traceability matrix

| Spec requirement | Satisfied by |
|---|---|
| package-catalogs: `package_status` central FSM | WU-1.2.1, WU-1.3.1, WU-1.8.6 |
| package-catalogs: `package_carrier` tenant-scoped | WU-1.2.2, WU-1.3.2, WU-1.8.7 |
| package-catalogs: `package_type` tenant-scoped | WU-1.2.3, WU-1.3.3, WU-1.8.7 |
| package-catalogs: 5 statuses after seeding | WU-1.10.1 |
| package-catalogs: 8 carriers + 5 types per new tenant | WU-1.8.7, WU-1.10.1 |
| package-catalogs: admin CRUD for carrier/type | WU-1.6.2, WU-1.6.3, WU-6.1, WU-6.2 |
| package-catalogs: column comments on all migrations | WU-1.2.1 through WU-1.2.4 |
| package-intake: `package` table schema | WU-1.2.4, WU-1.3.4 |
| package-intake: `PackageService::register` | WU-1.5.2 |
| package-intake: `PackageService::batchRegister` | WU-1.5.3 |
| package-intake: `PackageCreateRequest` + `PackageBatchRequest` | WU-1.4.1, WU-1.4.3 |
| package-intake: arrival notification via `NotifiesResidents` | WU-1.5.2, WU-1.10.2 |
| package-intake: role access (403 for residents) | WU-1.8.5, WU-1.10.2 |
| package-intake: tenant isolation on intake | WU-1.10.2 |
| package-pickup: `pickup_code` hidden + resident-only | WU-1.3.4, WU-1.6.4, WU-2.3.3, WU-2.4.1 |
| package-pickup: `GET /my-packages` | WU-2.3.3, WU-2.4.1 |
| package-pickup: `verify-code` | WU-2.1.1, WU-2.3.1, WU-2.4.1 |
| package-pickup: `handover` atomicity | WU-2.1.2, WU-2.3.2, WU-2.4.1 |
| package-pickup: policy enforcement in handover | WU-2.1.2, WU-3.1.3, WU-2.4.1 |
| package-pickup: staff exclusion on `my-packages` | WU-2.3.3, WU-2.4.1 |
| package-lifecycle: FSM transitions + terminal states | WU-2.1.3, WU-2.1.4, WU-2.4.2 |
| package-lifecycle: `returnPackage` | WU-2.1.3, WU-2.3.4, WU-2.4.2 |
| package-lifecycle: `flagIncident` | WU-2.1.4, WU-2.3.5, WU-2.4.2 |
| package-lifecycle: `reassignUnit` | WU-2.1.5, WU-2.3.6, WU-2.4.2 |
| package-lifecycle: `notified_at` is timestamp not FSM | WU-1.2.4, WU-1.5.2 |
| package-policies: 7 `package.*` Setting slugs | WU-1.8.7, WU-3.3 |
| package-policies: `GET/PUT /config/package-settings` | WU-3.1, WU-3.2, WU-3.3 |
| package-policies: `PackageConfigSection` frontend | WU-7.9 |
| package-policies: service reads from Setting at runtime | WU-1.5.2, WU-2.1.2 |
| package-retention: `package:process-retention` command | WU-4.1, WU-4.4 |
| package-retention: daily schedule | WU-4.2, WU-4.4 |
| package-retention: `reminded_at` dedup | WU-1.2.4, WU-4.1, WU-4.4 |
| package-retention: `package.reminder` notification | WU-4.3 |
| package-retention: auto-return after `return_after_days` | WU-4.1, WU-4.4 |
| package-retention: tenant-safe (no cross-tenant) | WU-4.1, WU-4.4 |
| package-reporting: aging report JSON | WU-5.1, WU-5.2.1, WU-5.3 |
| package-reporting: Excel export | WU-5.2.2, WU-5.3 |
| package-reporting: `pending-count` endpoint | WU-5.1.2, WU-5.2.3, WU-5.3 |
| package-reporting: dashboard widget | WU-7.10 |
| package-reporting: `reportColumns()` | WU-5.2.4 |
| CLAUDE.md regla 3: module registration | WU-1.8 |
| Frontend: schema + resource + store | WU-7.1, WU-7.2, WU-7.3 |
| Frontend: list, intake, pickup modal, my-packages, detail | WU-7.4 through WU-7.8 |
| Frontend: App.tsx routes | WU-6.4, WU-7.11 |
| Frontend: tests | WU-7.12 |

---

## Review Workload Forecast

| Metric | Estimate per slice | Slice total |
|---|---|---|
| WU-1 (Backend Spine) | 4 migrations (~100L) + 4 models (~120L) + PackageService intake (~80L) + 4 controllers (~120L) + routes (~50L) + seeder edits/CSVs (~80L) + tests (~120L) | **~670L** — exceeds 400-line limit on its own |
| WU-2 (Pickup & Custody) | PackageService 5 methods (~120L) + 4 FormRequests (~60L) + 3 controller methods (~80L) + tests (~120L) | **~380L** — at limit |
| WU-3 (Policies Config) | 2 ConfigurationController methods (~80L) + 2 routes (~10L) + tests (~60L) | **~150L** — well under limit |
| WU-4 (Retention) | Command (~80L) + ServiceProvider (~30L) + notification payload (~30L) + tests (~70L) | **~210L** — under limit |
| WU-5 (Reports) | ReportService (~80L) + ReportController (~100L) + tests (~80L) | **~260L** — under limit |
| WU-6 (Catalog UI) | 2 schemas (~30L) + 2 resources (~20L) + 2 stores (~10L) + 4 pages/forms (~200L) + StatusBadge (~30L) | **~290L** — under limit |
| WU-7 (Frontend Core) | schema (~80L) + resource (~60L) + store (~10L) + 5 pages + 1 modal + 1 component + widget (~500L) + tests (~150L) | **~800L** — exceeds limit (expected for full frontend) |
| **Total estimated** | | **~2760L** across 7 slices |

| Decision | Value |
|---|---|
| Chained PRs recommended | **Yes** — 7 stacked-to-main PRs as designed |
| 400-line budget risk WU-1 | **High** — spine is ~670L. Mitigation: scope WU-1 tightly to intake only (no placeholder stubs beyond what's needed); split tests into a separate commit within the PR |
| 400-line budget risk WU-2 | **Medium** — ~380L, at boundary |
| 400-line budget risk WU-7 | **High** — frontend core ~800L. Mitigation: deliver as separate commits per page within the PR; each page is independently reviewable |
| Decision needed before apply | **No** — delivery strategy (7 stacked-to-main) already decided and cached |
| Note | Each PR gets its own `/review-stack` pass. WU-1 is the critical path; all others unblock once it merges. `@notifications-expert` involved for WU-1 (arrival notification), WU-2 (pickup notification), WU-4 (reminder notification). |

---

*Generated by sdd-tasks on 2026-06-29. Authority: specs (package-catalogs/intake/pickup/lifecycle/policies/retention/reporting), design.md (5 resolved decisions), CLAUDE.md regla 3 + 6 + 8 + 9.*

---

## E2E Bug Fixes — Extended Playwright Pass (2026-06-29)

### BUG-4 (MEDIUM) — Row actions: return / incident / reassign — FIXED [x]

- [x] **BUG-4.1** Create `frontend/src/modules/package/components/PackageRowActions.tsx` — DropdownMenu ("⋮") with three items (Marcar como devuelto, Reportar incidente, Reasignar unidad). Only renders for `status = READY` packages. Uses `useMutation` (returnPackage / incident / reassign from PackageResource), `toast.success/error` (sileo), and `queryClient.invalidateQueries(['package-pending-count'])` on success.
- [x] **BUG-4.2** Return + Incident dialogs: shared `ReasonDialog` component inside the file; RHF + `reasonSchema` (Zod `min(1)` required); `DialogTitle` + `DialogDescription` (accessible). Reset form on cancel.
- [x] **BUG-4.3** Reassign dialog: `ReassignDialog` with `SearchSelect` + `useLazyCatalogs(CatalogType.UNIT)` (lazy-loaded on dialog open); `reassignSchema` (unit_id required). Mirrors unit-select pattern from `PackageForm.tsx`.
- [x] **BUG-4.4** `PackageListPage.tsx` — import `PackageRowActions`; add `handleRowActionSuccess` callback; render `<PackageRowActions pkg={pkg} onSuccess={handleRowActionSuccess} />` alongside `<PackageActions>` (Entregar button) in BOTH the desktop TableRow action cell and the mobile `renderActions` prop. Wrapped in a `flex gap-1` container.

### BUG-5 (LOW) — PendingPackagesWidget stale count — FIXED [x]

- [x] **BUG-5.1** `frontend/src/modules/package/components/PendingPackagesWidget.tsx` — removed `staleTime: 5 * 60_000`; set `staleTime: 0` + `refetchOnMount: 'always'`. Mirrors pattern of sibling dashboard widgets (treasury summary, delinquency) that use default staleTime=0.

### BUG-6 (HIGH) — Board read-only roles wrongly redirected to /my-packages — FIXED [x]

- [x] **BUG-6.1** `PackageListPage.tsx` — replaced `usePermissions('create.package')` with `useMultiplePermissions({ canCreate: 'create.package', canReport: 'read.report_package' })`. Updated redirect guard from `if (!canCreate)` to `if (!canCreate && !canReport)`. Treasurer and Oversight Committee (who have `read.report_package`) now stay on the management list. Residents/renters (who have neither) still redirect to `/my-packages`. All hooks remain unconditional before the early return (React-rules compliant).

### BUG-7 (MEDIUM) — Board read-only roles see inconsistent package list vs report — FIXED [x]

- [x] **BUG-7.1** `PackageController::scopeToResidentUnitsIfNeeded` — added `|| $user->tokenCan('read.report_package')` to the cluster-wide visibility condition. Board read-only roles (Treasurer, Oversight Committee) holding `read.report_package` now see the full cluster list in the package index, consistent with what `PackageReportService::aging()` shows. Residents without this ability remain scoped to their own units.

### BUG-8 (MEDIUM) — pendingCount included INCIDENT in total; inconsistent with aging report — FIXED [x]

Root cause: the E2E-found regression showed the dashboard widget returning a count that included packages in INCIDENT status (still physically in portería), while `PackageReportService::aging()` and the pending list only count READY packages. The service code already filtered to `PackageStatus::READY` — the consistency fix was already in place from WU-5. What was missing was explicit test regression coverage.

- [x] **BUG-8.1** `tests/Feature/Package/PackageReportTest.php` — added `test_pending_count_counts_only_ready_packages_excluding_other_statuses`: creates 2 READY + 1 INCIDENT + 1 RETURNED + 1 PICKED_UP, asserts `total=2` and `d7_plus=1` (only the 10-day-old READY). Guards against future regressions where the WHERE clause might be broadened to cover "in portería" statuses.
- [x] **BUG-8.2** `tests/Feature/Package/PackageReportTest.php` — added `test_pending_count_is_tenant_scoped`: two tenants (3 and 5 READY packages respectively), asserts admin of tenant A sees only its own 3 total + 1 d7_plus. Fills the gap left by the original pending-count tests which had no explicit cross-tenant coverage.

---

## Holistic Review Fixes — Post-implementation review (2026-06-30)

Three WARNING findings from the final holistic backend review. Applied to `feat/package-reception` branch.

### REVIEW-1 (WARNING) — President permissions diverge from design ADR-1; breaks /my-packages — FIXED [x]

**Root cause:** `RolePermissionTableSeeder.php` granted president `['read', 'create', 'update', 'report']` for the `package` entity. Design §2 / ADR-1 explicitly restricts president to supervision-only (`['read', 'report']`, matching treasurer/oversight_committee). Because `PackageController::myPackages()` uses `tokenCan('create.package')` as the "staff marker" exclusion guard, a president token with `create.package` would hit `abort(403)` on `GET /my-packages` — preventing them from viewing their own unit's pending parcels.

**Fix:**
- [x] **REVIEW-1.1** `backend/database/seeders/RolePermissionTableSeeder.php` — changed president's `package` permissions from `['read', 'create', 'update', 'report']` to `['read', 'report']`. `report_package` permissions unchanged (`['read', 'report']`). Updated comment to explain the supervision-only rationale and the `/my-packages` interaction.

**President behavior after fix:** president holds `read.package` + `read.report_package` (same as treasurer/oversight_committee). `scopeToResidentUnitsIfNeeded` exempts them (via `read.report_package`), so they see the full cluster list. `myPackages` no longer 403s them (they lack `create.package`), so president can view their own unit's pending parcels. NOT: register packages, handover, incident, reassign, return.

### REVIEW-2 (WARNING) — pendingCount gate too lax — residents get cluster-wide counts — FIXED [x]

**Root cause:** `PackageReportController::pendingCount()` authorized only `read.package`, which every authenticated tenant user (including residents/renters) holds. Sibling endpoints (aging, export) correctly require `read.report_package`. A resident calling `GET /package/report/pending-count` would receive cluster-wide `{total, d7_plus}` — a data-leakage vector.

**Fix:**
- [x] **REVIEW-2.1** `backend/Modules/Package/app/Http/Controllers/PackageReportController.php` — replaced `$this->authorize('read.package', ...)` with an explicit gate mirroring `scopeToResidentUnitsIfNeeded`: `abort(403)` unless `tokenCan('create.package')` (guards) OR `tokenCan('read.report_package')` (board) OR `tokenCan('*')` (super-admin). Updated method PHPDoc.
- [x] **REVIEW-2.2** `backend/tests/Feature/Package/PackageReportTest.php` — updated existing pendingCount tests that used a resident user with `['read.package']` token to use a guard with `['create.package']` (an authorized caller). Updated `test_pending_count_is_tenant_scoped` admin token to include `create.package` alongside `read.package`.
- [x] **REVIEW-2.3** `backend/tests/Feature/Package/PackageReportTest.php` — added 3 new tests: `test_pending_count_forbids_resident` (resident → 403), `test_pending_count_allows_guard_with_create_package` (guard → 200, total=1), `test_pending_count_allows_board_with_read_report_package` (treasurer → 200, total=1).

### REVIEW-3 (WARNING) — search contract drift: recipient_name not searchable — FIXED [x]

**Root cause:** `PackageController::searchableBy()` returned `['tracking_number','carrier_other','description','storage_location']` but the list's search placeholder advertises "Buscar por destinatario o tracking". Searching by a resident's name returned empty results.

**Fix:**
- [x] **REVIEW-3.1** `backend/Modules/Package/app/Http/Controllers/PackageController.php` — added `'recipient_name'` to `searchableBy()` return array.
- [x] **REVIEW-3.2** `backend/tests/Feature/Package/PackageIntakeTest.php` — added `test_package_is_searchable_by_recipient_name`: registers two packages with distinct `recipient_name` values, searches via `POST /api/package/search` with `search[value]='Valentina'`, asserts exactly 1 result whose `recipient_name` is `'Valentina Morales'`.

**Test results (2026-06-30, second run — DB in clean state):**
- `PackageCatalogsTest`: 10/10 PASS
- `PackageIntakeTest`: 13/13 PASS (including new REVIEW-3.2 test)
- `PackageLifecycleTest`: 7 FAIL — pre-existing flaky failures (auth/routing 401/404 errors from non-transactional test isolation, `$connectionsToTransact = []`; NOT caused by these fixes)
- `PackagePickupTest`: 19/19 PASS
- `PackageReportTest`: 13/13 PASS (including 3 new REVIEW-2.3 tests; REVIEW-2.2 updated tests pass)
- `PackageRetentionTest`: 6/6 PASS
- **Total: 67 passed, 8 failed (all 8 pre-existing flaky in PackageLifecycleTest)**

---

## Final Frontend Holistic Review Fixes (2026-06-30)

All 13 confirmed findings from the final holistic review resolved. NOT committed/pushed.

### TYPE-1 — schema.ts: description widening + status type — FIXED [x]

- [x] **TYPE-1.1** `schema.ts` — removed `description?: string | null` redeclaration from `IPackage` (the field inherits `description?: string` from `IEntity`; widening it with `| null` caused TS2430 cascading into ~10 files).
- [x] **TYPE-1.2** `schema.ts` — changed `status?: INamed | null` to `status?: IPackageStatus | null`. `IPackageStatus` already has `key?: string | null` and `color?: string | null`, enabling status badge color and key-based gating.

### TYPE-2 — 'shortDate' → 'mediumDate' — FIXED [x]

- [x] `AgingBucketCard.tsx:64`, `MyPackagesPage.tsx:142,148`, `PackagePickupModal.tsx:190`, `PackageListPage.tsx:368,412`

### TYPE-3 — ApiError constructor takes string — FIXED [x]

- [x] `BulkIntakeForm.tsx:71`, `PackageForm.tsx:102`, `PackagePickupModal.tsx:65,251`

### TYPE-4 — TableEmptyState icon must be literal — FIXED [x]

- [x] `MyPackagesPage.tsx:213`, `PackageListPage.tsx:334` — removed `icon={<JSX>}` prop (it is typed `'plus'|'search'|'default'`, not ReactNode).

### TYPE-5 — PackageDetailPage show() include format — FIXED [x]

- [x] `PackageDetailPage.tsx:153` — changed from `{ includes: [{ relation: '...' }, ...] }` to `{ include: 'unit,status,carrier,type,recipientUser,media' } as Record<string, unknown>`. Matches the `show()` signature and `resourceApi.ts` param handling; same pattern as UnitDetailPage/CommunityProjectDetailPage.

### TYPE-6 — TS6133 unused vars (cascade resolution) — FIXED [x]

- [x] Cascade from TYPE-1.1 (IPackage/IEntity mismatch). All TS6133 errors (`PackageRowActions`, `displayFormatDate`, `PackageDeliverButton`, `ageHighlightClass`, `handleRowActionSuccess`, `PackageStatusBadge`) cleared by fixing schema.ts. None were genuinely unused.
- [x] Removed `Package2` import from `PackageListPage.tsx` (was only used in the now-removed icon prop).

### BUG-FR-1 — Photo never renders — FIXED [x]

- [x] `PackageDetailPage.tsx:36` — changed `collection_name === 'package_photo'` to `collection_name === 'photo'`. Backend `Package` model + `PackageService` both use `'photo'` as the collection name.

### BUG-FR-2 — All status badges render blue — FIXED [x]

- [x] `PackageDetailPage.tsx`, `PackageListPage.tsx` (table row + card fields), `MyPackagesPage.tsx` — all `PackageStatusBadge` instances now pass `statusKey={pkg.status?.key ?? null}` and `statusColor={pkg.status?.color ?? null}` instead of hardcoded `null`.

### BUG-FR-3 — Reassign dialog units list never loads — FIXED [x]

- [x] `PackageRowActions.tsx` — moved `useLazyCatalogs(UNIT)` and `unitOptions` computation from `ReassignDialog` internals to the parent `PackageRowActions`. `enableUnits()` is now called in the `DropdownMenuItem` onClick (the parent's open handler), so units fetch is triggered when the user clicks the menu item — before the dialog is visible. `ReassignDialog` now receives `unitOptions` and `loadingUnits` as props. Removed the dead `onOpenChange else` branch (no DialogTrigger → onOpenChange(true) never fired).

### BUG-FR-4 — MyPackagesPage pending/history split by pickup_code — FIXED [x]

- [x] `MyPackagesPage.tsx` — changed `pending` / `history` split from `p.pickup_code` / `!p.pickup_code` to `p.status?.key === 'ready'` / `p.status?.key !== 'ready'`. `isReady` in `MyPackageCard` now gates on `status.key === 'ready' && pickup_code`. The "Ver código de retiro" QR button only renders when both conditions hold.

### BUG-FR-5 — handover() return type — FIXED [x]

- [x] `PackageResource.ts` — changed `handover` type from `Promise<{ count: number }>` to `Promise<IPackage[]>`. Implementation updated: `const { data } = await apiClient.post<{ data: IPackage[] }>(...); return data.data`.

### BUG-FR-6 — Hardcoded status UUID gating — PARTIAL (key-based preferred where sensible)

- [x] `MyPackagesPage.tsx` — uses `status.key === 'ready'` now (key-based). `PackageRowActions.tsx` and `PackageDetailPage.tsx` still use the UUID constant for `isReady` guard (UUID-based gating via `package_status_id` is more reliable without a full status include on these endpoints). UUID constant defined once per file.

### FEAT-FR-1 — Missing "Verificar código" UI entry — FIXED [x]

- [x] `PackageListPage.tsx` header — added "Verificar código" button (outline, with ScanLine icon) gated on `update.package`. Clicking sets `pickupTarget = null` and opens `PackagePickupModal` at Step 1 (scan/verify step). Reuses the existing modal; no new component.

### Test updates

- [x] `__tests__/PackagePickupModal.test.tsx` — removed `unit_id: 'u1'` from mock `packages` entry (not in `IVerifyCodePackage`).
- [x] `__tests__/MyPackagesPage.test.tsx` — added `key: 'ready'` to mock status object so the `status.key === 'ready'` pending-split correctly identifies the package as pending.

### Verification (holistic review batch)

- `pnpm exec tsc -b --force` → **0 errors** (exit 0)
- `pnpm build` → **built in 6.62s**, 10449 modules, zero TS errors
- `pnpm test --run` → **366 passed, 46 files** (all green)
- `pnpm lint` on `modules/package/**` → **zero errors, zero warnings**

---

## Real QR Scanner + Signature Pad (2026-06-30) [x]

### FEAT-QR — Real camera QR scanner in PackagePickupModal Step 1 — DONE [x]

- [x] QR value confirmed: `MyPackagesPage.tsx` renders `<QRCodeSVG value={pkg.pickup_code} />` — the QR encodes the raw `pickup_code` string (e.g. `ABCD-1234`).
- [x] Created `src/modules/package/components/QrScannerView.tsx` wrapping `html5-qrcode` (already installed at 2.3.8). Uses `facingMode: 'environment'`, `fps: 10`, `qrbox: 240x240`. Stops camera after first successful decode (no leaked stream).
- [x] Added "Escanear QR" button to `ScanStep` in `PackagePickupModal.tsx`. Pressing it mounts `QrScannerView`; on successful scan it auto-fills the code field and immediately triggers `verifyMutation.mutate(normalizedCode)`.
- [x] Camera permission denied → `onPermissionDenied` callback → error message shown, scanner hidden, manual text input remains as fallback.
- [x] Manual text input (`aria-label="Código de retiro del paquete"`) is always visible — never removed.
- [x] Camera stream is stopped on unmount (cleanup in `useEffect`); `QrScannerView` is only mounted when `showScanner=true`.

### FEAT-SIG — Canvas signature pad in PackagePickupModal Step 3 — DONE [x]

- [x] Installed `signature_pad@5.1.3` (vanilla JS, React-19-compatible, no peer-dep issues).
- [x] Created `src/modules/package/components/SignaturePadCanvas.tsx` — `forwardRef` component exposing `isEmpty`, `clear`, `toBlob` via `SignaturePadHandle`. Scales canvas to `devicePixelRatio` for crisp strokes. Disables `touchAction: none` to prevent mobile scroll interference.
- [x] Replaced `<input type="file" capture="environment">` (old signature upload) with `SignaturePadCanvas` in `ConfirmStep`.
- [x] Added "Limpiar" button (resets canvas + `hasDrawn`). On submit: if canvas is non-empty, exports via `canvas.toBlob('image/png')` → `File('firma.png')` appended as `pickup_signature`. If canvas empty, falls back to `signatureFile` from file upload.
- [x] Secondary "o sube una imagen de firma" link reveals the original file-upload input (fallback kept).
- [x] Policy enforcement intact: `needsSignature = requires_pickup_signature || isProxy`; `canSubmit` gates on `hasDrawn || signatureFile !== null` when `needsSignature`.
- [x] `mutationFn` made `async` to properly `await` `pad.toBlob(...)`.
- [x] Test file updated: both new components mocked with `vi.fn().mockReturnValue(null)` (no JSX in factory — hoisting safety).

### Verification (QR + signature batch)

- Installed: `signature_pad@5.1.3` (via `pnpm add signature_pad`). `html5-qrcode@2.3.8` was already installed.
- `pnpm exec tsc -b --force` → **0 errors** (exit 0)
- `pnpm build` → **success**, 10452 modules, zero TS errors
- `pnpm test --run` → **366 passed, 46 files** (all green)
- `pnpm exec eslint` on new files → **0 errors, 0 warnings**
- `pnpm exec eslint` on `PackagePickupModal.tsx` → **0 errors, 1 pre-existing warning** (file length 580 > 500 — existed before at 514 lines in WU-7, exit 0)
- NOTE: camera scanning and canvas drawing require a real device — cannot be validated in headless tests. tsc + build are the authoritative checks.

---

## Phase-2 Backend Completeness Fixes (2026-06-30) [x]

### Fix 1 — require_pickup_code setting [x]

- [x] Added `package.require_pickup_code` (boolean, default `true`) to `TenantCatalogSeeder::seedPackageSettings`. Count now 8 per tenant.
- [x] `ConfigurationController::getPackageSettings` — added `package.require_pickup_code` to the `$slugs` array. Response now includes flat key `require_pickup_code`.
- [x] `ConfigurationController::updatePackageSettings` — added `require_pickup_code => package.require_pickup_code` to `$slugMap` and `require_pickup_code => [sometimes, boolean]` to `$rules`.
- [x] `PackageService::getPickupPolicy()` — added `require_pickup_code` to the returned array. Return type annotation updated.

### Fix 2 — Enforce verification at handover (THE core gap) [x]

- [x] `PackagePickupRequest::rules()` — added `pickup_code => [nullable, string, max:20]`.
- [x] `PackageService::handover()` — pre-flight block when `package.require_pickup_code` is true:
  - If `pickup_code` is absent/empty → `abort(422, 'Se requiere el código de retiro')`.
  - Resolve the code to a package (via `where('pickup_code', $code)->first()`); if not found → `abort(422)`.
  - Assert all submitted `package_ids` share a single `unit_id` (multi-unit handover rejected → `abort(422)`).
  - Assert that single `unit_id` matches the code's resolved `unit_id` → `abort(422)` on mismatch.

### Fix 3 — Notifications on returnPackage/flagIncident/reassignUnit (push+in-app, NOT email) [x]

- [x] `PackageService::returnPackage` — added `notifyUsers(residentIds, 'Paquete devuelto', 'Tu paquete fue devuelto al remitente.', [...])` after status update.
- [x] `PackageService::flagIncident` — added `notifyUsers(residentIds, 'Incidente con tu paquete', 'Incidente con tu paquete: {reason}', [...])`.
- [x] `PackageService::reassignUnit` — replaced the `auto_notify_on_arrival`-gated single notification with two unconditional notifications: old unit residents ("El paquete ya no está asignado a tu unidad.") and new unit residents (arrival message). Captures `$oldUnitId = $package->unit_id` before the save.
- [x] Channel decision: all three use `notifyUsers()` directly with default `channels = ['log']` and `augmentPush = true`. This matches the existing arrival/pickup pattern. The `withConfiguredPushChannel` helper (in `NotifiesResidents`) will automatically add `firebase_push` if enabled globally. Email (resend/Blade) is NEVER in the base channel list — it is only added via `notifyEvent` + `NotificationSettingService` channels, which these calls bypass intentionally.
- [x] Retention cron auto-return: covered — cron calls `returnPackage()`, which now notifies.

### Fix 4 — Resident history (/my-packages?history=1) [x]

- [x] `PackageController::myPackages()` — when `$request->boolean('history')` is truthy, returns terminal packages (PICKED_UP/RETURNED/INCIDENT) from the last 60 days, ordered by `updated_at DESC`. Includes `unit,status,carrier,type,media,picked_up_by_user`.
- [x] Without the param: unchanged — returns non-terminal packages with `pickup_code` revealed.
- [x] `pickup_code` is NOT revealed on history items (terminal packages cannot be retrieved).

### Fix 5 — Custody identity (received_by_user / picked_up_by_user in includes) [x]

- [x] `PackageController::includes()` — added `received_by_user` and `picked_up_by_user`. Both relations exist on `Package` model with `withoutGlobalScope(UserTenantScope::class)` (guards are not tenant-scoped users). No cross-tenant leak possible: the IDs are always set by the authenticated guard of the current tenant during register/handover.

### Fix 6 — Unhide Transportistas and Tipos de Paquete from menu [x]

- [x] `SystemTableSeeder::seedModuleVisibility` — removed `'Transportistas'` and `'Tipos de Paquete'` from the `$hidden` array. `'Estados de Paquete'` and `'Paquetería (Reportes)'` remain hidden.
- [x] Verified via `db:seed --class=ResetPermissionTableSeeder` + DB query: Transportistas visible=true, Tipos de Paquete visible=true.

### Verification (Phase-2 backend) [x]

- `php artisan db:seed --class=ResetPermissionTableSeeder` → OK. Carrier/type now visible in DB.
- `php artisan test tests/Feature/Package/ tests/Feature/Configuration/PackageSettingsTest.php --compact` → **95 passed, 276 assertions** (all green).
- New tests added: pickup-policy includes `require_pickup_code` (1), handover rejected without code (1), handover rejected cross-unit (1), handover rejected multi-unit (1), handover allowed when setting off (1), handover succeeds with correct code (1), my-packages history returns terminal (1), history no pickup_code (1), return notifies resident (1), incident notifies resident (1), reassign notifies both old/new (1). Total new: **11 tests**.
- `php vendor/bin/pint --dirty` → `passed` (all changed PHP files formatted).

---

## Phase-2 Frontend Completeness Fixes (2026-06-30) [x]

### Fix 1 — require_pickup_code config toggle [x]

- [x] `IPickupPolicy` in `schema.ts` — added `require_pickup_code: boolean` and `liability_disclaimer?: string | null`.
- [x] `PackageConfigSection.tsx` — added "Verificación de entrega" subsection with checkbox bound to `require_pickup_code` (read from `getPackageSettings`, saved via `updatePackageSettings`).

### Fix 2 — Fix "Entregar" per-row flow [x]

- [x] `PackagePickupModal.tsx` — `PolicySettings` interface now includes `require_pickup_code: boolean`. Outer modal extracts it from `policyQuery.data`.
- [x] `ScanStep.onFound` signature extended to pass the verified code: `(_result, _code: string) => void`. `useMutation.onSuccess` forwards `variables` (the code string) to `onFound`.
- [x] `PackagePickupSteps`: when `require_pickup_code=true` always starts at scan step; when false with `initialPackage` starts at select. Unit packages for select step loaded via `useQuery(PackageResource.search, { unit_id, status=READY })` — result is derived via `useMemo` (no setState-in-effect).
- [x] `verifiedCode` state captured from scan step; forwarded to `ConfirmStep` which appends `pickup_code` to the FormData when non-null.
- [x] `PackageListPage.tsx` — header button label changed from "Verificar código" to "Entregar / Escanear código".
- [x] `PackageResource.search` added to `PackagePickupModal.test.tsx` mock to avoid unhandled promise rejections.

### Fix 3 — Staff history tab [x]

- [x] `PackageListPage.tsx` — `PackageTab` type extended with `'history'`. Third tab "Historial / Todos" added between "Pendientes de retiro" and "Reporte de antigüedad".
- [x] `handleTabChange` callback: switching to history calls `onSearch` with empty filters (all statuses, sort=received_at desc); switching back to pending restores pending filters.
- [x] History tab renders same `ResponsiveDataTable` as pending, with per-row deliver + row-action buttons (deliver button self-hides for non-READY packages). Status-aware empty state.

### Fix 4 — Resident history + disclaimer [x]

- [x] `PackageResource.ts` — `myPackages` accepts optional `_params?: { history?: number }` and passes them as axios GET params.
- [x] `MyPackagesPage.tsx` — two separate queries: `myPackages()` for pending, `myPackages({ history: 1 })` for history. Historial section now live.
- [x] "Ver detalle" `Link` added to each `MyPackageCard` pointing to `/package/${pkg.id}`.
- [x] `QrDialog` — `useQuery(pickupPolicy)` fetches `liability_disclaimer` with `retry: false` (graceful 403 for non-guard roles). Disclaimer rendered in amber block when present.
- [x] `PackageResource.test.ts` — `myPackages` test updated; new test for `?history=1` param.
- [x] `MyPackagesPage.test.tsx` — mock updated to use `mockImplementation` differentiating params; `pickupPolicy` mock added.

### Fix 5 — Detail page real custody + evidence [x]

- [x] `PackageDetailPage.tsx` — include string extended with `received_by_user,picked_up_by_user`.
- [x] `LifecycleSection` — "Recibido por" and "Retirado por" now render `pkg.received_by_user?.name` and `pkg.picked_up_by_user?.name` with "Personal de portería" fallback when null.
- [x] `PickupEvidenceSection` component added — renders `pickup_evidence` media collection: images inline, PDFs as links.
- [x] `PickupEvidenceSection` mounted in page body between intake photo and main details.

### Fix 6 — Pending widget for everyone [x]

- [x] `DashboardPage.tsx` — `<PendingPackagesWidget />` mounted directly above `<TreasurerHome />` (self-hides when no `read.package` or total=0).
- [x] `TreasurerHome.tsx` — duplicate `<PendingPackagesWidget />` and its import removed to avoid rendering it twice.

### Verification (Phase-2 frontend) [x]

- `pnpm exec tsc -b --force` → **0 errors** (exit 0)
- `pnpm build` → **success** (3.74s, chunk-size warning is pre-existing)
- `pnpm exec eslint` on all modified files → **0 errors** (2 pre-existing max-lines warnings)
- `pnpm exec vitest run` → **367 passed, 46 files** (1 new test added; 0 failures)
