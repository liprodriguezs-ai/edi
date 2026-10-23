# Design: EDI Stimulation Sessions

Change: `edi-stimulation-sessions`

## 1. Context

The legacy system modeled early stimulation as a generic form engine — `document → document_section → document_question → document_question_options` — to serve exactly ONE form of 16 questions across 4 fixed sections. This change replaces that with a concrete domain entity in a new nwidart module `Stimulation`. House patterns from `edi-clinic-and-child-registry` apply verbatim: `BaseModel::columns($table, true)` + `BelongsToTenant`, no DB-level FKs (uuid + index + tenant-scoped `exists` validation), Postgres partial uniques via `DB::statement`, Orion controllers extending `QCH\Core\Http\Controllers\Controller`, fixed-UUID central catalogs via `CatalogSeeder::upsertCatalog`, tenant catalogs via `TenantCatalogSeeder::CATALOGS`. Dependencies (`child`, `visit`, `health_center`, `practitioner_profile`) are delivered; Screening is NOT referenced by any FK (the referral wiring belongs to `edi-followup-and-referral`).

## 2. Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | Concrete entity, not a form engine: the four official sections (integración, preparación, actividad central, relajación) are COLUMN GROUPS on `stimulation_session`; the only multi-valued data become two narrow join tables | One fixed form does not justify meta-modeling. Columns give typed validation, honest queries/reports, and readable code. The spec forbids `document*`-style tables (REQ-SS-001) |
| D2 | All section fields nullable; only `session_date`, `practitioner_id`, and the subject are required | The record captures what was actually performed in a real session; there is no "completeness gate" for clinical narrative fields |
| D3 | Material/song columns (`integration_material`, `integration_song`, `preparation_song`, `core_material`, `core_song`) are free-TEXT snapshots; `didactic_material` and `song` tenant catalogs only feed combobox suggestions | Column names carry no `_id` in the target model — they are values, not FKs. A session is a historical record: renaming a catalog row later must not rewrite what was used. Free text also matches field reality (improvised materials/songs) |
| D4 | `session_section` ships as a CENTRAL catalog (fixed UUIDs, keys integration/preparation/core/relaxation, `sort_order`) with NO FK consumer in v1 | Mandated by the product brief: it preserves the official section vocabulary/order as data for rendering and export labels — the honest replacement of legacy `document_section`. Flagged in open decisions (Q3) |
| D5 | Subject rule: API enforces EXACTLY ONE of `child_id`/`stimulation_group_id` (422 on both/neither); DB enforces at-least-one via `ALTER TABLE ... ADD CONSTRAINT stimulation_session_subject_check CHECK (child_id IS NOT NULL OR stimulation_group_id IS NOT NULL)` (raw statement — the fluent builder cannot express it) | Individual and group sessions are distinct events; a row with both would be ambiguous for reads and reports. The DB CHECK follows the brief exactly as a backstop |
| D6 | `practitioner_id` → `practitioner_profile.id` (clinic D6 precedent); `promoter_user_id` → `user.id` validated as tenant member | The session is a clinical act by a licensed professional; the group promoter (promotora) may not hold a license — a plain tenant user is the right reference |
| D7 | `stimulation_session_movement` and `stimulation_session_relaxation_zone` are written ONLY via declarative arrays in the session payload, synced transactionally by `StimulationSessionService` (create missing, soft-delete removed, restore re-added); no standalone Orion resources | Per-row CRUD for grid data is chatty and lets a session and its rows drift. Declarative sync keeps one write path and one transaction |
| D8 | `stimulation_attendance` natural key (`stimulation_group_id`, `child_id`, `session_date`) uses a PLAIN unique index (NOT partial), deliberately departing from the house partial-unique pattern; no destroy endpoint; upserts restore trashed rows | The row is an upsertable FACT whose identity never changes. A partial unique would allow a trashed duplicate to block nothing while the upsert re-creates rows endlessly; with a plain unique, (group, child, date) always resolves to one physical row |
| D9 | Attendance bulk upsert: one transaction; per-entry `updateOrCreate` with `withTrashed()` lookup + restore; on a concurrent-insert 23505 the service retries that entry once (it becomes an update) | Idempotency and race-convergence by construction (REQ-SA-001); the batch is atomic — one invalid entry aborts everything (REQ-SA-002) |
| D10 | `StimulationGroupMemberController` and `StimulationAttendanceController` declare `$keyForPermission = 'stimulation_group'`; no own module rows/permissions | Clinic D11 precedent (`child_caregiver`/`birth_record` under `child`): sub-resources of the group share its permission like a single operational file |
| D11 | `stimulation_group.is_active` gates enrollment and attendance writes (422), never reads; no auto-close from `end_date` | History must stay readable/reportable after a group ends; deactivation is an explicit admin act |
| D12 | Registration: 11 module rows (2 domain + 6 tenant catalogs + 3 hidden central catalogs), 66 permissions, 9 `$mapperSelects`/`CatalogType` entries; children are NEVER exposed via `POST /selects` — child pickers use the child list endpoint | Clinic D12: dumping every minor's name into a generic unpaginated catalog endpoint is a PII and volume smell. Groups ARE in selects (bounded, non-PII) |

