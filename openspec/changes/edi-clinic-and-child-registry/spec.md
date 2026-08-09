# Spec: EDI Clinic & Child Registry

Change: `edi-clinic-and-child-registry`
Capabilities: `clinic-registry` (new), `child-registry` (new)

> RFC 2119 keywords (MUST, MUST NOT, SHALL, SHOULD, MAY) are normative.
> All entities in this spec are tenant-scoped unless stated CENTRAL.
> "Authorized user" = a user whose role holds the relevant `{verb}.{entity}` permission.
> On archive, REQ-CL-* merge into `openspec/specs/clinic-registry/spec.md` and the rest into `openspec/specs/child-registry/spec.md`.

---

## ADDED Requirements — capability `clinic-registry`

### REQ-CL-001 — Health center CRUD

The system SHALL expose Orion CRUD for `health_center` with soft deletes. `name`, `municipality_id`, and `address` are required; `clues_code` is optional; `is_active` defaults to true.

#### Scenario: create a health center
- GIVEN an authenticated user with `create.health_center`
- WHEN they POST `/api/health_center` with `{name: "Centro de Salud Norte", municipality_id: <valid>, address: "..." }`
- THEN the row is created with the caller's `tenant_id` stamped and `is_active = true`
- AND the response is the entity (Orion envelope), never a custom wrapper.

#### Scenario: municipality must exist
- WHEN a user creates a health center with a `municipality_id` not present in the central `municipality` table
- THEN the request fails with 422 and no row is created.

### REQ-CL-002 — CLUES code format and per-tenant uniqueness

`clues_code`, when provided, MUST match the CLUES shape `^[A-Z]{5}[0-9]{6}$` (normalized to uppercase before validation) and MUST be unique per tenant among non-deleted rows.

#### Scenario: duplicate CLUES within tenant is rejected
- GIVEN tenant T1 has an active health center with `clues_code = "DFSSA003962"`
- WHEN a T1 user creates another health center with the same `clues_code`
- THEN the request fails with 422 (validation) and the DB partial unique index would reject it regardless.

#### Scenario: same CLUES in another tenant is allowed
- GIVEN tenant T2 has no health center with `clues_code = "DFSSA003962"`
- WHEN a T2 user creates one with that code
- THEN it succeeds (uniqueness is scoped by `tenant_id`).

#### Scenario: soft-deleted CLUES is reusable
- GIVEN a T1 health center with `clues_code = "DFSSA003962"` was soft-deleted
- WHEN a T1 user creates a new health center with the same code
- THEN it succeeds (unique index is partial: `WHERE deleted_at IS NULL`).

### REQ-CL-003 — Practitioner profile

The system SHALL expose Orion CRUD for `practitioner_profile`. A profile links a `user` of the same tenant to a `health_center` and records `license_number` (cédula profesional) and `specialty`. A user MUST have at most one non-deleted practitioner profile per tenant.

#### Scenario: one profile per user per tenant
- GIVEN user U already has an active practitioner profile in tenant T1
- WHEN an authorized user creates a second profile for U in T1
- THEN the request fails with 422.

#### Scenario: user must belong to the tenant
- WHEN an authorized user creates a practitioner profile with a `user_id` that is not a member of the current tenant
- THEN the request fails with 422.

#### Scenario: health center must belong to the tenant
- WHEN a practitioner profile is created with a `health_center_id` owned by another tenant
- THEN the request fails with 422 (tenant-scoped `exists` validation).

---

## ADDED Requirements — capability `child-registry`

### REQ-CH-001 — Child CRUD

The system SHALL expose Orion CRUD for `child`. Required: `first_name`, `last_name_paternal`, `last_name_maternal`, `birth_date`, `sex_id`, `health_center_id`. Optional: `curp`, `external_id`, `notes`. `is_schooled` defaults to false. `birth_date` MUST NOT be in the future. The registry MUST NOT enforce the 1–71 month screening window (age eligibility belongs to the future Screening module).

#### Scenario: create a child
- GIVEN a user with `create.child`
- WHEN they POST a valid child payload
- THEN the child is created in the caller's tenant with the given `health_center_id`.

#### Scenario: future birth date rejected
- WHEN a child is created with `birth_date` after today (tenant timezone)
- THEN the request fails with 422.

#### Scenario: sex must be a central catalog row
- WHEN a child is created with a `sex_id` not present in the central `sex` catalog
- THEN the request fails with 422.

### REQ-CH-002 — CURP validation and normalization

