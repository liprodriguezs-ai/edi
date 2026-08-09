# Design: EDI Clinic & Child Registry

Change: `edi-clinic-and-child-registry`

## 1. Context

First domain slice on the pruned template. Two nwidart modules — `Clinic` (no dependencies) and `ChildRecord` (depends on Clinic) — cloned from the surviving `UserProfile` module patterns, which are the living reference for: `BaseModel::columns($table, true)`, no cross-module DB FKs (documented in `create_user_profile_table.php`), Orion controller shape (`$model`, `$keyForPermission`, `includes()`, `searchableBy()`, `buildFetchQuery`), and route registration (`Orion::resource(...)->withSoftDeletes()->middleware(['auth:sanctum', 'initializeTenancy'])`).

## 2. Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | Two modules, not one: `Clinic` + `ChildRecord` | `ChildRecord` holds minors' PII with distinct privacy/retention posture; Clinic is dependency-free structure (per `.project-context/modules.md`) |
| D2 | No DB-level foreign keys; UUID columns + index + tenant-scoped `exists` validation | Template precedent (`user_profile.user_id`). Consistency beats mixed styles; app-level rules give friendly 422s. Cross-tenant integrity is validated in FormRequests because `Rule::exists` bypasses `TenantScope` |
| D3 | All soft-delete-sensitive uniques are Postgres **partial unique indexes** via `DB::statement(... WHERE ... AND deleted_at IS NULL)` | Established precedent (`user_phone_unique`, `plan_quota_*`, `tenant_quota_usage_*`). Laravel's fluent builder cannot express them |
| D4 | `sex` and `population_type` are CENTRAL (fixed UUID constants, `CatalogSeeder::upsertCatalog`) | Code/export branches on them (CURP sex char check now; Secretaría export + head-circumference percentile lookup by sex later). Central = read-only for tenant roles |
| D5 | `caregiver_relationship`, `education_level`, `occupation` are TENANT-SCOPED (`TenantCatalogSeeder::CATALOGS`) | Pure admin-editable labels, no branching. Matches the seeder's own docblock listing them as the EDI catalogs to add |
| D6 | `visit.practitioner_id` references `practitioner_profile.id` (column name kept from the target model; relationship method `practitioner()`) | The professional identity (license, center) is the clinically meaningful actor, not the raw login user |
| D7 | All `birth_record` clinical fields nullable; NULL = "se ignora" | EDI paper form allows unknown. Risk factors must derive only from KNOWN values — a defaulted 0/false would silently produce false negatives in `<34w / ≤1500g / <16y`. Nullable booleans render as Sí/No/Se ignora |
| D8 | Clinical measures are integers in minor units: grams, millimeters, whole weeks/days/months/years | AGENTS.md hard rule 7; avoids the legacy lossy rounding; the three derivations are pure integer comparisons |
| D9 | `receipt_number` server-generated `V-YYYY-NNNNNN` from `visit_receipt_sequence` (`lockForUpdate` inside the create transaction), immutable after create | Race-safe, per-tenant, per-year; mirrors the template-era `R-YYYY-NNNNNN` folio convention (folio → `receipt_number` naming rule). Open question Q3 can flip this to manual capture |
| D10 | One-profile-per-user: partial unique (`tenant_id`,`user_id`) on `practitioner_profile` | Added beyond the target model — prevents ambiguous `visit.practitioner_id` resolution. Flagged in proposal question round |
| D11 | Caregivers have permissions but NO sidebar page (hidden module row); managed from Child detail. `birth_record` + `child_caregiver` authorize under `$keyForPermission = 'child'` | Caregivers only make sense in child context; fewer pages, same auditability. Sub-resources of the child record share its permission like a single clinical file |
| D12 | Child search for visit creation goes through the child list endpoint (from Child detail), NOT `POST /selects` | `/selects` returns full unpaginated rows — dumping every minor's name into a generic catalog endpoint is a PII and volume smell |

## 3. Data model (definitive)

