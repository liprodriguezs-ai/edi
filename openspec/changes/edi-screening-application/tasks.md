# Tasks: EDI Screening Application

Change: `edi-screening-application`

## Delivery forecast (review workload guard)

Decision needed before apply: Yes
Chained PRs recommended: Yes
400-line budget risk: High

Roughly ~2,500 authored backend lines (11 migrations, 11 models, 5 services, 2 controllers, 7 requests, seeders/registration) plus a comparable test volume. Recommended chain (each slice autonomous, verifiable, revertible): **PR1** = Phases 0–1 (catalogs + tables + models + seeders), **PR2** = Phase 2 (create draft + item bank + answers), **PR3** = Phases 3–4 (score pipeline + invariant tests), **PR4** = Phase 5 (lifecycle + confirmatory + registration + closure), **PR5 (severable)** = Phase 6 (FUA PDF) — drop PR5 to its own change if the budget trips. Resolve open decisions Q1–Q7 (`design.md` §8) before Phase 3.

All paths relative to `/Users/rodriguez/code/edi-app/`. Backend tests run from `backend/` with the host command in `openspec/config.yaml`; reproduce any parallel-flake in series before believing it (AGENTS.md §5). Do NOT create git commits from this pipeline. Do NOT modify `Modules/Screening/app/Services/Scoring/**` or `RuleSetLoading/**`.

---

## Phase 0 — Central catalogs

- [x] 0.1 Migrations for `screening_status`, `screening_situation` (with `sis_code` smallint), `finding_type`, `confirmatory_test_result` in `backend/Modules/Screening/database/migrations/` — `BaseModel::columns($table, false)`, table comment inside the closure, `->comment()` on every own column, partial unique on `key`.
- [x] 0.2 Models with fixed-UUID constants (`ed300001..4-0000-4000-8000-...`, design §3): `ScreeningStatus`, `ScreeningSituation`, `FindingType`, `ConfirmatoryTestResult`. No `BelongsToTenant`, no PHP enums.
- [x] 0.3 `CatalogSeeder`: `seedScreeningStatuses()`, `seedScreeningSituations()` (7 rows, `sis_code` 1/2/3/4/5/6/6), `seedFindingTypes()` (5 rows), `seedConfirmatoryTestResults()` (3 rows) via `upsertCatalog`.
- [x] 0.4 Feature test `CatalogAlignmentTest`: `screening_situation.key` set ≡ the 7 `SituationKey` constants AND `finding_type.key` set ≡ the 5 `ScoringFinding` keys (both directions); `sis_code` mapping exact (REQ-SCR-001).

## Phase 1 — Transactional tables + models

- [x] 1.1 Migrations per design §3 (tenant-scoped, `BaseModel::columns($table, true)`): `screening`, `screening_answer`, `screening_area_result`, `screening_risk_factor_answer`, `screening_finding`, `screening_score_run`, `confirmatory_test`. Partial uniques: `(visit_id)`, `(screening_id, screening_item_id)`, `(screening_id, development_area_id)`, `(screening_id, risk_factor_id)`, `(screening_id)` on confirmatory_test — all `WHERE deleted_at IS NULL` via `DB::statement`.
- [x] 1.2 Models with `BelongsToTenant`, casts (`context`/`input_payload`/`result_payload` → `array`, booleans, dates), snake_case single relations (`instrument_version()`, `age_group()`, `applied_age_group()`, `screening_status()`, `result_color()`, `situation()`, `visit()`, `child()`), plural collections (`answers()`, `area_results()`, `risk_factor_answers()`, `findings()`, `score_runs()`). `Screening::$fillable` = somatometry + `notes` ONLY (REQ-SCR-003).
- [x] 1.3 Verify `migrate:fresh --seed` green; run `DB_HOST=127.0.0.1 DB_PORT=5451 DB_USERNAME=rodriguez DB_PASSWORD=password php artisan test --compact Modules/Screening/tests` for Phase 0–1 tests.

