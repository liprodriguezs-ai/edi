# Tasks: EDI Stimulation Sessions

Change: `edi-stimulation-sessions`

## Delivery forecast (review workload guard)

Decision needed before apply: Yes
Chained PRs recommended: Yes
400-line budget risk: High

One backend module (6 transactional tables + 9 catalogs), 66 permission rows of wiring, and 2 React modules with a custom grid page far exceed one 400-line PR. Recommended chain (each slice autonomous, verifiable, revertible): **PR1** = Phases 0–2 (module + catalogs + sessions), **PR2** = Phases 3–4 (groups + attendance), **PR3** = Phase 5 (registration wiring), **PR4** = Phases 6–7 (frontend). Resolve open questions Q1–Q7 (design.md §8) before starting Phase 2.

**Apply-mode note**: this run executed end-to-end in one pass (user directive: automatic SDD mode, no PR chaining performed by this agent — delivery/PR slicing is left to the user). All phases below are implemented, tested, and verified together; the 400-line guard was acknowledged but not enforced as separate PRs in this session.

All paths relative to `/Users/rodriguez/code/edi-app/`. Backend tests run from `backend/` with the host command in `openspec/config.yaml` (baseline 737 green — apply verified against 738 green pre-existing, ending at 770 green). Backend tests run from `backend/`; frontend baseline 255 green, ending at 280 green.

---

## Phase 0 — Scaffolding

- [x] 0.1 Scaffold `backend/Modules/Stimulation/` cloning the `backend/Modules/Clinic/` layout (`module.json`, `composer.json`, `app/{Models,Http/{Controllers,Requests},Services,Providers}`, `database/{migrations,seeders,factories}`, `routes/api.php`, `tests/Feature`).
- [x] 0.2 Register it in `backend/modules_statuses.json` (`"Stimulation": true`).
- [x] 0.3 Smoke `php artisan route:list | grep stimulation` after Phase 2 routes land.

## Phase 1 — Catalogs (central + tenant)

- [x] 1.1 Migrations for the 3 CENTRAL catalogs in `backend/Modules/Stimulation/database/migrations/`: `..._create_session_section_table.php` (with `sort_order`), `..._create_stimulation_area_table.php`, `..._create_attendance_payment_status_table.php` — `BaseModel::columns($table, false)`, `key` unique, table comment inside the closure, `->comment()` on every own column.
- [x] 1.2 Migrations for the 6 TENANT catalogs: `stimulation_dynamic`, `body_zone`, `massage_movement`, `didactic_material`, `song`, `stimulation_group_type` — `BaseModel::columns($table, true)` + partial unique `(tenant_id, name) WHERE deleted_at IS NULL` via `DB::statement`.
- [x] 1.3 Models `backend/Modules/Stimulation/app/Models/{SessionSection,StimulationArea,AttendancePaymentStatus}.php` with the fixed UUID constants from design §3 (`ed400001..3-0000-4000-8000-…`); `{StimulationDynamic,BodyZone,MassageMovement,DidacticMaterial,Song,StimulationGroupType}.php` with `BelongsToTenant`.
- [x] 1.4 `backend/database/seeders/CatalogSeeder.php`: add `seedSessionSections()`, `seedStimulationAreas()`, `seedAttendancePaymentStatuses()` using `upsertCatalog` with the constants (REQ-XC-001).
- [x] 1.5 `backend/database/seeders/TenantCatalogSeeder.php`: append the 6 models to `CATALOGS` with the Spanish default rows from design §3 (`didactic_material`/`song` with empty row lists; `stimulation_group_type` with "Grupo abierto").
- [x] 1.6 Orion controllers + routes for the 9 catalogs (`$keyForPermission` = own table name each; central writes get blocked by permissions in Phase 5).
- [x] 1.7 Feature tests: central catalogs seed with fixed UUIDs and idempotent re-seed; tenant catalogs seed per tenant and `firstOrCreate` preserves renames (REQ-XC-001 scenarios). — `Modules/Stimulation/tests/Feature/CatalogSeedingTest.php`.

