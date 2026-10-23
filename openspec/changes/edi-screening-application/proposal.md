# Proposal: EDI Screening Application

## Intent

The scoring engine exists and is oracle-validated (1,834/1,836), but nothing can APPLY a screening: no tables, no endpoints, no persisted result. This change delivers the applied test — persistence, API, and lifecycle — on top of the untouched engine. It makes the four domain invariants operational: server-only result, hard version pointer, data-driven rules, and auditable recomputation.

## Why now

- Next acyclic dependency: `edi-screening-ui`, `edi-followup-and-referral`, and reports all hang from a persisted `screening`.
- The engine's contract (`ScoringInput`/`ScoringResult`, `DatabaseRuleSetLoader`) was designed for this consumer; the handoff notes in `edi-scoring-engine/tasks.md` 6.4 name exactly this change.
- Every week without persistence, screenings continue in the falsifiable legacy system.

## Scope

### In Scope
- 7 tenant-scoped tables in `Modules/Screening`: `screening`, `screening_answer`, `screening_area_result`, `screening_risk_factor_answer`, `screening_finding`, `screening_score_run`, `confirmatory_test`.
- 4 central catalogs: `screening_status`, `screening_situation` (7 rows, `sis_code` preserved), `finding_type`, `confirmatory_test_result`.
- Endpoints: create draft (returns resolved item bank), batch idempotent answers, score (with the alternate-evaluation 422 contract), complete, rescore (run history, permission + reason), invalidate, read CRUD, confirmatory-test CRUD.
- Full backend module registration (menu row seeded HIDDEN until the UI change).
- Official FUA PDF document as a final SEVERABLE phase (see Risks).

### Out of Scope
- All frontend (`edi-screening-ui`), follow-up/referral workflow, reports, notifications on red results, historical import, any engine or Instrument change.
- `$mapperSelects`/`CatalogType` entries (coupled pair; deferred to the UI change).

## Capabilities

### New Capabilities
- `screening-application`: persistence and API lifecycle of an applied EDI screening — draft, answers, server-side scoring with trace, freeze, auditable rescore, confirmatory test.

### Modified Capabilities
- None. (`scoring-engine` is consumed as-is; its spec does not change.)

## Approach

The Screening module gains models/migrations/controllers around the pure engine: create resolves version, ages, and groups server-side; score builds `ScoringInput`, runs `ScoringEngine`, and persists header + per-area results + effective answers + findings + a `screening_score_run` (with `ScoringResult::toArray()` as trace) in one transaction. Recompute always uses the screening's own `instrument_version`; other-version what-ifs are non-authoritative runs. Clinic/Instrument house patterns throughout (partial uniques, no DB FKs, fixed-UUID central catalogs).

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `backend/Modules/Screening/` | Modified | Adds database/, Models, Http, routes around the existing engine |
| `backend/database/seeders/*` | Modified | Catalog rows + module registration (hidden) |
| `frontend/` | None | Zero changes; baseline 221 tests untouched |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Forged client results | Med | FormRequests accept only answers; narrow `$fillable`; mandatory `ForgedResultIsIgnoredTest` |
| Partial persistence on alternate gate | Med | Engine is pure — gate evaluates BEFORE any write; transaction covers all writes |
| Rescore silently overwriting history | Low | Every calculation is a `screening_score_run`; header updates only from authoritative runs |
| FUA PDF inflates the change | High | Final severable phase; drop to its own change if the review budget trips |

## Rollback Plan

New tables and additive seeder rows only; no existing table altered; engine untouched. Revert the PR(s); `migrate:rollback` drops the tables; re-running seeders removes menu/permission rows.

## Dependencies

- `edi-scoring-engine` delivered (engine + `DatabaseRuleSetLoader` + `SituationKey`/`ResultColorKey` constants).
- `edi-instrument-catalog` delivered (both versions seeded/published, `content_hash` present).
- `edi-clinic-and-child-registry` delivered (`visit`, `child`, `birth_record`).

## Success Criteria

- [ ] `ForgedResultIsIgnoredTest`: forged green over red-producing answers returns red.
- [ ] All-yellow area without alternate answers → 422 `ALTERNATE_ITEMS_REQUIRED`, zero rows persisted.
- [ ] `PUT /answer` on completed → 409; rescore requires permission + reason and never recomputes with another version authoritatively.
- [ ] Backend suite green from the 696 baseline; frontend untouched at 221.

## Proposal question round

Interactive round unavailable in this executor context — defaults are encoded in `design.md` §Open decisions; correct them before `sdd-apply`.

1. **Completeness gate**: score requires every own-group item answered (422 `INCOMPLETE_ANSWERS`); risk factors may stay unanswered (count zero per REQ-SCO-006). Default: yes.
2. **One screening per visit**; invalidation does NOT free the visit — redo = new visit (new folio). Default: yes.
3. **Rescore default**: `authoritative=false` unless explicitly requested. Default: yes.
4. **Previous screening basis**: latest COMPLETED screening of the child, frozen at FIRST score (not at draft creation). Default: yes.
5. **FUA PDF**: minimal official layout from stored data, not a pixel replica of the legacy form. Default: yes.
