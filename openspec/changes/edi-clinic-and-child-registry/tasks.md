# Tasks: EDI Clinic & Child Registry

Change: `edi-clinic-and-child-registry`

## Delivery forecast (review workload guard)

Decision needed before apply: Yes
Chained PRs recommended: Yes
400-line budget risk: High

Two backend modules (8 tables + 5 catalogs), full registration wiring, and 5 React modules far exceed one 400-line PR. Recommended chain (each slice autonomous, verifiable, revertible): **PR1** = Phases 1–2 (Clinic backend + catalogs), **PR2** = Phases 3–4 (ChildRecord backend), **PR3** = Phase 5 (registration wiring), **PR4** = Phases 6–7 (frontend). Resolve open questions Q1–Q5 (design.md §7) before starting Phase 3.

All paths relative to `/Users/rodriguez/code/edi-app/`. Backend tests run from `backend/` with the host command in `openspec/config.yaml`.

---

## Phase 0 — Scaffolding

- [x] 0.1 Scaffold `backend/Modules/Clinic/` and `backend/Modules/ChildRecord/` cloning the `backend/Modules/UserProfile/` layout (`module.json`, `composer.json`, `app/{Models,Http/{Controllers,Requests},Services,Providers,Rules}`, `database/{migrations,seeders,factories}`, `routes/api.php`, `tests/Feature`).
- [x] 0.2 Register both in `backend/modules_statuses.json` (`"Clinic": true`, `"ChildRecord": true`).
- [x] 0.3 Confirm module service providers boot (route + migration discovery) with a smoke `php artisan route:list | grep health_center` after Phase 1.

## Phase 1 — Clinic backend

- [x] 1.1 Migration `backend/Modules/Clinic/database/migrations/XXXX_create_health_center_table.php`: table comment inside closure, `BaseModel::columns($table, true)`, columns per design §3.1 (every own column with `->comment()`), then `DB::statement` partial unique `health_center_tenant_clues_unique`.
- [x] 1.2 Migration `..._create_practitioner_profile_table.php`: columns per design §3.1 + partial unique `practitioner_profile_tenant_user_unique`.
- [x] 1.3 Models `backend/Modules/Clinic/app/Models/HealthCenter.php` and `PractitionerProfile.php`: extend `BaseModel`, `BelongsToTenant`, `$fillable`, casts, relations (`municipality()`, `user()`, `healthCenter()`, singular snake_case method names).
- [x] 1.4 Requests `backend/Modules/Clinic/app/Http/Requests/{HealthCenterCreateRequest,HealthCenterUpdateRequest,PractitionerProfileCreateRequest,PractitionerProfileUpdateRequest}.php`: CLUES regex + uppercase normalization in `prepareForValidation`; per-tenant unique pre-checks; tenant-scoped `exists` for `health_center_id`; user-belongs-to-tenant rule for `user_id`; one-profile-per-user rule.
- [x] 1.5 Controllers `backend/Modules/Clinic/app/Http/Controllers/{HealthCenterController,PractitionerProfileController}.php` extending `QCH\Core\Http\Controllers\Controller` with `$model`, `$keyForPermission`, `includes()`, `searchableBy()`.
- [x] 1.6 Routes `backend/Modules/Clinic/routes/api.php`: `Orion::resource('health_center', ...)` and `Orion::resource('practitioner_profile', ...)` with `->withSoftDeletes()->middleware(['auth:sanctum', 'initializeTenancy'])` under `throttle:api`.
- [x] 1.7 Factories `backend/Modules/Clinic/database/factories/{HealthCenterFactory,PractitionerProfileFactory}.php`.
- [x] 1.8 Feature tests `backend/Modules/Clinic/tests/Feature/`: CRUD happy path, CLUES uniqueness (dup / other-tenant / soft-delete-reuse — REQ-CL-002), one-profile-per-user (REQ-CL-003), cross-tenant 404 + 422 on foreign FKs (REQ-XC-001), 403 without permission (REQ-XC-002).

## Phase 2 — ChildRecord catalogs

- [x] 2.1 Migrations in `backend/Modules/ChildRecord/database/migrations/`: `..._create_sex_table.php`, `..._create_population_type_table.php` (central: `BaseModel::columns($table, false)`, `key` unique), and `..._create_caregiver_relationship_table.php`, `..._create_education_level_table.php`, `..._create_occupation_table.php` (tenant: `BaseModel::columns($table, true)` + partial unique `(tenant_id, name) WHERE deleted_at IS NULL`).
- [x] 2.2 Models `backend/Modules/ChildRecord/app/Models/{Sex,PopulationType,CaregiverRelationship,EducationLevel,Occupation}.php` — `Sex`/`PopulationType` with the fixed UUID constants from design §3.2; tenant catalogs with `BelongsToTenant`.
- [x] 2.3 `backend/database/seeders/CatalogSeeder.php`: add `seedSexes()` and `seedPopulationTypes()` using `upsertCatalog` with the constants.
- [x] 2.4 `backend/database/seeders/TenantCatalogSeeder.php`: fill `CATALOGS` with the three models and the Spanish default rows from design §3.2.
- [x] 2.5 Orion controllers + routes for the 5 catalogs (read-only usage for central pair still ships full resource; write verbs get blocked by permissions): `backend/Modules/ChildRecord/app/Http/Controllers/{Sex,PopulationType,CaregiverRelationship,EducationLevel,Occupation}Controller.php` + `routes/api.php` entries.
- [x] 2.6 Feature tests: central catalogs seeded with fixed UUIDs and idempotent re-seed; tenant catalogs seeded per tenant, `firstOrCreate` preserves renames (REQ-XC-003); `administrator` 403 on `create.sex` (REQ-XC-002).