When `curp` is provided the system MUST: (a) normalize it to uppercase and trim whitespace before validation and persistence; (b) validate the official 18-character CURP pattern `^[A-Z][AEIOUX][A-Z]{2}\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])[HM](AS|BC|BS|CC|CL|CM|CS|CH|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QT|QR|SP|SL|SR|TC|TS|TL|VZ|YN|ZS|NE)[B-DF-HJ-NP-TV-Z]{3}[A-Z\d]\d$`; (c) reject with 422 when the embedded birth-date segment (positions 5–10, `YYMMDD`) does not match `birth_date`, or the sex character (position 11, `H`/`M`) does not match `sex_id` (`H` = male, `M` = female).

#### Scenario: lowercase CURP is normalized then accepted
- WHEN a child is created with a syntactically valid CURP typed in lowercase, consistent with `birth_date` and `sex_id`
- THEN the child persists with the CURP stored in uppercase.

#### Scenario: malformed CURP rejected
- WHEN a child is created with `curp = "XXXX123"`
- THEN the request fails with 422 naming the `curp` field.

#### Scenario: CURP inconsistent with birth date rejected
- GIVEN `birth_date = 2024-03-05`
- WHEN the CURP's date segment encodes `240306`
- THEN the request fails with 422.

### REQ-CH-003 — Child identifier uniqueness (soft-delete aware, per tenant)

`curp` and `external_id` MUST each be unique per tenant among non-deleted children. NULL values never collide. Both constraints MUST exist at the DB level as partial unique indexes (`WHERE <col> IS NOT NULL AND deleted_at IS NULL`) and be pre-checked in validation for a friendly 422.

#### Scenario: duplicate CURP in tenant rejected
- GIVEN an active child in T1 with CURP X
- WHEN another T1 child is created with CURP X
- THEN 422; AND the same CURP X in tenant T2 succeeds.

#### Scenario: soft-deleted child frees its CURP
- GIVEN the only T1 child with CURP X was soft-deleted
- WHEN a new T1 child is created with CURP X
- THEN it succeeds; AND restoring the soft-deleted child while the new one is active MUST fail (409/422), not corrupt uniqueness.

### REQ-CH-004 — Caregiver CRUD

The system SHALL expose Orion CRUD for `caregiver`. Required: names, `education_level_id`, `occupation_id`, `population_type_id`, `phone`. Optional: `birth_date`, `email`. `has_social_program` defaults to false. `education_level_id` and `occupation_id` MUST reference tenant-scoped catalog rows of the caller's tenant; `population_type_id` MUST reference the central `population_type` catalog.

#### Scenario: tenant catalog row from another tenant rejected
- WHEN a T1 caregiver is created with an `occupation_id` belonging to tenant T2
- THEN the request fails with 422.

### REQ-CH-005 — Child–caregiver link

A child is linked to caregivers via `child_caregiver` rows carrying `caregiver_relationship_id` (tenant catalog) and `is_primary`. The pair (`child_id`, `caregiver_id`) MUST be unique among non-deleted links. Child and caregiver MUST belong to the same tenant.

#### Scenario: duplicate link rejected
- GIVEN caregiver C is already linked to child N
- WHEN a second link C↔N is created
- THEN 422; AND after soft-deleting the first link, re-linking succeeds.

#### Scenario: cross-tenant link rejected
- WHEN a link is attempted between a T1 child and a T2 caregiver
- THEN the request fails with 422.

### REQ-CH-006 — Exactly one primary caregiver per child

At most one non-deleted `child_caregiver` row per child MAY have `is_primary = true`, enforced by a DB partial unique index on (`child_id`) `WHERE is_primary AND deleted_at IS NULL`. The application SHALL keep the invariant "a child with links has exactly one primary": the first link created for a child becomes primary automatically; marking another link primary demotes the current primary in the same transaction; deleting the primary link while others exist requires the client to designate a new primary (422 otherwise).

#### Scenario: first link auto-primary
- WHEN the first caregiver link is created for child N without `is_primary`
- THEN it persists with `is_primary = true`.

#### Scenario: promoting a new primary demotes the old
- GIVEN N has primary caregiver A and secondary B
- WHEN B's link is updated to `is_primary = true`
- THEN after the transaction A's link has `is_primary = false` and B's is true; AND at no point do two active primaries exist.

#### Scenario: concurrent promotion cannot create two primaries
- WHEN two requests set different links of the same child primary concurrently
- THEN the DB partial unique index rejects one; the API returns 409/422, never two primaries.

### REQ-CH-007 — Birth record (1:1, unknown-aware)

