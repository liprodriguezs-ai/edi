# Spec: EDI Stimulation Sessions

Change: `edi-stimulation-sessions`
Capabilities: `early-stimulation` (new)

> RFC 2119 keywords (MUST, MUST NOT, SHALL, SHOULD, MAY) are normative.
> All entities are tenant-scoped unless stated CENTRAL.
> "Authorized user" = a user whose role holds the relevant `{verb}.{entity}` permission.
> On archive, all requirements merge into `openspec/specs/early-stimulation/spec.md`.

---

## ADDED Requirements — capability `early-stimulation`

### REQ-SS-001 — Stimulation session as a concrete 4-section record

The system SHALL expose Orion CRUD for `stimulation_session` with soft deletes. Required: `session_date` (not in the future), `practitioner_id` (a `practitioner_profile` of the tenant), and a subject per REQ-SS-002. Every section field is nullable — the record captures what was actually performed: integration (`integration_dynamic_id`, `integration_material`, `integration_song`), preparation (`preparation_song` + massage pairs per REQ-SS-003), core activity (`core_stimulation_area_id`, `core_dynamic_id`, `core_activity`, `core_material`, `core_song`), relaxation (`relaxation_notes` + zones per REQ-SS-003), plus `observations`. The four sections MUST be modeled as columns on the entity; the system MUST NOT introduce generic form-engine tables (`document`/`document_section`/`document_question`-style) for this record.

#### Scenario: create a minimal individual session
- GIVEN a user with `create.stimulation_session`
- WHEN they POST `{child_id: <valid>, session_date: <today>, practitioner_id: <valid>}`
- THEN the row is created with the caller's `tenant_id` stamped and every omitted section field NULL.

#### Scenario: catalog references are tenant/central-validated
- WHEN a session is created with an `integration_dynamic_id` belonging to another tenant, or a `core_stimulation_area_id` not present in the central `stimulation_area` catalog
- THEN the request fails with 422 and no row is created.

#### Scenario: future session date rejected
- WHEN a session is created with `session_date` after today
- THEN the request fails with 422.

### REQ-SS-002 — Session subject: child or group, optional visit

Every session MUST have a subject. The API SHALL enforce exactly one of `child_id` (individual session) or `stimulation_group_id` (group session) — 422 when both or neither are present. The DB SHALL enforce at least one via the CHECK constraint `(child_id IS NOT NULL OR stimulation_group_id IS NOT NULL)` as a backstop. `visit_id` MAY be provided only on individual sessions and MUST reference a visit of the same child.

#### Scenario: subjectless and double-subject payloads rejected
- WHEN a session is created with neither `child_id` nor `stimulation_group_id`, or with both
- THEN the request fails with 422.

#### Scenario: visit coherence
- WHEN a session is created with a `visit_id` whose visit belongs to a different child, or with `visit_id` on a group session
- THEN the request fails with 422.

#### Scenario: group session accepted
- WHEN a session is created with only `stimulation_group_id` referencing a group of the caller's tenant
- THEN it succeeds.

### REQ-SS-003 — Massage movements and relaxation zones (declarative nested sync)

Preparation massage data lives in `stimulation_session_movement` (`body_zone_id` × `massage_movement_id` pairs) and relaxation zones in `stimulation_session_relaxation_zone` (`body_zone_id`). Both MUST be written only through the session create/update payload as declarative arrays; the service SHALL sync them in the session's transaction (create missing, soft-delete removed, restore re-added). Uniqueness among non-deleted rows: (`session_id`, `body_zone_id`, `massage_movement_id`) and (`session_id`, `body_zone_id`) respectively, enforced as DB partial unique indexes. Referenced catalog rows MUST belong to the caller's tenant. No standalone endpoint exposes these tables.

#### Scenario: sync lifecycle
- GIVEN a session created with 3 movement pairs and 2 relaxation zones
- WHEN it is updated with one pair removed and a new pair added
- THEN the removed pair's row is soft-deleted, the new one exists, the other two are untouched
- AND re-adding the removed pair later restores it without creating a duplicate.

#### Scenario: duplicate pair in payload rejected
- WHEN a session payload repeats the same (`body_zone_id`, `massage_movement_id`) pair
- THEN the request fails with 422.

#### Scenario: cross-tenant body zone rejected
- WHEN a movement entry references a `body_zone_id` of another tenant
- THEN the request fails with 422.

### REQ-SG-001 — Stimulation group CRUD

The system SHALL expose Orion CRUD for `stimulation_group`. Required: `name`, `stimulation_group_type_id` (tenant catalog), `health_center_id`, `promoter_user_id` (a `user` who is a member of the tenant), `start_date`. Optional: `end_date` (MUST be >= `start_date`). `is_active` defaults to true. `name` MUST be unique per tenant among non-deleted rows (DB partial unique + validation pre-check).

#### Scenario: name uniqueness is tenant- and soft-delete-aware
- GIVEN tenant T1 has an active group "Estimulación matutina"
- WHEN a T1 user creates another group with that name → 422
- AND a T2 user creates one with that name → success
- AND after soft-deleting T1's group, recreating the name in T1 → success.

