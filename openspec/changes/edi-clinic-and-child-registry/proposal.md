# Proposal: EDI Clinic & Child Registry

## Intent

EDI cannot screen anyone yet: the platform scaffolding (tenancy, permissions, menu, billing, notifications) is green but owns zero domain tables. Every downstream module (Instrument, Screening, Stimulation, FollowUp, Reports) hangs off a child seen at a health center during a visit. This change builds that foundation: the organizational structure (`Clinic`) and the minor's record (`ChildRecord`), including the perinatal data (`birth_record`) that the scoring engine will later consume for corrected age and the three auto-derived biological risk factors (`gestational_age_weeks < 34`, `birth_weight_grams <= 1500`, `mother_age_at_birth_years < 16`).

## Why this change goes first

- Dependency chain is acyclic: `Screening → ChildRecord → Clinic`. Nothing upstream of these two exists.
- `visit` must exist before Screening because the applied test hangs from a visit.
- It exercises the full module-registration checklist once, on low-risk CRUD, before the hard modules.

## Scope

### In Scope
- Backend module `Clinic`: `health_center`, `practitioner_profile`.
- Backend module `ChildRecord`: `child`, `caregiver`, `child_caregiver`, `birth_record`, `visit`.
- Central catalogs `sex`, `population_type` (fixed UUID, `CatalogSeeder`).
- Tenant-scoped catalogs `caregiver_relationship`, `education_level`, `occupation` (`TenantCatalogSeeder`).
- Full module registration: CSVs, `SystemTableSeeder` maps, `RolePermissionTableSeeder`, `$mapperSelects`, `CatalogType`, routes.
- React modules: `healthCenter`, `practitioner`, `child`, `caregiver`, `visit` + 3 catalog pages via `createCatalogPage`.
- Privacy posture for minors' PII (LFPDPPP / NOM-024): CURP validation/uniqueness, audit without PII in logs.

### Out of Scope
- Instrument, Screening (the test itself), Stimulation, FollowUp, Reports.
- Any visit "application" logic — `visit` is a header only.
- Billing quota subject, retention/erasure workflows, CURP encryption at rest (flagged as open decisions).

## Capabilities

### New Capabilities
- `clinic-registry`: health centers and practitioner profiles of the organization.
- `child-registry`: children, caregivers, perinatal records, and visit headers.

### Modified Capabilities
- None.

## Approach

Clone the surviving `UserProfile` module pattern: `BaseModel::columns($table, true)` + `BelongsToTenant`, Orion controllers extending `QCH\Core\Http\Controllers\Controller`, partial unique indexes via `DB::statement(... WHERE deleted_at IS NULL)` (established precedent), FormRequest validation with tenant-scoped `exists` rules. Frontend uses the standard 5-layer CRUD scaffolding.

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Silent empty dropdowns (`$mapperSelects` ↔ `CatalogType` string coupling) | Med | Spec REQ + explicit wiring tasks + QA step |
| PII of minors leaking into logs | Med | Spec REQ-PRIV; no payload logging; audit via `audits` table only |
| Registration checklist step missed (menu/403) | Med | tasks.md enumerates every file; verify phase re-seeds and smoke-tests menu |

## Rollback Plan

Pre-implementation artifacts only. After apply: revert the PR(s); migrations are new tables only (`migrate:rollback` drops them); seeder entries are additive rows removable by re-running seeders after revert. No existing table is altered.

## Success Criteria
- [ ] All 8 tables migrate and seed on `migrate:fresh --seed`; both modules appear in the tenant menu with permissions enforced.
- [ ] Spec scenarios pass as Feature tests (uniqueness, isolation, CURP, primary caregiver).
- [ ] Frontend builds with zero `any`; catalog dropdowns load lazily and non-empty.

## Proposal question round

Interactive round unavailable in this executor context — answers below are ASSUMED and encoded in spec/design; correct them before `sdd-apply`.

1. **Single surname**: `last_name_maternal` is modeled NOT NULL (per the target data model). Real registrations include single-surname people — should child/caregiver `last_name_maternal` be nullable? *Assumed: required (as specified).*
2. **Unknown perinatal data**: EDI's paper form allows "se ignora". Design makes all `birth_record` clinical fields nullable (NULL = unknown; risk factors derive only from known values). *Assumed: yes, nullable.*
3. **`visit.receipt_number`**: auto-generated per-tenant `V-YYYY-NNNNNN` sequence, immutable — or manual capture of a paper folio? *Assumed: auto-generated.*
4. **CURP cross-consistency** (birth-date segment vs `birth_date`, sex char vs `sex_id`): hard 422 error or warning? *Assumed: hard error.*
5. **Menu shape**: practitioners get their own sidebar page; caregivers are managed only from the child detail (hidden module, permissions still exist). *Assumed: yes.*