## 3. Data model (definitive)

All transactional tables: `$table->comment('...')` first line inside the closure, then `BaseModel::columns($table, true)`; every own column `->comment()`; FK-style columns `uuid(...)->index()`; no DB FKs; partial uniques via `DB::statement(... WHERE deleted_at IS NULL)` except where D8 applies.

**`stimulation_session`** — 'Early stimulation session (interacción oportuna): concrete record with the four official sections as column groups'

| Column | Type | Constraints | Comment |
|---|---|---|---|
| `child_id` | uuid, index | nullable | FK to child (individual session); API enforces exactly one subject (REQ-SS-002) |
| `stimulation_group_id` | uuid, index | nullable | FK to stimulation_group (group session) |
| `visit_id` | uuid, index | nullable | FK to visit; individual sessions only; must belong to the same child |
| `session_date` | date | NOT NULL | Date the session took place (not in the future) |
| `practitioner_id` | uuid, index | NOT NULL | FK to practitioner_profile who conducted the session (D6) |
| `integration_dynamic_id` | uuid, index | nullable | Integration section: FK to tenant catalog stimulation_dynamic |
| `integration_material` | string | nullable | Integration section: material used; free-text snapshot, suggested from didactic_material (D3) |
| `integration_song` | string | nullable | Integration section: song used; free-text snapshot, suggested from song catalog |
| `preparation_song` | string | nullable | Preparation (massage) section: song used; free-text snapshot |
| `core_stimulation_area_id` | uuid, index | nullable | Core section: FK to central stimulation_area worked in the central activity |
| `core_dynamic_id` | uuid, index | nullable | Core section: FK to tenant catalog stimulation_dynamic |
| `core_activity` | text | nullable | Core section: description of the central activity performed |
| `core_material` | string | nullable | Core section: material used; free-text snapshot |
| `core_song` | string | nullable | Core section: song used; free-text snapshot |
| `relaxation_notes` | text | nullable | Relaxation section: notes |
| `observations` | text | nullable | General observations of the session |

Constraints/indexes: CHECK `stimulation_session_subject_check` (D5); `$table->index(['tenant_id', 'session_date'])`; `$table->index(['tenant_id', 'child_id'])`; `$table->index(['tenant_id', 'stimulation_group_id'])`.

**`stimulation_session_movement`** — 'Massage pair applied in the preparation section: body zone × movement'
`session_id` uuid idx NOT NULL 'FK to stimulation_session' · `body_zone_id` uuid idx NOT NULL 'FK to tenant catalog body_zone' · `massage_movement_id` uuid idx NOT NULL 'FK to tenant catalog massage_movement'.
`CREATE UNIQUE INDEX stimulation_session_movement_unique ON stimulation_session_movement (session_id, body_zone_id, massage_movement_id) WHERE deleted_at IS NULL`. (FK column is `session_id` per the target model; the model declares the explicit key on the `stimulation_session()` relation.)