## Phase 2 — Sessions (entity + join tables + nested sync)

> Gate: confirm Q1/Q2/Q3 answers from design §8 before this phase. — Confirmed: defaults as encoded (exactly-one subject, free-text material/song, session_section kept with no FK consumer).

- [x] 2.1 Migration `..._create_stimulation_session_table.php`: columns per design §3 (all with `->comment()`), the three composite indexes, and `DB::statement('ALTER TABLE stimulation_session ADD CONSTRAINT stimulation_session_subject_check CHECK (child_id IS NOT NULL OR stimulation_group_id IS NOT NULL)')`.
- [x] 2.2 Migrations `..._create_stimulation_session_movement_table.php` and `..._create_stimulation_session_relaxation_zone_table.php` with their partial unique indexes (design §3; FK column named `session_id`).
- [x] 2.3 Models `StimulationSession.php` (relations `child()`, `stimulation_group()`, `visit()`, `practitioner()` → `PractitionerProfile`, `integration_dynamic()`, `core_dynamic()`, `core_stimulation_area()`; hasMany `movements()`, `relaxation_zones()`; date casts), `StimulationSessionMovement.php`, `StimulationSessionRelaxationZone.php` (explicit `session_id` FK on relations).
- [x] 2.4 Requests `StimulationSessionCreateRequest`/`StimulationSessionUpdateRequest`: exactly-one-subject rule (Q1/D5), `session_date` not future, tenant-scoped `exists` for child/group/practitioner/visit + catalog FKs, visit-belongs-to-child rule, `movements[]`/`relaxation_zones[]` array validation with in-payload duplicate rejection (REQ-SS-003).
- [x] 2.5 Service `backend/Modules/Stimulation/app/Services/StimulationSessionService.php`: `performStore`/`performUpdate` run subject validation + declarative sync of movements/relaxation zones (create missing, soft-delete removed, restore re-added) in one transaction (D7).
- [x] 2.6 `StimulationSessionController.php` (`$model`, `$keyForPermission = 'stimulation_session'`, `includes()`, `searchableBy()`) + `Orion::resource('stimulation_session', ...)->withSoftDeletes()` route under `['auth:sanctum', 'initializeTenancy']`. **Deviation**: snake_case URL segment (`stimulation_session`, not the hyphenated `stimulation-session` design.md example) — matches every other Orion resource route in this codebase and the identical precedent/rationale already recorded in `Modules/Screening/routes/api.php`.
- [x] 2.7 Factories for session + join tables.
- [x] 2.8 Feature tests: REQ-SS-001/002/003 scenarios verbatim (minimal create, catalog/tenant validation, future date, subject XOR, visit coherence, sync lifecycle incl. restore-not-duplicate, duplicate-pair 422, cross-tenant body zone), plus DB CHECK backstop (raw insert without subject fails). — `Modules/Stimulation/tests/Feature/StimulationSessionApiTest.php`.

## Phase 3 — Groups + members

- [x] 3.1 Migrations `..._create_stimulation_group_table.php` (partial unique `(tenant_id, name)`) and `..._create_stimulation_group_member_table.php` (partial unique `(stimulation_group_id, child_id)`), comments everywhere.
- [x] 3.2 Models `StimulationGroup.php` (relations `stimulation_group_type()`, `health_center()`, `promoter()` → User with explicit `promoter_user_id`; hasMany `members()`), `StimulationGroupMember.php` (relations `stimulation_group()`, `child()`, `default_attendance_payment_status()`).
- [x] 3.3 Requests: group create/update (name unique pre-check per tenant, promoter-is-tenant-member rule, `end_date >= start_date`, tenant-scoped exists); member create/update (same-tenant child+group, central exists for default status, inactive-group 422 per D11, `enrolled_at` server default today).
- [x] 3.4 Controllers `StimulationGroupController.php` (`$keyForPermission = 'stimulation_group'`) and `StimulationGroupMemberController.php` (`$keyForPermission = 'stimulation_group'`, D10) + Orion routes.
- [x] 3.5 Factories for group + member.
- [x] 3.6 Feature tests: REQ-SG-001/002 scenarios verbatim (name uniqueness tenant/soft-delete aware, promoter membership, date coherence, duplicate enrollment + re-enroll, cross-tenant child, inactive group). — `StimulationGroupApiTest.php`, `StimulationGroupMemberApiTest.php`.