All tables: `BaseModel::columns($table, true)` first line inside the closure **after** `$table->comment('...')`, except `sex`/`population_type` which use `BaseModel::columns($table, false)`. Every own column carries `->comment('...')`. FK-style columns are `uuid(...)->index()`.

### 3.1 `Clinic` module

**`health_center`** — 'Health center (unidad médica) belonging to the tenant organization'

| Column | Type | Constraints | Comment |
|---|---|---|---|
| `name` | string | NOT NULL | Official name of the health center |
| `clues_code` | string(11) | nullable | CLUES code (Clave Única de Establecimientos de Salud); uppercase, 5 letters + 6 digits |
| `municipality_id` | uuid, index | NOT NULL | References municipality.id (central catalog; no DB FK per D2) |
| `address` | string | NOT NULL | Street address of the health center |
| `is_active` | boolean | NOT NULL default true | Whether the center currently operates and can receive visits |

Indexes: `CREATE UNIQUE INDEX health_center_tenant_clues_unique ON health_center (tenant_id, clues_code) WHERE clues_code IS NOT NULL AND deleted_at IS NULL`.

**`practitioner_profile`** — 'Professional identity of a user who applies screenings in this tenant'

| Column | Type | Constraints | Comment |
|---|---|---|---|
| `user_id` | uuid, index | NOT NULL | References user.id (no DB FK per D2); must be a member of the tenant |
| `license_number` | string(20) | NOT NULL | Professional license (cédula profesional) |
| `specialty` | string | NOT NULL | Specialty or discipline (e.g. pediatría, enfermería, psicología) |
| `health_center_id` | uuid, index | NOT NULL | FK to health_center (primary assignment; no DB FK per D2) |

Indexes: `CREATE UNIQUE INDEX practitioner_profile_tenant_user_unique ON practitioner_profile (tenant_id, user_id) WHERE deleted_at IS NULL` (D10).

### 3.2 `ChildRecord` module — catalogs

**`sex`** (CENTRAL) — 'Central sex catalog for children (fixed UUIDs; screening rules and official exports branch on it)'
Columns: `key` string unique ('male'/'female'), `name` string ('Masculino'/'Femenino'), `description` nullable.
Model `Modules\ChildRecord\Models\Sex` constants:
`Sex::MALE = 'ed100001-0000-4000-8000-000000000001'`, `Sex::FEMALE = 'ed100001-0000-4000-8000-000000000002'`.

**`population_type`** (CENTRAL) — 'Central population-type catalog (Secretaría de Salud export branches on it)'
Columns: `key` ('urban'/'rural'/'indigenous'), `name` ('Urbana'/'Rural'/'Indígena'), `description` nullable.
Constants: `PopulationType::URBAN = 'ed100002-0000-4000-8000-000000000001'`, `RURAL = '...0002'`, `INDIGENOUS = '...0003'`.

**`caregiver_relationship`**, **`education_level`**, **`occupation`** (TENANT-SCOPED) — admin-editable label catalogs.
Columns each: `name` string NOT NULL, `description` nullable, `is_active` boolean default true. No `key` column (nothing branches on rows). Unique: `CREATE UNIQUE INDEX {table}_tenant_name_unique ON {table} (tenant_id, name) WHERE deleted_at IS NULL`.
Seed defaults (values in Spanish — they are UI data):
- `caregiver_relationship`: Madre, Padre, Abuela, Abuelo, Tutor legal, Otro
- `education_level`: Ninguna, Primaria incompleta, Primaria, Secundaria, Bachillerato, Licenciatura, Posgrado
- `occupation`: Hogar, Empleada/o, Comerciante, Campo, Estudiante, Desempleada/o, Otra

### 3.3 `ChildRecord` module — core tables

**`child`** — 'Child (menor evaluado) registered in the tenant organization; sensitive PII of a minor'