**`stimulation_session_relaxation_zone`** — 'Body zone relaxed in the relaxation section of a session'
`session_id` uuid idx NOT NULL · `body_zone_id` uuid idx NOT NULL.
`CREATE UNIQUE INDEX stimulation_session_relaxation_zone_unique ON stimulation_session_relaxation_zone (session_id, body_zone_id) WHERE deleted_at IS NULL`.

**`stimulation_group`** — 'Early stimulation group at a health center, with enrollment and attendance'

| Column | Type | Constraints | Comment |
|---|---|---|---|
| `name` | string | NOT NULL | Group name; unique per tenant among non-deleted rows |
| `stimulation_group_type_id` | uuid, index | NOT NULL | FK to tenant catalog stimulation_group_type |
| `health_center_id` | uuid, index | NOT NULL | FK to health_center where the group meets |
| `promoter_user_id` | uuid, index | NOT NULL | FK to user who leads/promotes the group; must be a tenant member (D6) |
| `start_date` | date | NOT NULL | Date the group starts operating |
| `end_date` | date | nullable | Date the group ends; must be >= start_date |
| `is_active` | boolean | NOT NULL default true | Whether the group accepts enrollment and attendance writes (D11) |

`CREATE UNIQUE INDEX stimulation_group_tenant_name_unique ON stimulation_group (tenant_id, name) WHERE deleted_at IS NULL`.

**`stimulation_group_member`** — 'Enrollment of a child in a stimulation group'
`stimulation_group_id` uuid idx NOT NULL · `child_id` uuid idx NOT NULL (same tenant, validated) · `enrolled_at` date NOT NULL 'Enrollment date; defaults server-side to today when omitted' · `default_attendance_payment_status_id` uuid idx NOT NULL 'FK to central attendance_payment_status; prefills the attendance sheet'.
`CREATE UNIQUE INDEX stimulation_group_member_unique ON stimulation_group_member (stimulation_group_id, child_id) WHERE deleted_at IS NULL`.

**`stimulation_attendance`** — 'Attendance fact of a child in a group on a session date; upserted by natural key'
`stimulation_group_id` uuid idx NOT NULL · `child_id` uuid idx NOT NULL · `session_date` date NOT NULL 'Date of the group session' · `attended` boolean NOT NULL default false 'Whether the child attended' · `homework_done` boolean NOT NULL default false 'Whether the take-home task was done' · `attendance_payment_status_id` uuid idx NOT NULL 'FK to central attendance_payment_status; defaults from the member row'.
`$table->unique(['stimulation_group_id', 'child_id', 'session_date'])` — PLAIN unique, D8 · `$table->index(['tenant_id', 'session_date'])`.

### Central catalogs (fixed UUIDs, `CatalogSeeder::upsertCatalog`, `BaseModel::columns($table, false)`)

| Table | Rows (`key` → `name`) | Constants |
|---|---|---|
| `session_section` | integration → Integración, preparation → Preparación, core → Actividad central, relaxation → Relajación (+ `sort_order` 1–4) | `SessionSection::INTEGRATION = 'ed400001-0000-4000-8000-000000000001'` … `RELAXATION = '…0004'` |
| `stimulation_area` | sensory → Sensorial, cognitive → Cognitiva, socio_affective → Socioafectiva, language → Lenguaje, motor → Motora | `StimulationArea::SENSORY = 'ed400002-0000-4000-8000-000000000001'` … `MOTOR = '…0005'` |
| `attendance_payment_status` | scholarship → Beca, paid → Pagado, unpaid → No pagado | `AttendancePaymentStatus::SCHOLARSHIP = 'ed400003-0000-4000-8000-000000000001'` … `UNPAID = '…0003'` |