Each child MAY have at most one non-deleted `birth_record` (partial unique on `child_id`). All clinical fields are nullable; NULL means "se ignora" (unknown) and MUST be distinguishable from false/0. Provided values MUST pass range validation: `gestational_age_weeks` 20–44, `birth_weight_grams` 400–7000, `birth_length_mm` 200–650, `prenatal_visit_count` 0–40, `nicu_days` 0–365, `mother_age_at_birth_years` 10–60, `breastfeeding_months` 0–71. Lengths/weights are integers in minor units (mm, g) — decimals MUST NOT be accepted or stored.

#### Scenario: second birth record rejected
- GIVEN child N has an active birth record
- WHEN a second one is created for N
- THEN the request fails with 422.

#### Scenario: partial capture allowed
- WHEN a birth record is created with only `birth_weight_grams = 3100` and everything else omitted
- THEN it persists with the omitted fields NULL (unknown), not zero/false.

#### Scenario: risk-factor derivability preserved
- GIVEN a birth record with `gestational_age_weeks = 33`, `birth_weight_grams = 1400`, `mother_age_at_birth_years = 15`
- THEN reading the record yields integer values usable for the future derivations (`< 34`, `<= 1500`, `< 16`) without any float conversion
- AND a record whose `gestational_age_weeks` is NULL yields "unknown", not a derivable false.

### REQ-CH-008 — Visit header

The system SHALL expose Orion CRUD for `visit` with required `child_id`, `health_center_id`, `practitioner_id` (a `practitioner_profile` of the tenant), and `visit_date` (not in the future). `receipt_number` is server-generated per tenant with format `V-YYYY-NNNNNN` (year of `visit_date`, zero-padded sequence), MUST be unique per tenant among non-deleted visits (DB partial unique), and MUST be immutable — update requests MUST NOT change it (client-supplied values on create/update are ignored or rejected). Visits carry no screening/application logic in this change.

#### Scenario: receipt number generated and unique
- WHEN two visits are created in tenant T1 during 2026
- THEN they receive distinct numbers `V-2026-000001` and `V-2026-000002`; AND tenant T2's first 2026 visit also gets `V-2026-000001`.

#### Scenario: concurrent creation yields no duplicates
- WHEN two visit creations race in the same tenant
- THEN both succeed with distinct receipt numbers (sequence acquisition is transactional/locked).

#### Scenario: referenced entities must share the tenant
- WHEN a visit is created for a T1 child with a T2 `practitioner_id` or T2 `health_center_id`
- THEN the request fails with 422.

#### Scenario: child visit history query
- WHEN listing visits filtered by `child_id` sorted by `visit_date` descending
- THEN results are served by the composite index (`tenant_id`, `child_id`, `visit_date DESC`) — the query MUST NOT require a per-row scan of the tenant's visits.

---

## ADDED Requirements — cross-cutting (merge into `child-registry` on archive)

### REQ-XC-001 — Tenant isolation

Every table in this change except `sex` and `population_type` MUST use `BaseModel::columns($table, true)` + `BelongsToTenant`. Reads and writes are scoped by `TenantScope`; a record of another tenant referenced by UUID MUST resolve to 404, never 403 or data. Every FK-style validation rule (`exists`) on tenant-scoped targets MUST be tenant-constrained (raw `exists:` without a tenant filter is a defect, since `Rule::exists` bypasses Eloquent scopes).

#### Scenario: cross-tenant show is 404
- GIVEN a T2 child with id X
- WHEN a T1 user GETs `/api/child/{X}`
- THEN the response is 404.

#### Scenario: cross-tenant list leak
- WHEN a T1 user lists any entity of this change
- THEN no T2 rows appear, including via `include`/relation expansion and via `POST /selects`.

### REQ-XC-002 — Permissions

Each of `health_center`, `practitioner_profile`, `child`, `caregiver`, `visit`, `sex`, `population_type`, `caregiver_relationship`, `education_level`, `occupation` SHALL have the six permissions `read/create/update/delete/restore/report.{entity}` seeded via `permissions.csv` and granted to tenant roles through the existing `whereNotIn` flow (plus explicit membership in `$commonPermissions` so `admin.super` also receives them). Write verbs on the CENTRAL catalogs `sex` and `population_type` MUST be super-admin exclusive (added to `$centralCatalogs` in `RolePermissionTableSeeder`). Controllers MUST declare `$keyForPermission` accordingly (`child_caregiver` and `birth_record` operations authorize under `child`).

#### Scenario: role without permission is denied
- GIVEN a token whose role lacks `create.child`
- WHEN it POSTs `/api/child`
- THEN the response is 403.