| Column | Type | Constraints | Comment |
|---|---|---|---|
| `first_name` | string | NOT NULL | Given name(s) of the child |
| `last_name_paternal` | string | NOT NULL | Paternal surname |
| `last_name_maternal` | string | NOT NULL | Maternal surname (see open question Q1) |
| `birth_date` | date | NOT NULL | Birth date; drives age-in-months/days for screening |
| `sex_id` | uuid, index | NOT NULL | FK to central sex catalog |
| `curp` | string(18) | nullable | CURP, stored uppercase; validated against official pattern and cross-checked with birth_date/sex |
| `external_id` | string | nullable | Record id (expediente) in the health center's own system |
| `health_center_id` | uuid, index | NOT NULL | FK to health_center where the child is attended |
| `is_schooled` | boolean | NOT NULL default false | Whether the child attends daycare/school (EDI variable) |
| `notes` | text | nullable | Internal clinical/administrative notes |

Indexes:
- `CREATE UNIQUE INDEX child_tenant_curp_unique ON child (tenant_id, curp) WHERE curp IS NOT NULL AND deleted_at IS NULL`
- `CREATE UNIQUE INDEX child_tenant_external_unique ON child (tenant_id, external_id) WHERE external_id IS NOT NULL AND deleted_at IS NULL`
- `$table->index(['tenant_id', 'birth_date'])` (age-cohort queries)
- `$table->index(['tenant_id', 'last_name_paternal'])` (name search)

**`caregiver`** — 'Caregiver/tutor of one or more children; adult PII'

| Column | Type | Constraints | Comment |
|---|---|---|---|
| `first_name` / `last_name_paternal` / `last_name_maternal` | string | NOT NULL | Names (Q1 applies) |
| `birth_date` | date | nullable | Caregiver birth date |
| `education_level_id` | uuid, index | NOT NULL | FK to tenant catalog education_level |
| `occupation_id` | uuid, index | NOT NULL | FK to tenant catalog occupation |
| `population_type_id` | uuid, index | NOT NULL | FK to central population_type (official export dimension) |
| `phone` | string(30) | NOT NULL | Contact phone |
| `email` | string | nullable | Contact email |
| `has_social_program` | boolean | NOT NULL default false | Whether the family receives a social program (EDI variable) |

**`child_caregiver`** — 'Pivot child↔caregiver with kinship and primary-contact flag'

| Column | Type | Constraints | Comment |
|---|---|---|---|
| `child_id` | uuid, index | NOT NULL | FK to child |
| `caregiver_id` | uuid, index | NOT NULL | FK to caregiver (same tenant, validated) |
| `caregiver_relationship_id` | uuid, index | NOT NULL | FK to tenant catalog caregiver_relationship (kinship) |
| `is_primary` | boolean | NOT NULL default false | Primary caregiver/contact for the child (at most one active per child) |

Indexes:
- `CREATE UNIQUE INDEX child_caregiver_pair_unique ON child_caregiver (child_id, caregiver_id) WHERE deleted_at IS NULL`
- `CREATE UNIQUE INDEX child_caregiver_primary_unique ON child_caregiver (child_id) WHERE is_primary AND deleted_at IS NULL`

Primary-caregiver transitions run in `ChildCaregiverService` inside one DB transaction (demote-then-promote ordering so the partial unique never trips on legal transitions; concurrent violators get the DB 23505 → mapped to 409/422).

**`birth_record`** — 'Perinatal record of a child (1:1); feeds corrected age and auto-derived biological risk factors; NULL = unknown (se ignora)'

| Column | Type | Constraints | Comment |
|---|---|---|---|
| `child_id` | uuid, index | NOT NULL | FK to child; one active record per child |
| `gestational_age_weeks` | smallInteger | nullable, 20–44 | Gestational age in whole weeks; corrected age uses it; <34 auto-derives a risk factor |
| `birth_weight_grams` | integer | nullable, 400–7000 | Birth weight in grams (integer minor units); ≤1500 auto-derives a risk factor |
| `birth_length_mm` | integer | nullable, 200–650 | Birth length in millimeters (integer minor units) |
| `prenatal_visit_count` | smallInteger | nullable, 0–40 | Number of prenatal visits |
| `had_pregnancy_complication` | boolean | nullable | Pregnancy complication; NULL = unknown |
| `had_delivery_complication` | boolean | nullable | Delivery complication; NULL = unknown |
| `nicu_days` | smallInteger | nullable, 0–365 | Days in NICU (UCIN) |
| `mother_age_at_birth_years` | smallInteger | nullable, 10–60 | Mother's age at delivery in whole years; <16 auto-derives a risk factor |
| `early_breastfeeding` | boolean | nullable | Breastfeeding initiated early; NULL = unknown |
| `breastfeeding_months` | smallInteger | nullable, 0–71 | Total months breastfed; NULL = unknown/ongoing-not-captured |