#### Scenario: promoter must belong to the tenant
- WHEN a group is created with a `promoter_user_id` that is not a member of the current tenant
- THEN the request fails with 422.

#### Scenario: incoherent dates rejected
- WHEN a group is created with `end_date` before `start_date`
- THEN the request fails with 422.

### REQ-SG-002 — Group membership

`stimulation_group_member` links a child to a group with `enrolled_at` (defaults to the current date when omitted) and `default_attendance_payment_status_id` (central `attendance_payment_status` catalog). The pair (`stimulation_group_id`, `child_id`) MUST be unique among non-deleted rows. Child and group MUST belong to the same tenant. Enrollment into a group with `is_active = false` MUST fail with 422. Membership operations authorize under the `stimulation_group` permission entity.

#### Scenario: duplicate enrollment rejected, re-enrollment after unenroll allowed
- GIVEN child N is an active member of group G
- WHEN N is enrolled in G again → 422
- AND after soft-deleting the membership, enrolling N again → success.

#### Scenario: cross-tenant child rejected
- WHEN a T2 child is enrolled into a T1 group
- THEN the request fails with 422.

#### Scenario: inactive group rejects enrollment
- GIVEN group G has `is_active = false`
- WHEN a child is enrolled into G
- THEN the request fails with 422.

### REQ-SA-001 — Attendance bulk upsert (idempotent)

`POST /api/stimulation-group/{id}/attendance` SHALL accept `{session_date, entries: [{child_id, attended, homework_done, attendance_payment_status_id?}]}` and upsert `stimulation_attendance` rows keyed by (`stimulation_group_id`, `child_id`, `session_date`), backed by a plain (non-partial) DB unique index on that triple. Re-sending the same payload MUST NOT create duplicate rows and MUST leave the same final state. When `attendance_payment_status_id` is omitted, the member's `default_attendance_payment_status_id` applies. A trashed row for the key is restored and updated, never duplicated. Two concurrent submissions for the same key MUST NOT produce two rows. The endpoint authorizes under `update.stimulation_group` and returns the upserted attendance entities.

#### Scenario: idempotent replay
- GIVEN a POST marking 3 children for 2026-08-10
- WHEN the identical payload is POSTed again
- THEN the attendance row count for (G, 2026-08-10) is still 3 and the stored values are unchanged.

#### Scenario: member default payment status applied
- GIVEN member M has `default_attendance_payment_status_id = <scholarship>`
- WHEN an entry for M omits `attendance_payment_status_id`
- THEN the persisted row carries the scholarship status.

#### Scenario: concurrent upserts converge to one row
- WHEN two requests upsert the same (child, date) for group G concurrently
- THEN exactly one row exists afterwards and both requests succeed (retry-on-conflict), or one fails with 409 — never two rows.

### REQ-SA-002 — Attendance validation and reads

Every entry's `child_id` MUST be an active (non-deleted) member of the group; otherwise the whole batch fails with 422 naming the offending child, and nothing is persisted (single transaction). `session_date` MUST NOT be in the future and MUST NOT precede the group's `start_date`. Upserts against an inactive group MUST fail with 422. Attendance rows are facts: no destroy endpoint is exposed. Reads go through the Orion index/search of `stimulation_attendance` (authorizing under `stimulation_group`), filtered by group and date range, served by the (`tenant_id`, `session_date`) index.

#### Scenario: non-member entry aborts the batch
- GIVEN a batch of 3 entries where one child is not a member of G
- WHEN the batch is POSTed
- THEN the response is 422 and zero attendance rows were written.

#### Scenario: date bounds
- WHEN a batch is POSTed with `session_date` in the future, or earlier than G's `start_date`
- THEN the request fails with 422.

#### Scenario: grid read
- WHEN the frontend searches attendance by `stimulation_group_id` and a date range
- THEN only rows of the caller's tenant and that group return.

### REQ-XC-001 — Catalog seeding

CENTRAL (fixed UUID model constants, seeded idempotently by `CatalogSeeder::upsertCatalog`, read-only for tenant roles): `session_section` (integration/preparation/core/relaxation + `sort_order`), `stimulation_area` (sensory/cognitive/socio_affective/language/motor), `attendance_payment_status` (scholarship/paid/unpaid). TENANT-SCOPED (added to `TenantCatalogSeeder::CATALOGS`, `firstOrCreate` preserving admin customization): `stimulation_dynamic` (Mamá/bebé, Bebé/bebé, Grupal, Circuito), `body_zone` (9 rows: Cuello, Hombro/brazo, Mano, Tronco, Pierna, Pie, Cara, Pecho y abdomen, Espalda), `massage_movement` (7 rows: Flexión, Extensión, Circunducción, Media circunducción, Arriba-abajo, Abducción-aducción, Amasamiento de dedos), `didactic_material` (no default rows), `song` (no default rows), `stimulation_group_type` (Grupo abierto).

#### Scenario: new tenant receives its catalog rows
- WHEN a tenant is created
- THEN it owns its own `body_zone` rows including "Cuello" and "Espalda", isolated from other tenants.

