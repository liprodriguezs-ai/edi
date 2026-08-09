# SDD Design — Paquetería (package reception module)

- **Change:** `package-reception`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** design (the HOW at architectural level)
- **Artifact store:** openspec (this file) + engram `sdd/package-reception/design`
- **Date:** 2026-06-29
- **Reads:** proposal (`openspec/changes/package-reception/proposal.md`), the approved plan (`~/.claude/plans/hola-estoy-buscabo-hacer-vast-lemur.md`), the 3 committed specs (`specs/package-{catalogs,intake,pickup}/spec.md`), and the LIVE reuse targets (every file cited below was read this phase): `Modules/Access/{Visitor,AccessLog,AccessService}`, `app/Notifications/Concerns/NotifiesResidents`, `Modules/Collections/{RefreshChargeStatusesCommand,CollectionsServiceProvider}`, `core/.../ReportController`, `Modules/Setting/Models/Setting`, `Modules/Configuration/ConfigurationController`, `Modules/Access/{routes/api,VisitorController}`.
- **Authority order on conflict:** laravel-backend-architecture / stancl-tenancy / react-frontend-architecture skills > `CLAUDE.md` > committed specs > this design. This design does NOT restate skill rules; it applies them. Where this design refines a proposal/plan open question, it says so and stays compatible with the already-committed specs.

This design closes the 5 open decisions left by propose/spec, grounded in the live codebase. It is the architectural HOW; the WHAT-to-do steps belong to sdd-tasks.

---

## 0. Executive summary (plain Spanish, no jargon)

El módulo de Paquetería se construye **calcando moldes que ya existen y funcionan**, no inventando arquitectura. El paquete es, estructuralmente, un "evento de portería ligado a una unidad con foto" — exactamente como el módulo de Control de Acceso (`Visitor`/`AccessLog`). Así que:

- La tabla `package` y sus catálogos siguen el patrón estándar tenant-scoped (una sola base compartida, aislada por `tenant_id`); el catálogo de estados es CENTRAL (estados fijos del sistema), y transportista/tipo son catálogos editables por el admin del clúster.
- El **código de retiro** (`pickup_code`) copia el truco del `qr_token` de Visitas: se guarda oculto (`$hidden`) y SOLO se le muestra al residente dueño; el guardia nunca lo ve, solo lo escanea y el sistema valida que coincida. Esto resuelve elegantemente el retiro por un familiar (el residente comparte el código).
- La **entrega** de 1 o varios paquetes ocurre en UNA sola transacción, con UNA firma/foto que se copia a cada paquete, y cada paquete guarda su propio registro de quién lo retiró. No se crea una tabla nueva de "entregas" — el registro de auditoría que ya trae cada modelo alcanza.
- Las **notificaciones** reutilizan el motor `NotifiesResidents` (avisar a todos los residentes activos de la unidad). El **cron de retención** copia el molde del cron de cobranza. Los **reportes** usan el exportador Excel existente. Las **políticas** del clúster viven en el store genérico `Setting`, editadas desde el módulo de Configuración igual que las de Cobranza — sin tabla nueva.
- Todo el back vía Orion + un Service; todo el front vía los helpers CRUD compartidos. UI en español, identificadores en inglés, aislamiento por tenant de punta a punta.

**Las 5 decisiones quedan resueltas así:** (1) `package` lleva los 6 permisos estándar + `read/report.report_package`; los catálogos read/create/update/delete; las acciones de portería (verify/handover/return/incident/reassign) se autorizan con `update.package`; las políticas reutilizan `read/update.configuration` (NO se crea `package_config`). (2) `photo` = una sola imagen en intake; `pickup_evidence` = colección multi-archivo (firma + evidencia, imagen o PDF), copiada a cada paquete. (3) `pickup_code` = `XXXX-XXXX` con alfabeto sin caracteres ambiguos (sin O/0/I/1), generado en alta con loop acotado de unicidad, `$hidden` salvo para el residente dueño. (4) Handover atómico en una transacción; evidencia compartida copiada a cada paquete; sin tabla de handover. (5) 7 slices encadenados, **stacked-to-main**.

---

## 1. Architecture approach

### 1.1 Pattern & layering (no new architecture)

This change rides EXISTING, proven seams:

| Concern | Mold reused (live file) | What we copy |
|---|---|---|
| Entity + intake + QR-style code + media | `Modules/Access` (`Visitor`, `AccessService`) | FK→unit, `registerMediaCollections`, `$hidden` credential + `makeVisible`, bounded unique-token loop |
| Orion controller + resident scope | `VisitorController` | `$keyForPermission`, `includes/filterableBy/sortableBy/searchableBy`, `buildIndex/ShowFetchQuery` override, `performStore`→Service, `afterIndex/Show/Search` reveal |
| Multi-channel notifications | `NotifiesResidents` trait | `unitResidentUserIds`, `notifyUsers` (DB::afterCommit, never raw `DB::`) |
| Per-tenant daily cron | `RefreshChargeStatusesCommand` + `CollectionsServiceProvider::registerCommandSchedules` | `Tenant::all()` → `$tenant->run(fn)`, `$schedule->command(...)->dailyAt(...)` |
| Cluster policies (key/value) | `Setting` + `ConfigurationController::get/updateCollectionSettings` | `Setting::getValue`, `updateOrCreate(['slug'=>..],..)`, dotted-slug validation workaround |
| Excel export | `core/.../ReportController` + `ReportExport` | `setCollection/setHeadings/setMap` + `Excel::download` (we do NOT touch the legacy `customReport` switch) |
| Frontend CRUD + config UI | `frontend/src/modules/unit/**`, `CollectionConfigSection.tsx`, shared `resourceApi`/`EntityStore`/`useBaseList` | createCrudApi + customMethods, `useSectionEdits`, `ResponsiveDataTable` |

### 1.2 Module boundaries (who owns what)

| Concern | Owner | New file(s) |
|---|---|---|
| `package` + 3 catalogs, intake/pickup/custody, retention, aging | **new module `Modules/Package`** | models, controllers, `PackageService`, `ProcessPackageRetentionCommand`, requests, migrations, routes, providers |
| Cluster policy endpoints `/config/package-settings` | **`Modules/Configuration`** (existing) | 2 methods on `ConfigurationController` + 2 routes |
| Module registration (sidebar + permissions + catalogs + Setting defaults) | root `database/seeders/` + CSVs | edits to CSVs + 4 seeders + `modules_statuses.json` |
| Frontend `package` module + config section + dashboard widget | **frontend** | `modules/package/*`, `App.tsx`, `iconMap.ts` |

Policies live in Configuration (not Package) because that module already owns `/config/*` and the `Setting` store; adding `package.*` there mirrors `collections.*` exactly and reuses its auth + dotted-slug handling.

### 1.3 Data flow (intake → notify → pickup, end to end)

```
INTAKE
[PackageForm] --POST /api/package--> PackageController::performStore
   -> PackageService::register(data, guard)  (DB::transaction)
      set received_*; status=READY; generateUniquePickupCode() if pickup_code_enabled
      addMedia(photo); persist; set notified_at
   -> (afterCommit) NotifiesResidents::notifyUsers(unitResidentUserIds, 'package.received', click_action:/my-packages)

PICKUP
[MyPackagesPage] --GET /api/my-packages--> own units' non-terminal packages (pickup_code REVEALED) -> qrcode.react
[PackagePickupModal] scan QR --POST /api/package/verify-code--> ready packages for that unit (code HIDDEN)
   guard multi-selects --POST /api/package/handover--> PackageService::handover(ids, {proxy, signature, evidence}, guard)
      (DB::transaction) policy check -> per package: picked_up_*; status=PICKED_UP; copy evidence media
   -> (afterCommit) notifyUsers('package.picked_up')
```

---

## 2. DECISION 1 — Permission granularity (CLOSED)

Verified against the live seeders' contract (per the tableros-contables precedent): `MenuController` gates the sidebar on `read.<entity>` OR `report.<entity>`; `account.owner`/`company.admin` inherit any new row via `whereNotIn`; the 11 Kivo7 roles are EXPLICIT allow-lists in `seedKivo7RolePermissions()`.

| Entity | Rows seeded | Rationale |
|---|---|---|
| `package` | `read, create, update, delete, restore, report` (standard 6) | A real CRUD entity (unlike read-only tableros). |
| `report_package` | `read, report` (2) | Aging report/export; read-only — matches the tableros read-only report convention. |
| `package_carrier` | `read, create, update, delete` (4) | Admin-editable tenant catalog (spec `package-catalogs` gates create/update/delete; read open to any tenant user). |
| `package_type` | `read, create, update, delete` (4) | Same. |
| `package_status` | `read` (1) | Central FSM, read-only for tenant roles. |
| ~~`package_config`~~ | **none — DROPPED** | See correction below. |