Index: `CREATE UNIQUE INDEX birth_record_child_unique ON birth_record (child_id) WHERE deleted_at IS NULL`.

**`visit`** — 'Visit header (consulta) at a health center; the future screening hangs from it'

| Column | Type | Constraints | Comment |
|---|---|---|---|
| `child_id` | uuid, index | NOT NULL | FK to child |
| `health_center_id` | uuid, index | NOT NULL | FK to health_center where the visit happened |
| `receipt_number` | string(20) | NOT NULL | Server-generated folio V-YYYY-NNNNNN; unique per tenant; immutable |
| `visit_date` | date | NOT NULL | Date of the visit (not in the future) |
| `practitioner_id` | uuid, index | NOT NULL | FK to practitioner_profile who attended (D6) |

Indexes:
- `CREATE UNIQUE INDEX visit_tenant_receipt_unique ON visit (tenant_id, receipt_number) WHERE deleted_at IS NULL`
- `CREATE INDEX visit_tenant_child_date_idx ON visit (tenant_id, child_id, visit_date DESC)`

**`visit_receipt_sequence`** — 'Per-tenant, per-year counter backing visit receipt_number generation' (D9)
Columns: `year` smallInteger NOT NULL, `last_number` integer NOT NULL default 0. Unique `(tenant_id, year)` (plain — rows are never soft-deleted). Consumed only by `VisitService` under `lockForUpdate`.

## 4. Backend surface

Per entity: Model (+`BelongsToTenant`, casts, relations), `{Entity}CreateRequest`/`{Entity}UpdateRequest`, `{Entity}Controller extends QCH\Core\Http\Controllers\Controller` with `$model`, `$keyForPermission`, `includes()`, `searchableBy()` (child: names + curp — curp searchable, gate later if needed; caregiver: names + phone), and `Orion::resource('{table}', ...)` in the module's `routes/api.php` under `throttle:api` + `['auth:sanctum', 'initializeTenancy']`.

Services (business logic out of controllers): `ChildCaregiverService` (primary invariant), `VisitService` (receipt generation), `CurpValidator` rule class (`Modules/ChildRecord/app/Rules/Curp.php`, reusable — also cross-checks birth_date/sex from request context).

`$keyForPermission`: `health_center`, `practitioner_profile`, `child`, `caregiver`, `visit`; catalogs use their own table name; `ChildCaregiverController` and `BirthRecordController` use `child` (D11).

## 5. Module registration (exact wiring)

New module UUIDs use the handcrafted-stable pattern already in the repo (cf. `b4001001-...` for Planes y Precios).

**`modules.csv`** (`id,name,group`):

| id | name | group |
|---|---|---|
| `ed000001-0000-4000-8000-000000000001` | Centros de Salud | Evaluación |
| `ed000001-0000-4000-8000-000000000002` | Profesionales de Salud | Evaluación |
| `ed000001-0000-4000-8000-000000000003` | Niños | Evaluación |
| `ed000001-0000-4000-8000-000000000004` | Cuidadores | Evaluación |
| `ed000001-0000-4000-8000-000000000005` | Visitas | Evaluación |
| `ed000001-0000-4000-8000-000000000006` | Sexos | Evaluación |
| `ed000001-0000-4000-8000-000000000007` | Tipos de Población | Evaluación |
| `ed000001-0000-4000-8000-000000000008` | Parentescos | Evaluación |
| `ed000001-0000-4000-8000-000000000009` | Escolaridades | Evaluación |
| `ed000001-0000-4000-8000-000000000010` | Ocupaciones | Evaluación |