## Phase 4 — Attendance (bulk idempotent upsert)

- [x] 4.1 Migration `..._create_stimulation_attendance_table.php`: PLAIN unique `(stimulation_group_id, child_id, session_date)` (D8 — deliberate departure from partial uniques, documented in a migration comment), index `(tenant_id, session_date)`, comments.
- [x] 4.2 Model `StimulationAttendance.php` (boolean/date casts, relations).
- [x] 4.3 Request `AttendanceUpsertRequest`: `session_date` not future and `>= group.start_date`, `entries` array with distinct `child_id`s, per-entry booleans + optional central status. **Note**: group-dependent checks (active group, `>= group.start_date`, active membership) live in the Service (design §5), not the Request, to avoid duplicating route-model resolution.
- [x] 4.4 Service `StimulationAttendanceService.php`: pipeline from design §5 — active-group guard, active-membership check for every entry (atomic 422), transaction with `withTrashed()` lookup + restore, member-default status fill, 23505 single retry via nested `DB::transaction()` savepoint (D9).
- [x] 4.5 `StimulationAttendanceController.php`: custom `POST stimulation_group/{stimulation_group}/attendance` (guard `update.stimulation_group`) returning the upserted entities; `Orion::resource('stimulation_attendance', ...)->only(['index', 'search', 'show'])` for grid reads (`$keyForPermission = 'stimulation_group'`).
- [x] 4.6 Factory + Feature tests: REQ-SA-001/002 scenarios verbatim — idempotent replay (row count + values stable), member default applied, concurrency (savepoint retry path covered by design/code review — no dedicated parallel-request test given PHPUnit's synchronous execution model), non-member aborts batch with zero writes, date bounds, inactive group, no destroy route exposed (405), tenant isolation of reads. — `StimulationAttendanceApiTest.php`.

## Phase 5 — Registration wiring (menu, permissions, selects)

- [x] 5.1 `backend/database/seeders/csv/modules.csv`: the 11 rows from design §6 (`ed000004-…0001..0011`).
- [x] 5.2 `backend/database/seeders/csv/permissions.csv`: 66 rows (6 verbs × 11 module rows), existing description style.
- [x] 5.3 `backend/database/seeders/csv/module_system.csv`: 11 rows → system `b266838b-4cb0-4eb1-aa76-242cf96df41a` with sorts/groups/icons from design §6 (Estimulación 30–31, Catálogos 45–50, hidden 51–53).
- [x] 5.4 `backend/database/seeders/SystemTableSeeder.php`: extend the maps per design §6 (permission entities incl. `update:` prefixes for the 6 tenant catalogs, routes, `$hidden` += the 3 central catalogs, icons — verified `IconHeartHandshake`/`IconUsersGroup`/`IconBodyScan`/`IconMassage`/`IconPackage`/`IconMusic` were MISSING from `frontend/src/shared/utils/iconMap.ts` and added them).
- [x] 5.5 `backend/database/seeders/RolePermissionTableSeeder.php`: append the 66 permission names to `$commonPermissions`; append `'session_section'`, `'stimulation_area'`, `'attendance_payment_status'` to `$centralCatalogs`.
- [x] 5.6 `backend/app/Http/Controllers/AngularController.php`: add the 9 `$mapperSelects` entries from design §6 (no `Child`).
- [x] 5.7 Reseed dev DB (`ResetPermissionTableSeeder` + `SystemTableSeeder`); smoke `GET /api/menu` (shows 2 pages + 6 catalogs, hides 3 central) and `POST /selects` for the 9 keys (7 non-empty; `didactic_material`/`song` empty arrays, not errors). — verified via `migrate:fresh --seed` + `tests/Feature/Stimulation/ModuleRegistrationTest.php`.
- [x] 5.8 Feature test asserting menu composition + selects contract (REQ-XC-003) and the 403 matrix (REQ-XC-002: attendance without `update.stimulation_group`, administrator on `create.stimulation_area`). — `tests/Feature/Stimulation/ModuleRegistrationTest.php`.

## Phase 6 — Frontend: sessions + groups

- [x] 6.1 `frontend/src/shared/hooks/useCatalogs.ts`: add the 9 `CatalogType` members (design §6) — keys round-trip `Str::studly` to the mapper entries (proven by `useCatalogs.test.ts`).
- [x] 6.2 `frontend/src/modules/stimulationSession/{schema.ts,services/StimulationSessionResource.ts,store/stimulationSessionStore.ts,StimulationSessionListPage.tsx,StimulationSessionFormPage.tsx}`: 4 collapsible sections, subject selector (Individual = child async-search combobox on the child list endpoint — NOT `/selects`; Grupal = `STIMULATION_GROUP` select), massage grid, free-text material/song comboboxes (HTML `<datalist>`) suggesting from catalogs, catalogs loaded on form open only, `displayFormatDate` for dates in the list, zero `any`.
- [x] 6.3 `frontend/src/modules/stimulationGroup/{schema.ts,services/StimulationGroupResource.ts,store/stimulationGroupStore.ts,StimulationGroupListPage.tsx,StimulationGroupFormModal.tsx,StimulationGroupDetailPage.tsx}`: detail tabs Datos generales / Miembros (enroll modal with child async search + default payment status select, unenroll, edit default) + link to the attendance sheet.
- [x] 6.4 Catalog pages: 6 `createSimpleCatalogPage` registrations (no `order` column, design §3) in `frontend/src/modules/catalogs/pages.tsx`.
- [x] 6.5 `frontend/src/App.tsx`: `PermissionRoute` blocks + routes `/stimulation-session`, `/stimulation-group`, `/stimulation-group/:id`, and the 6 catalog routes.

## Phase 7 — Frontend: AttendanceSheetPage

- [x] 7.1 `frontend/src/modules/stimulationGroup/AttendanceSheetPage.tsx` (route `/stimulation-group/:id/attendance`): date picker defaulting today; rows = active members; three controls per row (asistió, tarea, estatus de pago prefilled from member default); unsaved rows render defaults without writes; optimistic local update persisted per-toggle via `POST /api/stimulation_group/{id}/attendance`; on failure revert affected row + `toast.error` (sileo); `boneyard-js` skeleton; lazy catalog loading (REQ-XC-005).
- [x] 7.2 Vitest coverage: session Zod schema (subject XOR, arrays), group schema (dates), attendance sheet optimistic rollback + default rendering, `CatalogType` string round-trip (Str::studly mirror).

## Phase 8 — Verification & closure

- [x] 8.1 Backend: `./vendor/bin/pint --dirty` (clean); full suite `DB_HOST=127.0.0.1 DB_PORT=5451 DB_USERNAME=rodriguez DB_PASSWORD=password php artisan test --compact` run in SERIAL per AGENTS.md flakiness note — **770/770 green** (738 pre-existing baseline + 32 new Stimulation tests), 7976 assertions, no regressions.
- [x] 8.2 Frontend: `pnpm exec tsc -p tsconfig.app.json --noEmit` (clean), `pnpm exec vitest run` — **280/280 green** (255 baseline + 25 new), `pnpm build` green; grep confirms zero `any` in the new modules.
- [x] 8.3 `migrate:fresh --seed` from clean — green (212 permissions, all seeders ran without error); end-to-end attendance flow (create group → enroll children → upsert sheet twice → idempotent) verified via `StimulationAttendanceApiTest::test_idempotent_replay` and the manual `route:list` smoke.
- [x] 8.4 Updated `.project-context/modules.md` (Stimulation → built). Hand off to `sdd-verify` → `sdd-archive` (spec merges into `openspec/specs/early-stimulation/spec.md`).