**Custom actions authorize an EXISTING ability (no new verbs):**

| Route | Authorize | Why |
|---|---|---|
| `POST /api/package/verify-code` | `update.package` | Guard custody action (security/admin hold `update.package`; residents don't → 403, satisfies pickup-spec scenario). |
| `POST /api/package/handover` | `update.package` | Mutates custody. |
| `POST /api/package/{id}/return|incident|reassign` | `update.package` | Status/custody mutations. |
| `POST /api/package/batch` | `create.package` | Bulk intake. |
| `GET /api/my-packages` | `read.package` + **staff exclusion** | Residents/renters only. Guards ALSO hold `read.package`, so the endpoint 403s callers holding `create.package` (the staff marker), mirroring `VisitorController::scopeToOwnVisitorsUnlessStaff` which keys off `read.access_log`. Satisfies "Guard cannot call my-packages → 403". |

> **CORRECTION to proposal §4 / plan (load-bearing): NO `package_config` permission entity.** Every existing `/config/*` endpoint authorizes `read.configuration` / `update.configuration` (one ability for the whole Configuration screen — verified in `ConfigurationController::requireAbility`). The `/config/package-settings` endpoints MUST follow that same pattern, NOT introduce a per-section `package_config` ability that no sibling uses. Audience (Administrator/Account Owner) is already exactly who holds `update.configuration`. This deletes 2 dead permission rows and avoids fighting the established Configuration auth model.

**Role grants in `seedKivo7RolePermissions()` (explicit allow-lists):**

| Role | Package abilities |
|---|---|
| `security` | `create/read/update.package`, `read.package_carrier`, `read.package_type`, `read.package_status` |
| `resident`, `renter` | `read.package` only (scoped to own units via `buildIndexFetchQuery`; sees own `pickup_code` via `makeVisible`) |
| `administrator` | all `package` (6) + carrier/type CRUD + `read/report.report_package` (configuration already held) |
| `president`, `treasurer`, `oversight_committee` | `read.package` + `read/report.report_package` (oversight, no mutation) |
| `account.owner` / `company.admin` | inherit automatically via `whereNotIn` — add NONE of these to `$permissionsOnlySuperAdmin` |

---

## 3. DECISION 2 — Media collections (CLOSED)

Mirror `Visitor::registerMediaCollections` mime restrictions. Two collections on `Package`:

| Collection | Cardinality | Mimes | Notes |
|---|---|---|---|
| `photo` | `singleFile()` | image/jpeg, image/png, image/webp | Intake photo of the parcel (one is enough). |
| `pickup_evidence` | **multi-file (NO singleFile)** | image/jpeg, png, webp **+ application/pdf** | Holds BOTH the handover `pickup_signature` (image) and `pickup_evidence` (image/PDF) the pickup-spec defines as separate upload fields. One handover captures these ONCE; the service **copies each file into every selected package's `pickup_evidence` collection** so each package has its own media record (per-package custody) pointing at the same upload (pickup-spec: "each package MUST have its own media record linking to the same upload"). |

> **Visibility note (security):** the residents-only `$hidden` rule applies ONLY to the `pickup_code` STRING. Media URLs (`photo`, `pickup_evidence`) are returned normally per Spatie/Orion — they are NOT subject to the code-hiding rule.

---

## 4. DECISION 3 — `pickup_code` format + collision (CLOSED)

- **Format:** `XXXX-XXXX` (9 chars: 4 + dash + 4), uppercase. **Alphabet = `ABCDEFGHJKMNPQRSTUVWXYZ23456789`** (Crockford-style: excludes the ambiguous `I L O 0 1`). This is the proposal/plan's explicit ask and stays compatible with the pickup-spec ("uppercase alphanumeric") — it is a curated subset of alphanumerics, human-readable off a phone screen. The base62 `Str::random(4)` of `AccessService::generateUniqueToken` is therefore NOT reused verbatim; we reuse its **bounded-loop uniqueness mechanism**, not its alphabet.
  - *Reconciliation note for sdd-tasks:* the pickup-spec sentence "matching the pattern used by `generateUniqueToken`" refers to the collision-loop pattern, not the exact alphabet. No spec edit required; if a reviewer reads it as the literal alphabet, prefer this curated set and note the rationale.
- **Generation:** in `PackageService::register`, only when `Setting::getValue('package.pickup_code_enabled') === true`. A `generateUniquePickupCode()` helper runs a **bounded loop (10 attempts)**: build code → `Package::where('pickup_code', $code)->exists()` (tenant-scoped via `BelongsToTenant`) → throw `RuntimeException` after 10 (mirrors `generateUniqueToken`). The DB partial unique index `unique(tenant_id, pickup_code) WHERE deleted_at IS NULL` backstops the race.
- **Visibility (`$hidden`):** `pickup_code` in `Package::$hidden`. Revealed via `->makeVisible('pickup_code')` ONLY when the caller is an **active member of the package's unit** (`unit_user.end_date IS NULL`). Packages are guard-created, so — UNLIKE Visitor (which keys off `created_by_user_id`) — the reveal predicate is **unit membership**, applied in `afterIndex`/`afterShow`/`afterSearch` and in the `my-packages` endpoint. `verify-code` runs as the guard (a non-member) → `makeVisible` NOT applied → code stays hidden (satisfies "response MUST NOT include pickup_code").

---

## 5. DECISION 4 — Handover atomicity (CLOSED)

**Chosen:** single `DB::transaction` over the selected `package_ids`; ONE shared signature/evidence captured once and **copied to each package**; per-package custody stamped on each row. **No `package_handover` table.**

`PackageService::handover(array $packageIds, array $data, User $guard)`:
1. Load all packages tenant-scoped; reject (HTTP 422, whole op) if any is missing or not `READY` (pickup-spec).
2. Policy gate (read `Setting`): `requires_pickup_signature` → `pickup_signature` mandatory; `requires_pickup_evidence` → `pickup_evidence` mandatory; else 422 referencing the missing field.
3. In the transaction, per package: `picked_up_at=now()`, `picked_up_by_user_id=$guard->id`, `pickup_proxy_name`, `pickup_proxy_relationship`, `status=PackageStatus::PICKED_UP`; `addMedia(copy)->toMediaCollection('pickup_evidence')` for signature + evidence.
4. After commit, `notifyUsers('package.picked_up')`.

**Alternatives considered**

| Option | Tradeoff | Decision |
|---|---|---|
| Per-package custody on the package row + copied evidence (chosen) | Per-package audit trail already captured via Laravel Auditing; no schema growth | **Accepted** — simplest that preserves per-package custody |
| New `package_handover` record (1 row) + `package_handover_id` FK on each package | Cleaner handover-level reporting, but adds a table/model/migration for marginal v1 benefit | Rejected (note as future option if handover-level reporting is needed) |

Rollback is guaranteed by the single transaction (pickup-spec: "if any package fails, ALL changes are rolled back").

---

## 6. Backend specifics

### 6.1 `package` table (tenant-scoped, `BaseModel::columns($table, true)` + `BelongsToTenant`)
Columns exactly per the committed **package-intake** spec table (each `->comment()`; closure opens with `$table->comment('Paquetes recibidos en la portería del clúster')`). Indexes on `unit_id`, `package_status_id`. Add the partial unique `unique(tenant_id, pickup_code) WHERE deleted_at IS NULL`. `received_by_user_id` / `picked_up_by_user_id` carry NO FK (guard pattern, like `AccessLog.guard_user_id`). `recipient_user_id` is an FK→`user` (central table). Relationships: `unit`, `status` (→`PackageStatus`), `carrier` (→`PackageCarrier`), `type` (→`PackageType`), `recipient_user`, plus `received_by_user`/`picked_up_by_user` (`belongsTo(User)` `withoutGlobalScope(UserTenantScope)`, mirroring `Visitor::created_by_user`).

### 6.2 Catalogs (per the committed package-catalogs spec)
- `package_status` — `CentralCatalogModel`, `BaseModel::columns($table, false)`, UUID constants `RECEIVED/READY/PICKED_UP/RETURNED/INCIDENT`, seeded by `CatalogSeeder`. NB: `register` sets `READY` directly; `received` exists as a reserved state for the lifecycle FSM.
- `package_carrier`, `package_type` — `BaseModel::columns($table, true)` + `BelongsToTenant`, columns `name`, `is_active` (bool, def true), `order` (int, def 0), `unique(['tenant_id','name'])` non-deleted. Seeded per-tenant by `TenantCatalogSeeder` on `TenantCreated` (carriers: DHL, Estafeta, FedEx, UPS, Amazon, Mercado Libre, Correos de México, Otro; types: Sobre, Caja, Voluminoso, Perecedero, Frágil).

### 6.3 `PackageService` (uses `NotifiesResidents`)
`register`, `batchRegister` (one transaction over all rows), `verifyPickupCode(code)` (find by code in tenant → 404/422 per status → return all `READY` packages of that unit, code hidden), `handover` (§5), `returnPackage(pkg, reason)`→RETURNED, `flagIncident(pkg, reason)`→INCIDENT, `reassignUnit(pkg, newUnitId)` (edit `unit_id`, audited, no extra column), `generateUniquePickupCode()` (§4), `processRetention()` (cron body). Notifications via `notifyUsers` direct (ad-hoc, matching `AccessService`) with `click_action:'/my-packages'` + `NotificationCategory`; the `auto_notify_on_arrival` toggle gates the call at the service level.

### 6.4 Controllers + routes (`Modules/Package/routes/api.php`)
`PackageController` (Orion; `includes(['unit','status','carrier','type','recipient_user'])`, `filterableBy(['unit_id','package_status_id','package_carrier_id','package_type_id','received_at'])`, `sortableBy(['received_at'])`, `searchableBy(['tracking_number','carrier_other','description','storage_location'])`; `buildIndexFetchQuery`/`buildShowFetchQuery` scope residents to own units; `performStore`→`register`; `afterIndex/Show/Search` reveal `pickup_code` to unit members). Catalog controllers: `PackageStatusController` (read-only), `PackageCarrierController`, `PackageTypeController`. `PackageReportController` (aging JSON + Excel export). FormRequests array-style: `PackageCreate/Update/Pickup/BatchRequest`.

> **Route ordering gotcha (verified in Access routes):** literal GET/POST custom routes (`my-packages`, `package/verify-code`, `package/handover`, `package/batch`, `package/{id}/...`, `unit/{id}/packages`) MUST be registered under `$customMiddleware = ['auth:sanctum','rejectRefreshToken','authActive','initializeTenancy']` and the literal-path ones (`my-packages`) declared BEFORE `Orion::resource('package', ...)`, or `package/{id}` swallows them and 500s parsing the literal as a UUID.

### 6.5 Policies (Configuration module)
Add `getPackageSettings`/`updatePackageSettings` to `ConfigurationController` + `GET/PUT /config/package-settings`, gated `read/update.configuration`. Slugs (seeded via `Setting::firstOrCreate` on `TenantCreated`): `package.auto_notify_on_arrival` (bool, true), `package.pickup_code_enabled` (bool, true), `package.requires_pickup_signature` (bool, false), `package.requires_pickup_evidence` (bool, false), `package.reminder_after_days` (int, 3), `package.return_after_days` (int, 10), `package.liability_disclaimer` (text).

> **Dotted-slug gotcha (verified):** Laravel's validator and `$request->input()` treat the `.` in `package.reminder_after_days` as nested traversal → silent no-op. Reuse `updateCollectionSettings`' exact workaround: read from `$request->all()`, validate with rule keys whose dots are escaped (`str_replace('.', '\.', $slug)`), `Setting::updateOrCreate` per slug with the type-mapped value.

### 6.6 Retention cron
`ProcessPackageRetentionCommand` (`package:process-retention`), body iterates `Tenant::all()` → `$tenant->run(fn)`: notify `READY` packages with age ≥ `reminder_after_days` (`package.reminder`), flag/suggest return for age ≥ `return_after_days`. Registered in `PackageServiceProvider::registerCommands()` + `registerCommandSchedules()` (`->dailyAt('08:00')`), copying `CollectionsServiceProvider`.

### 6.7 Reports
`PackageReportController::aging()` → JSON aging buckets (0-2 / 3-7 / 7+ days since `received_at` for non-terminal packages), tenant-scoped Eloquent aggregation (never `DB::` facade), authorize `read.report_package` — feeds the dashboard "pendientes" widget. `PackageReportController::export()` → Excel via `ReportExport` (`setCollection/setHeadings/setMap` + `Excel::download`); we do NOT extend the legacy `ReportController::customReport` switch.

---

## 7. Frontend (`frontend/src/modules/package/`)

`schema.ts` (`IPackage`, `IPackageStatus`, `IPackageCarrier`, `IPackageType`, `packageFormSchema`, `packagePickupSchema`, `packageBatchSchema`, `*FormValues` — import entity types, no `IXxxResponse` wrappers, no `any`). `store/packageStore.ts` (`createEntityStore`). `services/PackageResource.ts` (`createCrudApi` + customMethods: `verifyCode`, `handover`, `returnPackage`, `incident`, `reassign`, `batch`, `myPackages`, `aging`). `hooks/usePackageList.ts` (`useBaseList`). Pages: `PackageListPage` (pending + aging, `ResponsiveDataTable`, age badge, storage location), `PackageForm` (fast intake + bulk mode), `PackagePickupModal` (scan QR → verify-code → multi-select → one signature/photo per policy → confirm), `MyPackagesPage` (resident; code/QR via `qrcode.react`, shareable), `PackageDetailPage` (custody timeline from Laravel Auditing), `components/PackageConfigSection.tsx` (policies; mold `CollectionConfigSection` + `useSectionEdits`). Dashboard widget "Paquetes pendientes de retiro". `App.tsx`: `<PermissionRoute permissionEntity="package">` for `/package`, `/package/:id`, `/my-packages`, lazy. `iconMap.ts`: add `IconBox`. Skeletons via `boneyard-js`; toasts via `sileo` `toast`; dates via `displayFormatDate`; UI 100% Spanish.

---

## 8. Module registration (regla 3)
`csv/modules.csv` (1 row, `name="Paquetería"`), `csv/permissions.csv` (rows per §2), `csv/module_system.csv` (tenant business system + group + icon `IconBox`); `SystemTableSeeder` (`seedModulePermissionEntities` `'Paquetería'=>'package'`, `seedModuleRoutes` `/package`, `seedModuleIcons` `IconBox`, `seedModuleVisibility` hide the central `package_status`); `RolePermissionTableSeeder` (§2 grants); `CatalogSeeder` (`package_status`); `TenantCatalogSeeder` (carrier/type); `Setting` defaults on `TenantCreated` (§6.5); `module.json` + `modules_statuses.json` (`true`); `App.tsx` route. Then `php artisan migrate` + `db:seed --class=ResetPermissionTableSeeder`.

---

## 9. Testing strategy (Standard Mode; strict_tdd FALSE)

> Backend tests run on **host php** (`127.0.0.1:5432`), NOT Sail (project memory `backend-tests-run-on-host-php`).

| Layer | What to test | Approach |
|---|---|---|
| Backend (PHPUnit feature) | register single/bulk; resident scope; `verify-code` returns unit's ready packages with NO code; handover with/without signature per policy; proxy fields; return/incident/reassign; retention cron; tenant isolation; `pickup_code` `$hidden` (resident sees own via my-packages, guard does not) | seed tenant A + B, auth as A-user with the relevant ability; assert by data presence/absence; reuse module factories |
| Frontend (Vitest + TL) | Zod schema parse; Resource customMethod paths; render smoke per page (KPIs, QR render, pickup modal multi-select) | mock `apiClient`/query; ResizeObserver polyfill already in `src/test/setup.ts` |
| Gates | `vendor/bin/pint`; `pnpm lint` + `pnpm build`; `/review-stack` per slice (laravel-architecture + react-frontend + integration + tenancy), `@notifications-expert` for the notification events | no CRITICAL before merge |

---

## 10. Migration / rollout & DECISION 5 — PR chain (CLOSED)

`php artisan migrate` (single-DB; no per-tenant pass). Reversible per slice (drop new tables/rows; `modules_statuses.json` flips the module off). **7 stacked slices, chain strategy = `stacked-to-main`** (each slice is independently valuable and shippable — the spine alone gives working intake+notify; residents get value immediately; fix-on-the-go fits the team velocity). `feature-branch-chain` is the fallback only if the team wants to gate the whole module behind one coordinated release.

| # | Slice | Depends on | Boundary / rollback |
|---|---|---|---|
| 1 | **Backend spine** — module scaffold, `package` + `package_status` (+ `package_carrier`/`package_type` TABLES + seed + read-only API so intake can reference them), Orion CRUD + `register` + notify, FormRequests, menu/permissions/seeders | — | drop tables + seeder rows + flip module off |
| 2 | **Pickup & custody** — `pickup_code` gen+verify, `handover`, `return`, `incident`, `reassign` | 1 | drop custom routes/methods |
| 3 | **Policies config** — `package.*` slugs, `/config/package-settings`, `PackageConfigSection`, disclaimer | 1 (settings default safely even if absent) | remove 2 methods/routes |
| 4 | **Retention & reminders** — `package:process-retention` + schedule + `package.reminder` | 1, 2 | unregister command |
| 5 | **Reports & dashboard** — aging JSON + Excel export + pending widget | 1 | remove report controller/route |
| 6 | **Tenant catalog admin UI & extras** — carrier/type admin CRUD UI, returned/incident polish | 1 (tables already exist) | remove UI |
| 7 | **Frontend core** — list/aging, intake+bulk, pickup modal, my-packages (code/QR), detail timeline | interleaves per backend slice | per-page removal |

> Slice 1 tightens the plan: the carrier/type TABLES + seed + read API move INTO slice 1 (intake's carrier dropdown needs them); only their ADMIN CRUD UI stays in slice 6. This avoids a forward FK dependency.

---

## 11. Open items for sdd-tasks/apply (not blockers)
- Confirm the tenant business `system_id` to mount the module (read `module_system.csv` for the system Dashboard/Collections use).
- Confirm `Unit::full_identifier` accessor exists for `verify-code` payload `unit.full_identifier` (memory note `unit-full-identifier-input-convention` suggests it does).
- The lifecycle/policies/retention/reporting specs are not yet authored; sdd-tasks should ensure tasks reference this design where those specs are thin.
- `package_status` badge color column (optional) — decide in apply if the UI needs a status color, else omit.

---

## 12. ADR-style decision log

### ADR-1: `package` seeds 6 verbs + `read/report.report_package`; custom actions reuse `update.package`; NO `package_config`
- **Status:** Accepted. **Context:** real CRUD entity; `/config/*` siblings all authorize `read/update.configuration`; `MenuController` only consults `read/report`.
- **Decision:** standard 6 for `package`, read-only `report_package`, catalog read/create/update/delete, custody actions authorize `update.package`, `my-packages` adds a `create.package` staff-exclusion; policy endpoints reuse `read/update.configuration`.
- **Rejected:** a `package_config` per-section ability (no sibling uses one; 2 dead rows); dedicated `verify/handover` verbs (custody mutations are semantically `update.package`).

### ADR-2: `pickup_evidence` is one multi-file collection copied per package; no handover table
- **Status:** Accepted. **Context:** handover is 1+ packages, one signature/evidence, per-package custody required.
- **Decision:** copy the shared signature+evidence into each package's `pickup_evidence` collection inside one transaction; stamp custody per package.
- **Rejected:** a `package_handover` record + FK — schema growth for marginal v1 benefit; audit trail already gives per-package custody.

### ADR-3: `pickup_code` uses an unambiguous alphabet, `$hidden`, revealed by UNIT MEMBERSHIP
- **Status:** Accepted. **Context:** human-read code shared with a proxy; guard must never see it; packages are guard-created (no owner-by-creator key).
- **Decision:** `XXXX-XXXX` from `ABCDEFGHJKMNPQRSTUVWXYZ23456789`, bounded uniqueness loop + partial unique index; `$hidden` revealed via `makeVisible` only to active members of `package.unit`.
- **Rejected:** base62 `Str::random` verbatim (ambiguous chars hurt readability); keying reveal on `created_by_user_id` (that is the guard, not the resident).

### ADR-4: Reuse molds — Access (entity/QR/media), NotifiesResidents, Collections cron, Setting/Configuration, ReportExport
- **Status:** Accepted. **Context:** every needed capability already exists in a battle-tested seam.
- **Decision:** copy the seams listed in §1.1; do NOT invent new architecture nor touch the legacy `ReportController::customReport` switch.
- **Consequences:** minimal new surface, consistent with siblings, tenant isolation inherited via `BelongsToTenant` + scoped Eloquent.

### ADR-5: 7 stacked slices, `stacked-to-main`; carrier/type tables move into slice 1
- **Status:** Accepted. **Context:** full-stack feature > 400-line budget; each slice independently valuable.
- **Decision:** stacked-to-main; spine carries the catalog tables intake references; admin UI for catalogs deferred to slice 6.
- **Rejected:** feature-branch-chain as default (coordinated-release control unneeded since slices ship value incrementally) — kept as fallback.