## Phase 2 — Draft creation, item bank, answers

- [x] 2.1 `app/Services/RuleSetProvider.php`: memoized `DatabaseRuleSetLoader::build()` per version key (design D18), registered as singleton in the module provider.
- [x] 2.2 `app/Services/ScreeningApplicationService.php`: create draft — resolve child from visit (tenant-scoped `exists`), freeze default published `instrument_version_id`, resolve ages/groups via engine `AgeResolver`/`AgeGroupResolver` (map `ScreeningNotApplicableException` → 422 `SCREENING_NOT_APPLICABLE`), store both group FKs + ages, display-resolve previous screening (D16), status `draft`. Item bank builder: own + alternate items per area, options WITHOUT colors, locked flags + structured `locked_reason` from known-plausible `birth_record`/somatometry inputs (D12/D13).
- [x] 2.3 `app/Services/ScreeningAnswerService.php`: batch upsert with option→color snapshot (D4), membership validation (applied ∪ alternate group; version-scoped risk factors), idempotency, staleness reset (D14), frozen 409s. `lockForUpdate` + transaction (D19).
- [x] 2.4 `ScreeningController` (extends `QCH\Core\Http\Controllers\Controller`, `$model`, `$keyForPermission = 'screening'`, `includes()` allowlist, `searchableBy()`), FormRequests `ScreeningCreateRequest`/`ScreeningUpdateRequest` (somatometry plausibility ranges D12, Spanish messages)/`ScreeningAnswerRequest` (ONLY `answers[]` + `risk_factor_answers[]`), routes per design §4: Orion `only(['index','show','update'])` + `POST screening` + `GET {id}/items` + `PUT {id}/answer`.
- [x] 2.5 Feature tests: `ScreeningCreateTest` (REQ-SCR-002 scenarios incl. substitution and not-applicable-persists-nothing), `ScreeningAnswerTest` (REQ-SCR-004: idempotent replay, offending-id 422s, staleness reset, no-op keeps `scored`), item-bank shape + locked flags (REQ-SCR-007 bank half).

## Phase 3 — Score pipeline (the invariant core)

- [x] 3.1 `app/Services/ScreeningScoreService.php`: pipeline per design §5 — `ENGINE_VERSION` const, lock + status guard, completeness gate (Q1), alternate gate with missing-items payload (D11), input build (`forDates` initial with group tripwire / `forGroup` rescore, D6), perinatal plausibility filter (D12), previous-color freeze at first score (D16), engine run, ONE-transaction persist (header + area results + effective answers with `is_auto_derived` + FRB answers + findings + authoritative `screening_score_run` with `input_payload`/`result_payload`), `rules_fingerprint` copy (D9).
- [x] 3.2 Wire `POST screening/{id}/score`; map engine exceptions to typed 4xx envelopes (codes per design §4).
- [x] 3.3 Feature test `ForgedResultIsIgnoredTest` (MANDATORY, this exact name): forged `result_color_id`/`situation_id`/counters/`scored_at` across create+answer+score payloads over red-producing answers → stored and returned result is red (REQ-SCR-003).
- [x] 3.4 Feature test `ScreeningScoreTest`: gate ordering (`INCOMPLETE_ANSWERS` before alternate), alternate 422 with ZERO persisted artifacts (REQ-SCR-005), answered-alternates proceed with Model A pooling, transactional persist + trace fidelity (header counters ≡ `result_payload`), determinism on repeat, engine-failure persists nothing.
- [x] 3.5 Feature tests `AutoDerivedAnswerTest` (override + `auto_answer_overrode_manual` finding + `is_auto_derived` row; D-14 NULL stays manual + `unknown_perinatal_input`) and `PlausibilityTest` (garbage `birth_weight_grams = 10` → NULL semantics, no FRB4 derivation; 422 on implausible somatometry capture) (REQ-SCR-007/008).