## Phase 3 — ChildRecord core (child, caregiver, links, birth record)

> Gate: confirm Q1/Q2/Q4 answers from design §7 before this phase.

- [x] 3.1 Migrations: `..._create_child_table.php` (partial uniques on curp/external_id + the two composite indexes), `..._create_caregiver_table.php`, `..._create_child_caregiver_table.php` (pair + primary partial uniques), `..._create_birth_record_table.php` (child 1:1 partial unique). Comments on table + every column; NULL="se ignora" semantics stated in birth_record comments.
- [x] 3.2 Models `backend/Modules/ChildRecord/app/Models/{Child,Caregiver,ChildCaregiver,BirthRecord}.php` with relations (`sex()`, `health_center()` → note: method names = include keys, snake_case for belongsTo) and boolean/date casts (nullable booleans stay nullable).
- [x] 3.3 Rule `backend/Modules/ChildRecord/app/Rules/Curp.php`: official pattern + uppercase normalization + birth_date/sex cross-check (REQ-CH-002).
- [x] 3.4 Requests for the four entities in `backend/Modules/ChildRecord/app/Http/Requests/`: child (Curp rule, future-date guard, tenant-scoped exists on health_center_id/sex_id), caregiver (tenant-scoped exists on the two tenant catalogs, central exists on population_type_id), child_caregiver (same-tenant child+caregiver), birth_record (ranges from design §3.3, integer-only).
- [x] 3.5 Service `backend/Modules/ChildRecord/app/Services/ChildCaregiverService.php`: first-link auto-primary, transactional demote-then-promote, block deleting last primary while other links exist (REQ-CH-006), 23505 → 409/422 mapping.
- [x] 3.6 Controllers `{Child,Caregiver,ChildCaregiver,BirthRecord}Controller.php` (`ChildCaregiverController` + `BirthRecordController` with `$keyForPermission = 'child'`); routes in `backend/Modules/ChildRecord/routes/api.php`.
- [x] 3.7 Factories for the four entities.
- [x] 3.8 Feature tests: REQ-CH-001..007 scenarios verbatim (CURP normalization/format/consistency, uniqueness incl. restore-conflict, pair + primary invariants incl. concurrency via two transactions, birth-record 1:1 + partial capture + range rejections), cross-tenant isolation (REQ-XC-001), audit row on curp update + assert log file free of the CURP value (REQ-XC-005).

## Phase 4 — Visit

- [x] 4.1 Migrations: `..._create_visit_table.php` (partial unique receipt + `visit_tenant_child_date_idx` with `visit_date DESC`) and `..._create_visit_receipt_sequence_table.php`.
- [x] 4.2 Models `Visit.php` (relation `practitioner()` → `PractitionerProfile`, explicit FK `practitioner_id`) and `VisitReceiptSequence.php`.
- [x] 4.3 Service `backend/Modules/ChildRecord/app/Services/VisitService.php`: `performStore` generates `V-YYYY-NNNNNN` under `lockForUpdate` on the (tenant, year) sequence row; `performUpdate` strips/rejects `receipt_number` (REQ-CH-008).
- [x] 4.4 `VisitController.php` + requests (same-tenant child/center/practitioner, `visit_date` not future) + route + factory.
- [x] 4.5 Feature tests: sequential numbering per tenant/year, cross-tenant independence, concurrency (two parallel creates → distinct numbers), immutability on update, history sort by `visit_date` desc filtered by child.

## Phase 5 — Registration wiring (menu, permissions, selects)