### Tenant catalogs (`BaseModel::columns($table, true)` + `BelongsToTenant`, partial unique `(tenant_id, name)`, added to `TenantCatalogSeeder::CATALOGS`)

Columns each: `name` NOT NULL, `description` nullable, `is_active` boolean default true. No `key` column (nothing branches on rows). Defaults (Spanish — UI data):
- `stimulation_dynamic`: Mamá/bebé, Bebé/bebé, Grupal, Circuito
- `body_zone`: Cuello, Hombro/brazo, Mano, Tronco, Pierna, Pie, Cara, Pecho y abdomen, Espalda
- `massage_movement`: Flexión, Extensión, Circunducción, Media circunducción, Arriba-abajo, Abducción-aducción, Amasamiento de dedos
- `didactic_material`: (no defaults — admin fills; suggestions-only catalog, D3)
- `song`: (no defaults — admin fills)
- `stimulation_group_type`: Grupo abierto (single default so REQ-SG-001's required FK is satisfiable; Q4)

## 4. Backend surface

```
Modules/Stimulation/app/
├── Models/            StimulationSession, StimulationSessionMovement, StimulationSessionRelaxationZone,
│                      StimulationGroup, StimulationGroupMember, StimulationAttendance,
│                      SessionSection, StimulationArea, AttendancePaymentStatus,
│                      StimulationDynamic, BodyZone, MassageMovement, DidacticMaterial, Song, StimulationGroupType
├── Http/Controllers/  StimulationSessionController, StimulationGroupController,
│                      StimulationGroupMemberController, StimulationAttendanceController, + 9 catalog controllers
├── Http/Requests/     StimulationSession{Create,Update}Request, StimulationGroup{Create,Update}Request,
│                      StimulationGroupMember{Create,Update}Request, AttendanceUpsertRequest, + catalog requests
└── Services/
    ├── StimulationSessionService.php     # subject rule (D5), nested movement/relaxation sync (D7)
    └── StimulationAttendanceService.php  # bulk idempotent upsert (D8/D9), member defaults
```

Routes (`Modules/Stimulation/routes/api.php`, under `['auth:sanctum', 'initializeTenancy']` + `throttle:api`):

| Route | Action | Guard |
|---|---|---|
| `Orion::resource('stimulation-session', ...)->withSoftDeletes()` | session CRUD (payload carries `movements[]`, `relaxation_zones[]`) | `$keyForPermission = 'stimulation_session'` |
| `Orion::resource('stimulation-group', ...)->withSoftDeletes()` | group CRUD | `$keyForPermission = 'stimulation_group'` |
| `Orion::resource('stimulation-group-member', ...)->withSoftDeletes()` | enroll / update default status / unenroll | `$keyForPermission = 'stimulation_group'` (D10) |
| `POST stimulation-group/{id}/attendance` | bulk idempotent upsert | `update.stimulation_group` |
| `Orion::resource('stimulation-attendance', ...)->only(['index', 'search', 'show'])` | grid reads | `$keyForPermission = 'stimulation_group'` (D10) |
| 9 catalog `Orion::resource`s | catalog CRUD (central writes blocked by permissions) | own table name each |

## 5. Attendance upsert pipeline

```
1. Resolve group (tenant-scoped → cross-tenant is 404); guard is_active (422)
2. Validate batch: session_date not future and >= group.start_date; entries child_ids distinct;
   every child an ACTIVE member of the group; payment status (when given) exists in central catalog
3. One transaction, per entry: withTrashed() lookup by (group, child, date) → restore if trashed →
   fill attended / homework_done / payment status (entry value, else member default) → save
4. Concurrent-insert 23505 on the plain unique → retry that entry once (now an update) (D9)
5. Return upserted stimulation_attendance entities (Orion-style collection) — never a custom wrapper
```

## 6. Module registration (exact wiring)

**`modules.csv`** (`id,name,group`) — 11 rows, prefix `ed000004`:

| id | name | group |
|---|---|---|
| `ed000004-0000-4000-8000-000000000001` | Sesiones de Estimulación | Evaluación |
| `ed000004-0000-4000-8000-000000000002` | Grupos de Estimulación | Evaluación |
| `ed000004-0000-4000-8000-000000000003` | Dinámicas de Estimulación | Evaluación |
| `ed000004-0000-4000-8000-000000000004` | Zonas Corporales | Evaluación |
| `ed000004-0000-4000-8000-000000000005` | Movimientos de Masaje | Evaluación |
| `ed000004-0000-4000-8000-000000000006` | Materiales Didácticos | Evaluación |
| `ed000004-0000-4000-8000-000000000007` | Canciones | Evaluación |
| `ed000004-0000-4000-8000-000000000008` | Tipos de Grupo | Evaluación |
| `ed000004-0000-4000-8000-000000000009` | Secciones de Sesión | Evaluación |
| `ed000004-0000-4000-8000-000000000010` | Áreas de Estimulación | Evaluación |
| `ed000004-0000-4000-8000-000000000011` | Estatus de Pago de Asistencia | Evaluación |

**`permissions.csv`**: 6 rows per module row (66 total), existing description style.

**`module_system.csv`**: all 11 → system `b266838b-4cb0-4eb1-aa76-242cf96df41a` (Evaluación). Sorts/groups: Sesiones de Estimulación (30, Estimulación, IconHeartHandshake), Grupos de Estimulación (31, Estimulación), Dinámicas (45, Catálogos), Zonas Corporales (46, Catálogos), Movimientos de Masaje (47, Catálogos), Materiales Didácticos (48, Catálogos), Canciones (49, Catálogos), Tipos de Grupo (50, Catálogos); hidden rows (Secciones de Sesión 51, Áreas de Estimulación 52, Estatus de Pago de Asistencia 53) with empty group labels.

**`SystemTableSeeder`**:
- `seedModulePermissionEntities()`: `'Sesiones de Estimulación' => 'stimulation_session'`, `'Grupos de Estimulación' => 'stimulation_group'`, `'Dinámicas de Estimulación' => 'update:stimulation_dynamic'`, `'Zonas Corporales' => 'update:body_zone'`, `'Movimientos de Masaje' => 'update:massage_movement'`, `'Materiales Didácticos' => 'update:didactic_material'`, `'Canciones' => 'update:song'`, `'Tipos de Grupo' => 'update:stimulation_group_type'`, `'Secciones de Sesión' => 'session_section'`, `'Áreas de Estimulación' => 'stimulation_area'`, `'Estatus de Pago de Asistencia' => 'attendance_payment_status'`.
- `seedModuleRoutes()`: `/stimulation-session`, `/stimulation-group`, `/stimulation-dynamic`, `/body-zone`, `/massage-movement`, `/didactic-material`, `/song`, `/stimulation-group-type` (hidden rows need no route).
- `seedModuleVisibility()` `$hidden` += `'Secciones de Sesión'`, `'Áreas de Estimulación'`, `'Estatus de Pago de Asistencia'`.
- `seedModuleIcons()` (all 11; verify each exists in `frontend/src/shared/utils/iconMap.ts`, add mappings if missing): IconHeartHandshake, IconUsersGroup, IconCategory, IconBodyScan, IconMassage, IconPackage, IconMusic, IconCategory, IconCategory, IconCategory, IconCategory.

**`RolePermissionTableSeeder`**: append the 66 permission names to `$commonPermissions`; append `'session_section'`, `'stimulation_area'`, `'attendance_payment_status'` to `$centralCatalogs`.

**`AngularController::$mapperSelects`** (keys = `Str::studly` of `CatalogType`): `'StimulationDynamic'`, `'BodyZone'`, `'MassageMovement'`, `'DidacticMaterial'`, `'Song'`, `'StimulationGroupType'`, `'StimulationArea'`, `'AttendancePaymentStatus'`, `'StimulationGroup'` → their model classes. NO `Child` entry (D12).

**`useCatalogs.ts::CatalogType`**: `STIMULATION_DYNAMIC = 'stimulation_dynamic'`, `BODY_ZONE = 'body_zone'`, `MASSAGE_MOVEMENT = 'massage_movement'`, `DIDACTIC_MATERIAL = 'didactic_material'`, `SONG = 'song'`, `STIMULATION_GROUP_TYPE = 'stimulation_group_type'`, `STIMULATION_AREA = 'stimulation_area'`, `ATTENDANCE_PAYMENT_STATUS = 'attendance_payment_status'`, `STIMULATION_GROUP = 'stimulation_group'`.

**`modules_statuses.json`**: add `"Stimulation": true`.

## 7. Frontend structure

- `frontend/src/modules/stimulationSession/`: `schema.ts` (entity interfaces + Zod form schema incl. `movements[]`/`relaxation_zones[]`), `services/StimulationSessionResource.ts` (`createCrudApi`), `store/stimulationSessionStore.ts` (`createEntityStore`), `StimulationSessionListPage.tsx`, `StimulationSessionFormPage.tsx` — the form renders the four sections as collapsible groups: subject selector (Individual → child async-search combobox via the child list endpoint, D12 + optional visit; Grupal → group select via `STIMULATION_GROUP`), integration, preparation (massage grid body_zone × movement + song), core (area/dynamic/activity/material/song), relaxation (zones multi-select + notes). Material/song = free-text comboboxes suggesting from `DIDACTIC_MATERIAL`/`SONG`.
- `frontend/src/modules/stimulationGroup/`: same layers + `StimulationGroupDetailPage.tsx` with tabs Datos generales / Miembros (enroll modal: child async search + default payment status select; unenroll; edit default) and a link to the attendance sheet.
- `frontend/src/modules/stimulationGroup/AttendanceSheetPage.tsx` (route `/stimulation-group/:id/attendance`): date picker (defaults today) + one row per ACTIVE member with three controls (asistió checkbox, tarea checkbox, estatus de pago select). Rows without a persisted record render defaults (attended=false, tarea=false, member default status). Changes update local state immediately (optimistic), are batched into the bulk POST, and on failure revert + `toast.error`. Catalogs load lazily; skeletons via `boneyard-js`.
- Catalog pages: 6 `createCatalogPage` registrations in `frontend/src/modules/catalogs/pages.tsx`.
- `App.tsx`: `PermissionRoute` blocks — `stimulation_session` → `/stimulation-session`; `stimulation_group` → `/stimulation-group`, `/stimulation-group/:id`, `/stimulation-group/:id/attendance`; the 6 catalog routes.

## 8. Open decisions

| Q | Question | Default encoded in this design |
|---|---|---|
| Q1 | Exactly-one subject at API level (D5) — or is "individual session inside a group" a real case needing both FKs? | Exactly one; DB CHECK stays at-least-one so relaxing later is migration-free |
| Q2 | Material/song as free-text snapshots with suggestion catalogs (D3) — or hard FKs? | Free text; catalogs are suggestion sources |
| Q3 | `session_section` has no FK consumer (D4) — keep as official vocabulary or cut? | Keep (mandated by brief) |
| Q4 | `stimulation_group_type` default seed row | One row: "Grupo abierto" |
| Q5 | `attended`/`homework_done` NOT NULL default false — or nullable tri-state ("not recorded")? | NOT NULL default false |
| Q6 | Attendance date window: `>= group.start_date`, no `end_date` upper bound (make-up sessions) | As stated; not in the future always |
| Q7 | "Sesiones" tab on Child detail (cross-module UI) | Not in this change; sessions are reached from their own list page |

## 9. Explicitly unchanged

`Modules/Screening/**` (engine, tables, catalogs), `Modules/Instrument/**`, all Clinic/ChildRecord tables, existing `TenantCatalogSeeder` entries, quota binding (`NullQuotaSubjectCounter`), `REPORT_MODEL_MAP`, notification resolvers, every existing frontend module. No `alter` migration touches an existing table.