## Phase 4 — Situation persistence

- [x] 4.1 Map `SituationOutcome.key` → `screening_situation` row in the score persist; expose `sis_code` through the model/read payload (REQ-SCR-012).
- [x] 4.2 Feature test `SituationPersistenceTest`: first-time keys; worsened vs unchanged distinct rows sharing `sis_code = 6`; previous frozen at first score (sibling completes between create and score); rescore reuses frozen previous; invalidated screenings excluded from history.

## Phase 5 — Lifecycle, confirmatory test, registration

- [x] 5.1 `app/Services/ScreeningLifecycleService.php`: `complete` (scored→completed, `completed_at`, 409 matrix), `rescore` (permission `rescore.screening`, mandatory `reason`, `authoritative` default false, version pinning `VERSION_PINNED`, fingerprint check `RULES_FINGERPRINT_MISMATCH`, header update ONLY from authoritative runs, non-auth simulation with any published version key), `invalidate` (permission `delete.screening`, `invalidation_reason`, terminal freeze) — D8/D9/D15. Wire `POST {id}/complete|rescore|invalidate` + `ScreeningRescoreRequest`/`ScreeningInvalidateRequest`.
- [x] 5.2 `ConfirmatoryTestController` + requests + `Orion::resource('confirmatory-test', ...)->only(['index','show','store','update'])`: red + `completed` gate (`CONFIRMATORY_NOT_APPLICABLE`), one per screening (REQ-SCR-014).
- [x] 5.3 Registration per design §6: `modules.csv` (2 rows `ed000003-…0001/0002`), `permissions.csv` (13 rows incl. `rescore.screening`), `module_system.csv` (Evaluación, sorts 23–24), `SystemTableSeeder` maps (entities/routes/icons; BOTH rows in `$hidden`), `RolePermissionTableSeeder` (13 → `$commonPermissions`; 4 catalog entities → `$centralCatalogs`). NO `$mapperSelects`/`CatalogType` — leave the pointer comment. Run `php artisan db:seed --class=ResetPermissionTableSeeder`.
- [x] 5.4 Feature tests: `ScreeningLifecycleTest` (freeze 409s, rescore permission/reason/pinning/fingerprint, non-auth leaves header byte-identical, invalidate terminal), `ConfirmatoryTestTest`, `TenantIsolationTest` (cross-tenant 404 on read/mutate; foreign visit/child rejected), `MenuHiddenTest` (`GET /api/menu` unchanged for tenant roles; 403 without permissions).
- [x] 5.5 Closure: `./vendor/bin/pint --dirty` clean; full suite `DB_HOST=127.0.0.1 DB_PORT=5451 DB_USERNAME=rodriguez DB_PASSWORD=password php artisan test --parallel --processes=10` green from the 696 baseline; `git status` shows ZERO frontend changes (221 stays); update `.project-context/modules.md` (Screening now owns tables + API; UI pending) and note the `$mapperSelects`/`CatalogType` + unhide handoff for `edi-screening-ui`.

## Phase 6 — FUA PDF (SEVERABLE — may ship as its own PR/change)

- [x] 6.1 `GET /api/screening/{id}/document`: dompdf render from STORED data only (header, child identity, somatometry, per-area colors + counts, counters, global message + referral bullets, situation, folio/center/practitioner), statuses `scored`/`completed` only, permission `read.screening` (REQ-SCR-015). No engine invocation.
- [x] 6.2 Feature test: 409 on draft; PDF content-type + key stored values present; renders without recomputation (assert no RuleSet build in the request path).
- [x] 6.3 DELIVERED after all (2026-08-12), in this same change rather than a follow-up: `GET /api/screening/{id}/document` renders the FUA from stored data via dompdf, 409 on draft, `read.screening`. REQ-SCR-015 DOES merge to `openspec/specs/` on archive. All other REQ-SCR-* (001-014) are fully implemented, tested, and verified in this change.