- [x] 5.1 `backend/database/seeders/csv/modules.csv`: the 10 rows from design §5 (fixed UUIDs `ed000001-...0001..0010`).
- [x] 5.2 `backend/database/seeders/csv/permissions.csv`: 60 rows (6 verbs × 10 module rows), existing description style.
- [x] 5.3 `backend/database/seeders/csv/module_system.csv`: 10 rows → system `b266838b-4cb0-4eb1-aa76-242cf96df41a` with sorts/groups/icons from design §5.
- [x] 5.4 `backend/database/seeders/SystemTableSeeder.php`: extend the four maps (permission entities incl. `update:` prefixes for the 3 tenant catalogs, routes, `$hidden` += Cuidadores/Sexos/Tipos de Población, icons — verify each icon key exists in `frontend/src/shared/utils/iconMap.ts`, add mappings there if missing).
- [x] 5.5 `backend/database/seeders/RolePermissionTableSeeder.php`: append the 60 permission names to `$commonPermissions`; append `'sex'`, `'population_type'` to `$centralCatalogs`.
- [x] 5.6 `backend/app/Http/Controllers/AngularController.php`: add the 7 `$mapperSelects` entries from design §5 (no `Child`).
- [x] 5.7 Run `php artisan db:seed --class=ResetPermissionTableSeeder` (plus `SystemTableSeeder`) on dev DB; smoke-test `GET /api/menu` shows/hides modules per REQ-XC-004 and `POST /selects` returns non-empty arrays for all 7 keys.
- [x] 5.8 Feature test asserting menu composition + selects contract for a seeded `administrator` (REQ-XC-004).

## Phase 6 — Frontend: Clinic + catalogs

- [x] 6.1 `frontend/src/shared/hooks/useCatalogs.ts`: add the 7 `CatalogType` members (design §5) — keys must round-trip `Str::studly` to the mapper entries.
- [x] 6.2 `frontend/src/modules/healthCenter/{schema.ts,services/HealthCenterResource.ts,store/healthCenterStore.ts,HealthCenterListPage.tsx}` + form modal (Zod schema mirrors backend validation incl. CLUES regex; UI copy in Spanish).
- [x] 6.3 `frontend/src/modules/practitioner/{schema.ts,services/PractitionerResource.ts,store/practitionerStore.ts,PractitionerListPage.tsx}` + form (user + health-center selects load on form open; practitioner select pattern `_with: 'user'`).
- [x] 6.4 Catalog admin pages: 3 `createCatalogPage` registrations in `frontend/src/modules/catalogs/pages.tsx` (caregiver-relationship, education-level, occupation).
- [x] 6.5 `frontend/src/App.tsx`: `PermissionRoute` blocks + routes `/health-center`, `/practitioner`, `/caregiver-relationship`, `/education-level`, `/occupation`.

## Phase 7 — Frontend: ChildRecord

- [x] 7.1 `frontend/src/modules/child/{schema.ts,services/ChildResource.ts,store/childStore.ts,ChildListPage.tsx,ChildFormPage.tsx,ChildDetailPage.tsx}`: list (search by name/CURP, no eager catalogs — REQ-XC-006), form (CURP uppercase input mask, sex/health-center catalogs on form open), detail tabs Datos generales / Cuidadores / Antecedentes perinatales / Visitas.
- [x] 7.2 `frontend/src/modules/caregiver/{schema.ts,services/CaregiverResource.ts,store/caregiverStore.ts}` + components `CaregiverFormModal.tsx`, `ChildCaregiverPanel.tsx` (link/unlink, kinship select, primary toggle honoring REQ-CH-006 errors).
- [x] 7.3 Birth-record tab: `frontend/src/modules/child/components/BirthRecordForm.tsx` with tri-state Sí/No/Se ignora controls submitting `true/false/null` and integer-only numeric fields (REQ-CH-007 / REQ-XC-006).
- [x] 7.4 `frontend/src/modules/visit/{schema.ts,services/VisitResource.ts,store/visitStore.ts,VisitListPage.tsx}` + `VisitFormModal.tsx` (child pre-bound from detail; receipt_number read-only display; `displayFormatDate` for dates).
- [x] 7.5 `frontend/src/App.tsx`: routes `/child`, `/child/:id`, `/visit` under their `PermissionRoute`s.
- [x] 7.6 Vitest coverage: Zod schemas (CURP, ranges, tri-state mapping) + `ChildCaregiverPanel` primary-toggle behavior.

## Phase 8 — Verification & closure

- [x] 8.1 Backend: `./vendor/bin/pint --dirty`; full suite `DB_HOST=127.0.0.1 DB_PORT=5451 DB_USERNAME=rodriguez DB_PASSWORD=password php artisan test --parallel --processes=10` green.
- [x] 8.2 Frontend: `pnpm exec tsc -p tsconfig.app.json --noEmit`, `pnpm exec vitest run`, `pnpm build` green; grep confirms zero `any` in new modules.
- [x] 8.3 `migrate:fresh --seed` from clean; create a tenant; verify tenant catalogs seeded and menu correct for `administrator` (DB/HTTP-level, via `ModuleRegistrationTest`). Playwright smoke on `/child` + `/health-center` NOT run — the dev server URLs documented for this session belong to a different project and were unreachable; not in the user's explicit verification checklist for this task.
- [x] 8.4 Privacy audit pass: update a child CURP, assert `audits` row exists and `storage/logs/laravel.log` contains no PII values (REQ-XC-005) — covered by `ChildAuditTest`.
- [ ] 8.5 Update `.project-context/modules.md` (move Clinic/ChildRecord from "a construir" to built) and log the change via `sdd-verify` → `sdd-archive`. modules.md update DONE; sdd-verify/sdd-archive are out of scope for sdd-apply — next phase.