#### Scenario: tenant admin cannot mutate central catalogs
- GIVEN an `administrator` token
- WHEN it POSTs `/api/sex` or updates a `population_type` row
- THEN the response is 403; AND reading both catalogs succeeds.

### REQ-XC-003 — Catalog seeding

CENTRAL: `sex` (male/female) and `population_type` (urban/rural/indigenous — the Secretaría export will branch on it) MUST be seeded by `CatalogSeeder` via `upsertCatalog` with fixed UUID model constants, idempotently. TENANT-SCOPED: `caregiver_relationship` (madre, padre, abuela, abuelo, tutor legal, otro), `education_level`, and `occupation` MUST be added to `TenantCatalogSeeder::CATALOGS` so `TenantCreated` seeds them per tenant; re-running `tenants:seed` for an existing tenant MUST NOT overwrite admin-customized rows (firstOrCreate contract) and MUST backfill missing ones.

#### Scenario: new tenant gets its catalog rows
- WHEN a tenant is created
- THEN it owns its own `caregiver_relationship` rows including "Madre" and "Tutor legal", isolated from other tenants.

#### Scenario: reseeding preserves customization
- GIVEN a tenant renamed "Otro" to "Otro familiar"
- WHEN `tenants:seed` runs again
- THEN the renamed row keeps its name; nothing is duplicated.

### REQ-XC-004 — Module registration and menu

Both modules MUST complete the dynamic-menu checklist: rows in `modules.csv`, 6 permission rows per entity in `permissions.csv`, `module_system.csv` rows under the `Evaluación` system, the five `SystemTableSeeder` maps (permission_entity, route, visibility, icon — every module row MUST have an icon present in `frontend/src/shared/utils/iconMap.ts`), `RolePermissionTableSeeder`, `AngularController::$mapperSelects`, `useCatalogs.ts::CatalogType`, and `App.tsx` routes guarded by `PermissionRoute`.

#### Scenario: menu shows the new modules
- GIVEN an `administrator` after `ResetPermissionTableSeeder` + `SystemTableSeeder`
- WHEN they GET `/api/menu`
- THEN "Centros de Salud", "Profesionales de Salud", "Niños" and "Visitas" appear under `Evaluación`; "Cuidadores", "Sexos" and "Tipos de Población" do not (hidden).

#### Scenario: selects contract holds
- WHEN the frontend requests `POST /selects` for each new `CatalogType` member
- THEN each returns a non-empty array on a seeded tenant (a silently-empty dropdown is a failed scenario).

### REQ-XC-005 — Privacy of minors' PII (LFPDPPP / NOM-024)

Sensitive-PII inventory for this change: `child.*` identity fields (names, `birth_date`, `curp`, `sex_id`), the entire `birth_record` (health data), `caregiver` identity/contact fields, `practitioner_profile.license_number`. The system MUST: (a) record create/update/delete of these entities in the `audits` table (Laravel Auditing via BaseModel — old/new values live ONLY there, access-gated by `read.audit`); (b) never write PII field values to application log channels — log statements and exception context for these modules carry UUIDs and field NAMES only, never values; (c) never place CURP or names in URLs or query strings (UUID route keys only); (d) exclude `child`, `caregiver`, `birth_record`, and `practitioner_profile` from any debug/payload-dump middleware.

#### Scenario: audit trail without log leakage
- WHEN a child's `curp` is updated
- THEN an `audits` row records old/new values; AND `storage/logs/laravel.log` gains no line containing the CURP value.

#### Scenario: audit read is permission-gated
- GIVEN a role without `read.audit`
- WHEN it requests the audit trail of a child
- THEN the response is 403.

### REQ-XC-006 — Frontend behavior

The five React modules MUST use the standard scaffolding (`createEntityStore`, `createCrudApi`, `useBaseList`/`useBaseCrud`, react-hook-form + Zod, zero `any`, UI text in Mexican Spanish). Catalog loading MUST be lazy: forms load catalogs on open; list filters use `useLazyCatalogs`/`onFirstOpen`; no `useCatalogs` call may live in list scope or an always-mounted modal body. Dates render via `displayFormatDate()`. Caregivers and birth record are managed from the Child detail (tabs), not standalone pages; visits are creatable from the Child detail.

#### Scenario: no eager selects on list mount
- WHEN `ChildListPage` mounts
- THEN no `POST /selects` request fires until a form or filter dropdown opens.

#### Scenario: unknown tri-state in birth record form
- WHEN the perinatal form renders a nullable boolean (e.g., `early_breastfeeding`)
- THEN the control offers "Sí / No / Se ignora" and submits `true`/`false`/`null` respectively.