**`permissions.csv`**: 6 rows per module row above (60 total), format `,{Descripción} {Entity},{verb}.{entity},api,{module_id}` following the existing description style (Lectura/Creación/Edicion/Inactivar/Activar/Reporte).

**`module_system.csv`**: all 10 rows → `system_id = b266838b-4cb0-4eb1-aa76-242cf96df41a` (Evaluación). Visible sort/groups: Centros de Salud (10, Estructura, IconBuilding), Profesionales de Salud (11, Estructura), Niños (20, Expediente, IconUsers), Visitas (21, Expediente), Parentescos (40, Catálogos, IconCategory), Escolaridades (41, Catálogos), Ocupaciones (42, Catálogos); hidden rows (Cuidadores 22, Sexos 43, Tipos de Población 44) keep empty group labels.

**`SystemTableSeeder`**:
- `seedModulePermissionEntities()`: `'Centros de Salud' => 'health_center'`, `'Profesionales de Salud' => 'practitioner_profile'`, `'Niños' => 'child'`, `'Cuidadores' => 'caregiver'`, `'Visitas' => 'visit'`, `'Sexos' => 'sex'`, `'Tipos de Población' => 'population_type'`, `'Parentescos' => 'update:caregiver_relationship'`, `'Escolaridades' => 'update:education_level'`, `'Ocupaciones' => 'update:occupation'` (the `update:` prefix is the established gate for admin-managed catalogs).
- `seedModuleRoutes()`: `/health-center`, `/practitioner`, `/child`, `/visit`, `/caregiver-relationship`, `/education-level`, `/occupation` (hidden modules need no route).
- `seedModuleVisibility()` `$hidden`: `'Cuidadores'`, `'Sexos'`, `'Tipos de Población'`.
- `seedModuleIcons()` (all 10; verify each exists in `frontend/src/shared/utils/iconMap.ts`): IconBuilding, IconUserCheck, IconUsers, IconUsers, IconCalendarEvent, IconCategory, IconCategory, IconUsers, IconCategory, IconBriefcase.

**`RolePermissionTableSeeder`**: append the 60 permission names to `$commonPermissions` (so `admin.super` gets them; tenant roles flow via `whereNotIn`); append `'sex'`, `'population_type'` to `$centralCatalogs` (their create/update/delete/restore become super-admin exclusives).

**`AngularController::$mapperSelects`** (keys travel as `Str::studly` of `CatalogType`): `'Sex'`, `'PopulationType'`, `'CaregiverRelationship'`, `'EducationLevel'`, `'Occupation'`, `'HealthCenter'`, `'PractitionerProfile'` → their model classes. No `Child` entry (D12).

**`useCatalogs.ts::CatalogType`**: `SEX = 'sex'`, `POPULATION_TYPE = 'population_type'`, `CAREGIVER_RELATIONSHIP = 'caregiver_relationship'`, `EDUCATION_LEVEL = 'education_level'`, `OCCUPATION = 'occupation'`, `HEALTH_CENTER = 'health_center'`, `PRACTITIONER_PROFILE = 'practitioner_profile'`.
Practitioner dropdown requests `_with: 'user'` and renders `profile.user.name` (profiles have no own `name`).

**`modules_statuses.json`**: add `"Clinic": true`, `"ChildRecord": true`.

**Seeder ordering note**: `Sex`/`PopulationType` models live in `ChildRecord`, imported by the central `CatalogSeeder`; tenant catalog models are listed in `TenantCatalogSeeder::CATALOGS`. Both seeders are idempotent and already run from `DatabaseSeeder` and `TenantCreated → tenants:seed`.

## 6. Frontend structure