#### Scenario: reseeding preserves customization
- GIVEN a tenant renamed "Circuito" to "Circuito motor"
- WHEN `tenants:seed` runs again
- THEN the renamed row keeps its name and nothing is duplicated.

#### Scenario: central rows are fixed and idempotent
- WHEN `CatalogSeeder` runs twice
- THEN `attendance_payment_status` still has exactly 3 rows with the fixed UUIDs from the model constants.

### REQ-XC-002 — Permissions

`stimulation_session`, `stimulation_group`, and the 9 catalog entities SHALL each have the six permissions `read/create/update/delete/restore/report.{entity}` (66 rows), granted through the existing `whereNotIn` flow plus `$commonPermissions`. Write verbs on the CENTRAL catalogs `session_section`, `stimulation_area`, `attendance_payment_status` MUST be super-admin exclusive (`$centralCatalogs` in `RolePermissionTableSeeder`). `StimulationGroupMemberController` and `StimulationAttendanceController` MUST declare `$keyForPermission = 'stimulation_group'` (sub-resources of the group, per the `child_caregiver` precedent).

#### Scenario: attendance requires the group permission
- GIVEN a token whose role lacks `update.stimulation_group`
- WHEN it POSTs `/api/stimulation-group/{id}/attendance`
- THEN the response is 403.

#### Scenario: tenant admin cannot mutate central catalogs
- GIVEN an `administrator` token
- WHEN it POSTs `/api/stimulation-area`
- THEN the response is 403; AND reading the catalog succeeds.

### REQ-XC-003 — Module registration, menu, and selects contract

The change MUST complete the dynamic-menu checklist: 11 rows in `modules.csv`, 66 rows in `permissions.csv`, `module_system.csv` rows under the `Evaluación` system, the `SystemTableSeeder` maps (permission entities with `update:` prefix for the 6 tenant catalogs, routes, visibility, icons — every icon present in `frontend/src/shared/utils/iconMap.ts`), `RolePermissionTableSeeder`, 9 `AngularController::$mapperSelects` entries, 9 `useCatalogs.ts::CatalogType` members, and `App.tsx` routes under `PermissionRoute`. The 3 central catalogs are hidden module rows. Children MUST NOT be exposed through `POST /selects`: session and attendance child pickers use the child list endpoint (clinic D12 precedent).

#### Scenario: menu composition
- GIVEN an `administrator` after reseeding
- WHEN they GET `/api/menu`
- THEN "Sesiones de Estimulación", "Grupos de Estimulación" and the 6 tenant-catalog pages appear under `Evaluación`; "Secciones de Sesión", "Áreas de Estimulación" and "Estatus de Pago de Asistencia" do not.

#### Scenario: selects contract holds
- WHEN the frontend requests `POST /selects` for each of the 9 new `CatalogType` members on a seeded tenant
- THEN each returns 200 with a non-empty array, EXCEPT `didactic_material` and `song` which return 200 with an empty array (no error) until the admin fills them.

### REQ-XC-004 — Tenant isolation

All 6 transactional tables and the 6 tenant catalogs MUST use `BaseModel::columns($table, true)` + `BelongsToTenant`. A record of another tenant referenced by UUID MUST resolve to 404, never 403 or data. Every `exists`-style validation on tenant-scoped targets MUST be tenant-constrained (raw `exists:` bypasses `TenantScope`). No cross-tenant rows may leak via `include` expansion, `POST /selects`, or attendance reads.

#### Scenario: cross-tenant show is 404
- GIVEN a T2 stimulation group with id X
- WHEN a T1 user GETs `/api/stimulation-group/{X}`
- THEN the response is 404.

#### Scenario: attendance list never leaks
- WHEN a T1 user searches attendance
- THEN no T2 rows appear.

### REQ-XC-005 — Frontend behavior

The React modules MUST use the standard scaffolding (`createEntityStore`, `createCrudApi`, `useBaseList`/`useBaseCrud`, react-hook-form + Zod, zero `any`, UI in Mexican Spanish, dates via `displayFormatDate()`). Catalog loading MUST be lazy (forms on open; filters via `useLazyCatalogs`/`onFirstOpen`; never in list scope or an always-mounted modal body). `AttendanceSheetPage` SHALL render the group's active members as rows for a selected date with three controls per row (asistió, tarea, estatus de pago), prefill the payment control from the member default, apply changes optimistically, persist through the bulk endpoint, and on failure revert the affected row(s) and show `toast.error`. Material/song inputs are free-text comboboxes suggesting from their catalogs.

#### Scenario: no eager selects on list mount
- WHEN `StimulationSessionListPage` or `StimulationGroupListPage` mounts
- THEN no `POST /selects` request fires until a form or filter dropdown opens.

#### Scenario: optimistic save with rollback
- GIVEN the attendance sheet shows member M unmarked
- WHEN the user toggles "asistió" and the bulk POST fails
- THEN the checkbox reverts to unmarked and an error toast is shown.

#### Scenario: member without attendance rows renders defaults
- WHEN the sheet loads a date with no attendance rows for member M
- THEN M's row shows attended=false, tarea=false, and M's default payment status, without any write occurring.
