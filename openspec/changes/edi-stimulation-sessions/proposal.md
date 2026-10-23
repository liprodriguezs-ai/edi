# Proposal: EDI Stimulation Sessions

## Intent

Early stimulation ("interacción oportuna") is the product's second scope beside the EDI screening: a child with a yellow or red result is referred to stimulation sessions, and open groups run with attendance lists. Today nothing exists — no tables, endpoints, or UI. The legacy system modeled ONE fixed 4-section form as a generic form engine (`document → document_section → document_question → document_question_options`); this change delivers the domain as concrete entities instead.

## Why now

- Upstream slices are delivered: Clinic/ChildRecord (`child`, `visit`, `practitioner_profile`, `health_center`), Instrument, Screening.
- `edi-followup-and-referral` will wire "red result → referral" and needs sessions/groups to point at.
- Acyclic: Stimulation depends only on delivered modules.

## Scope

### In Scope
- Backend module `Stimulation`: `stimulation_session` (+ `stimulation_session_movement`, `stimulation_session_relaxation_zone`), `stimulation_group`, `stimulation_group_member`, `stimulation_attendance`.
- Central catalogs `session_section`, `stimulation_area`, `attendance_payment_status`; tenant catalogs `stimulation_dynamic`, `body_zone`, `massage_movement`, `didactic_material`, `song`, `stimulation_group_type`.
- Bulk idempotent endpoint `POST /api/stimulation-group/{id}/attendance`.
- Full module registration (CSVs, `SystemTableSeeder` maps, `RolePermissionTableSeeder`, `$mapperSelects`, `CatalogType`, `App.tsx` routes).
- React modules `stimulationSession`, `stimulationGroup` (shared CRUD scaffolding) + `AttendanceSheetPage` (children × date grid, optimistic save) + 6 catalog pages.

### Out of Scope
- Automatic "yellow/red result → referral" linkage (`edi-followup-and-referral`); sessions are registered manually here.
- Reports/exports (`REPORT_MODEL_MAP`), notifications, printable session sheets.
- Any change to Screening, scoring engine, Instrument, Clinic, ChildRecord.

## Capabilities

### New Capabilities
- `early-stimulation`: individual and group stimulation sessions (4-section record), stimulation groups with enrollment, attendance tracking.

### Modified Capabilities
- None.

## Approach

Model the session as a concrete domain entity: the four official sections (integración, preparación, actividad central, relajación) are column groups on `stimulation_session`; the only multi-valued data (massage body-zone × movement pairs, relaxation zones) are two narrow join tables synced from the session payload. House patterns throughout: `BaseModel::columns($table, true)`, partial uniques via `DB::statement`, no DB FKs, tenant-scoped `exists`, Orion controllers, fixed-UUID central catalogs. Attendance is not per-row CRUD: one bulk upsert keyed by (group, child, date).

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `backend/Modules/Stimulation/` | New | 6 transactional tables + 9 catalogs, services, Orion controllers |
| `backend/database/seeders/*` | Modified | Catalog seeds + module registration rows |
| `backend/app/Http/Controllers/AngularController.php` | Modified | 9 `$mapperSelects` entries |
| `frontend/src/modules/{stimulationSession,stimulationGroup}/` | New | CRUD + AttendanceSheetPage |
| `frontend/src/{App.tsx, shared/hooks/useCatalogs.ts, modules/catalogs/pages.tsx}` | Modified | Routes, CatalogType, catalog pages |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Rebuilding the legacy form engine | Low | Design D1 fixes concrete-entity modeling; spec forbids `document*`-style tables |
| Silent empty dropdowns (9 coupled selects entries) | Med | Explicit wiring tasks + selects-contract test |
| Attendance double-submit / races | Med | Plain natural-key unique + idempotent upsert + concurrency test |
| Review budget (11 module rows, both stacks) | High | Chained PRs per tasks.md forecast |

## Rollback Plan

New tables and additive seeder rows only; no existing table altered. Revert the PR(s); `migrate:rollback` drops the tables; re-running seeders removes menu/permission rows.

## Dependencies

- `edi-clinic-and-child-registry` delivered (`child`, `visit`, `health_center`, `practitioner_profile`).
- Screening is context only — no FK from this change references it.

## Success Criteria

- [ ] `migrate:fresh --seed` green; suites green from baselines (backend 737, frontend 221) plus new tests.
- [ ] Attendance upsert proven idempotent (same payload twice → same rows) and membership-guarded.
- [ ] Menu shows both pages + 6 catalog pages; selects contract holds for all 9 entries.
- [ ] Zero `any`; no eager catalog loading.

## Proposal question round

Interactive round unavailable in this executor context — answers below are ASSUMED and encoded in spec/design; correct them before `sdd-apply`.

1. **Session subject**: exactly one of `child_id`/`stimulation_group_id` at API level; DB CHECK stays at-least-one. *Assumed: exactly one.*
2. **Material/song columns**: free-text snapshots; `didactic_material`/`song` catalogs feed combobox suggestions only. *Assumed: yes.*
3. **`session_section`**: no FK consumer in v1 — seeded as the official section vocabulary (rendering/export labels). *Assumed: keep (mandated by brief).*
4. **`stimulation_group_type`** seeds one default row ("Grupo abierto") so group creation is not blocked by an empty required catalog. *Assumed: yes.*
5. **`attended`/`homework_done`**: NOT NULL default false (no "not recorded" tri-state). *Assumed: yes.*