- `frontend/src/modules/healthCenter/` and `practitioner/`: `schema.ts`, `services/{X}Resource.ts` (`createCrudApi`), `store/{x}Store.ts` (`createEntityStore`), `{X}ListPage.tsx` + form modal (mirror `userProfile` module shape).
- `frontend/src/modules/child/`: same layers + `ChildDetailPage.tsx` with tabs: Datos generales, Cuidadores (link/unlink/primary via `caregiver` components), Antecedentes perinatales (birth-record form with Sí/No/Se ignora tri-states), Visitas (history + "Nueva visita" modal).
- `frontend/src/modules/caregiver/`: schema/resource/store + components consumed by the child detail; no standalone page (D11).
- `frontend/src/modules/visit/`: schema/resource/store + `VisitListPage.tsx` (global worklist) + create modal (child pre-bound from detail).
- Catalog pages: 3 `createCatalogPage` registrations in `frontend/src/modules/catalogs/pages.tsx`.
- `App.tsx`: `PermissionRoute` blocks for `health_center`, `practitioner_profile`, `child`, `visit`, and the three catalog entities.

## 7. Open decisions

| Q | Question | Default encoded in this design |
|---|---|---|
| Q1 | `last_name_maternal` nullable (single-surname reality)? | NOT NULL per target model |
| Q2 | Birth-record NULL="se ignora" semantics (D7) acceptable? | Yes, nullable |
| Q3 | Receipt auto-generation vs manual paper-folio capture (D9)? | Auto `V-YYYY-NNNNNN` |
| Q4 | CURP↔birth_date/sex cross-check severity? | Hard 422 |
| Q5 | CURP encryption at rest / masking in lists (LFPDPPP hardening)? | Deferred — plaintext column, permission-gated, flagged for a dedicated privacy change |

## 8. Explicitly unchanged

`UnitLimitService`/quota binding, `REPORT_MODEL_MAP`, notification resolvers, and every existing module. No `alter` migration touches an existing table.

---

## 8. Resolved product decisions

These close the open round in `proposal.md`. Two override the executor's assumption.

### D-13 — `last_name_maternal` is NULLABLE (overrides assumption 1)

Requiring it forces staff to invent data for foreign nationals, children of unknown
parentage, and single-surname registrations. A NOT NULL column does not produce
better records, it produces `.` and `X` in the second surname. Nullable on both
`child` and `caregiver`; the display name concatenates what exists.

### D-14 — Perinatal data is nullable and NULL means "se ignora" (confirms assumption 2)

Matches the paper form. Consequence for the scoring engine that comes later: a risk
factor is auto-derived **only from a known value**. With NULL the factor is neither
auto-answered nor silently assumed absent — it stays answerable by hand and the
screening records a finding noting the missing input. Never treat unknown as "no".

### D-15 — `receipt_number` is auto-generated, plus an optional paper folio (refines assumption 3)

`visit.receipt_number` is a per-tenant immutable sequence `V-YYYY-NNNNNN` assigned on
create. A separate nullable `physical_receipt_number` stores the folio written on the
paper form, which is what staff actually cross-reference. The legacy system conflated
the two and could not reconcile against paper.

### D-16 — CURP cross-consistency is a WARNING, not a hard error (overrides assumption 4)

Format and uniqueness stay hard 422. But a mismatch between the CURP's embedded
birth-date or sex segment and the captured `birth_date`/`sex_id` must NOT block the
registration.

Rationale: this is a screening system for children, and the cost of the two failure
modes is asymmetric. Refusing the record teaches staff to leave CURP empty — which
loses the identifier entirely and breaks the longitudinal follow-up the whole product
depends on. Accepting it with a visible flag keeps the data and surfaces the conflict
for correction. Real CURPs issued with typos exist and the clinic cannot reissue them.

The response carries the discrepancy so the UI can show it; the record is stored with
the mismatch flagged for review.

### D-17 — Caregivers are managed from the child detail (confirms assumption 5)

`health_center` and `practitioner_profile` get sidebar pages. `caregiver` and
`child_caregiver` are edited inside the child's detail view; their module stays hidden
from the menu while keeping its permissions, so the API remains guarded.
