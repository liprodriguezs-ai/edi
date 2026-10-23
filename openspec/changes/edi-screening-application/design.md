# Design: EDI Screening Application

Change: `edi-screening-application`

## 1. Context

The `Screening` module exists as a code-only skeleton: the pure `ScoringEngine` (oracle-gated at 1,834/1,836), `DatabaseRuleSetLoader::build(string $versionKey): RuleSet`, `ScoringInput::forGroup()/forDates()`, `ScoringResult::toArray()` (persistable trace, no UUIDs), and the `SituationKey`/`ResultColorKey`/`ScoringFinding` constants. This change adds persistence and API around it. The engine and its oracle harness are FROZEN — nothing here modifies `app/Services/Scoring/` or `RuleSetLoading/`. House patterns from `edi-clinic-and-child-registry` (tenant tables, partial uniques, no DB FKs, FormRequest tenant-scoped `exists`) and `edi-instrument-catalog` (fixed-UUID central catalogs) apply throughout.

## 2. Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | All 7 transactional tables tenant-scoped; the 4 new catalogs CENTRAL with fixed UUIDs (`ed300001..4-0000-4000-8000-...`), seeded by `CatalogSeeder::upsertCatalog` | Code branches on status/situation/finding/confirmatory rows (FSM + export + rendering) → central per hard rule 4. Scoring design D11 explicitly deferred the situation catalog to this change, mapping engine keys → rows |
| D2 | `screening` carries BOTH `age_group_id` (chronological) and `applied_age_group_id` (post corrected-age substitution) | The clinical record must show the substitution, and recompute (D6) needs the applied group without re-resolving dates (engine D5) |
| D3 | `instrument_version_id` frozen at create to the published `is_default` version; NO endpoint can write it afterwards | Domain invariant 2. Version migration is prohibited; cross-version curiosity goes through non-authoritative runs (D8) |
| D4 | Answers are persisted with a server-resolved `result_color_id` snapshot from the selected option; the client payload carries only ids | Invariant 1 at row level: the color column exists for reads/reports and is never writable. The engine re-resolves colors from the RuleSet at score time — divergence is impossible while both read the same seeded content |
| D5 | Result assignment is service-only: narrow `$fillable` (somatometry + `notes`) + whitelisting FormRequests + `forceFill` in services for computed fields | Three independent layers must all fail for a forged result to land; `ForgedResultIsIgnoredTest` proves the stack end-to-end |
| D6 | Initial score builds `ScoringInput::forDates` (full trace incl. ages) with a tripwire `resolved applied group == stored applied_age_group_id`; every rescore builds `forGroup(stored applied group)` | Engine D5: recomputing a stored screening must reuse ITS group, never re-resolve dates. The initial `forDates` run re-proves the create-time resolution deterministically |
| D7 | Score persistence is ONE transaction: header + `screening_area_result` + effective answers + FRB answers + findings + one authoritative `screening_score_run` (`result_payload` = `ScoringResult::toArray()` verbatim, `input_payload` = natural-key input serialization) | The trace is the clinical explanation (REQ-SCO-009); persisting it verbatim keeps the engine the single source of truth. Natural-key input makes runs replayable across versions and environments |
| D8 | `screening_score_run.instrument_version_id` MUST equal the screening's version when `is_authoritative = true`; MAY be any published version when false. Header updates come exclusively from authoritative runs | Invariant 5: recompute always with the screening's own rules; "what would the new rules say" is a recorded simulation, never a mutation |
| D9 | `rules_fingerprint` = `instrument_version.content_hash` copied at score time; authoritative rescore verifies stored fingerprint == current hash (409 on mismatch) | Defense in depth over the Instrument immutability observer: a tampered rulebook can never silently re-emit a result under the old identity |
| D10 | `ENGINE_VERSION` is a constant on `ScreeningScoreService` (starts `'1.0.0'`), recorded on header and every run — NOT on the engine | The engine is frozen (no touches); the consumer owns the audit tag. Bump on any engine-behavior release |
| D11 | Alternate gate (REQ-SCR-005) and completeness gate run BEFORE any write, ordered: `INCOMPLETE_ANSWERS` first, then `ALTERNATE_ITEMS_REQUIRED`. The trigger is: development axis, all own items answered, all yellow, alternate area items not all answered | The engine is pure — evaluating it costs nothing and writes nothing, so "no partial score persisted" is by construction. Trigger transcribes instrument §13's descriptive finding (own-item set all yellow), scoring stays Model A pooling |
| D12 | Plausibility filter when building `ScoringInput` from `birth_record`: gestational 20–44 weeks, weight 400–7000 g, mother age 10–60 y; screening somatometry FormRequest ranges: weight 1500–40000 g, height 350–1400 mm, head circumference 250–700 mm. Out-of-range stored input → passed as NULL | The oracle's source data holds 319/1,483 impossible birth weights (10 g–33 kg, `_source/README.md`): garbage must become "se ignora" (finding), never a derived "no"/zero (clinic D-14). Ranges mirror `birth_record`'s own validation |
| D13 | Item bank (create response + `GET /items`): own + alternate items per area, options WITHOUT colors; derivable items flagged locked with structured `locked_reason` only when the source value is known-plausible | Colors client-side would let the UI precompute results (and re-teach the legacy habit). Locked-when-known/answerable-when-unknown is D-14 verbatim; explanations are structured keys so UI owns the Spanish copy |
| D14 | Staleness rule: any effective input mutation (answer change or somatometry change) while `scored` reverts status to `draft` and clears header result fields; runs are never deleted. Idempotent replays do not trigger it | A visible result must always correspond to the current inputs; history lives in `screening_score_run`, so clearing the header loses nothing |
| D15 | FSM: `draft → scored` (score), `scored → draft` (D14), `scored → completed` (complete, sets `completed_at`), any non-invalidated `→ invalidated` (permission `delete.screening` + reason). `completed` freezes everything except rescore/invalidate/reads. Orion `destroy` NOT exposed | The status catalog must have no unreachable rows; invalidate is the auditable retirement path for a clinical record — soft-deleting a screening would silently rewrite history |
| D16 | Previous screening resolved at draft creation for DISPLAY, then re-resolved and FROZEN at FIRST authoritative score (`previous_screening_id`, `previous_result_color_id`); rescores reuse the frozen color. Basis: latest `completed`, non-invalidated screening of the child with earlier `visit.visit_date` (tie: `scored_at`) | Situation must reflect the truth at scoring time (a sibling screening can complete while this one is a draft), then stay deterministic forever. Only `completed` records are clinical history |
| D17 | One screening per visit (partial unique on `visit_id`); invalidation does not free the visit — a redo is a new visit with a new folio | The folio trail shows both attempts; a predicate excluding invalidated rows would rest on a magic UUID in an index. Re-application same day = new visit row, which is honest |
| D18 | `RuleSetProvider` memoizes `DatabaseRuleSetLoader::build()` per version key (array cache on the singleton) | RuleSet construction reads ~1,700 rows; per-request rebuilds would dominate score latency. RuleSet is immutable → safe under Octane workers |
| D19 | Concurrency: `score`, `rescore`, `complete`, `invalidate`, and the answer batch open with `lockForUpdate` on the screening row inside their transaction; status guards re-check after the lock | Two concurrent scores (or answer-vs-score races) must serialize; the loser re-reads a changed status and gets its 409/422 instead of interleaved writes |
| D20 | `confirmatory_test`: one per screening (partial unique), only for red `completed` screenings, result nullable until known (`confirmatory_test_result` central) | Domain: Battelle follows a red result. The follow-up change owns prompting/referral; this change owns the record |
| D21 | Registration: 2 module rows (`Tamizajes`, `Pruebas Confirmatorias`) BOTH seeded hidden; 12 CRUD permission rows + a 13th `rescore.screening`; NO `$mapperSelects`/`CatalogType` entries | No frontend ships here — a visible menu row would 404 in the SPA. Permissions must exist anyway to gate the API. The selects pair is string-coupled to the frontend hook and travels atomically with `edi-screening-ui` |
| D22 | FUA PDF (REQ-SCR-015) is the LAST phase, severable to its own PR/change; renders stored data only, via the installed dompdf | The core lifecycle must not wait on document layout review; rendering from storage keeps it a pure projection |

## 3. Data model (definitive)

All transactional tables: `$table->comment('...')` first line inside the closure, then `BaseModel::columns($table, true)`; every own column `->comment()`; FK-style columns `uuid(...)->index()`, no DB FKs; partial uniques via `DB::statement(... WHERE deleted_at IS NULL)`.

**`screening`** — 'Applied EDI screening: header, frozen version pointer, server-computed result'

| Column | Type | Constraints | Comment |
|---|---|---|---|
| `visit_id` | uuid, index | NOT NULL | FK to visit (one screening per visit, D17) |
| `child_id` | uuid, index | NOT NULL | FK to child (denormalized from visit for history queries) |
| `instrument_version_id` | uuid, index | NOT NULL | Hard pointer to the version whose rules scored this test; immutable after create |
| `age_group_id` | uuid, index | NOT NULL | Chronological age group (FK to age_group) |
| `applied_age_group_id` | uuid, index | NOT NULL | Group actually applied after corrected-age substitution |
| `chronological_age_days` | smallInteger | NOT NULL | Chronological age in days at visit_date |
| `corrected_age_days` | smallInteger | nullable | Corrected age in days (prematurity); NULL when not applicable |
| `weight_grams` | integer | nullable | Somatometry at test: weight in grams (minor units) |
| `height_mm` | smallInteger | nullable | Somatometry at test: height/length in millimeters |
| `head_circumference_mm` | smallInteger | nullable | Somatometry at test: head circumference in millimeters; feeds the EN auto-derived item |
| `screening_status_id` | uuid, index | NOT NULL | FK to central screening_status (FSM D15) |
| `result_color_id` | uuid, index | nullable | Global semaphore; engine output only, never client-writable |
| `global_result_message_id` | uuid, index | nullable | FK to global_result_message shown for the result |
| `situation_id` | uuid, index | nullable | FK to central screening_situation (D16) |
| `alert_sign_count` | smallInteger | nullable | Alert signs (ALE) counter from the trace |
| `risk_factor_count` | smallInteger | nullable | Risk factors (FRB) counter from the trace |
| `previous_screening_id` | uuid, index | nullable | Frozen previous screening at first score (D16) |
| `previous_result_color_id` | uuid | nullable | Frozen previous global color; situation input across rescores |
| `engine_version` | string(20) | nullable | ScoringEngine consumer version that produced the header result (D10) |
| `rules_fingerprint` | char(64) | nullable | instrument_version.content_hash at score time (D9) |
| `scored_at` | timestamp | nullable | First authoritative score moment |
| `completed_at` | timestamp | nullable | Freeze moment |
| `invalidation_reason` | text | nullable | Mandatory reason captured on invalidate |
| `notes` | text | nullable | Clinical/administrative notes of the application |

Indexes: `UNIQUE (visit_id) WHERE deleted_at IS NULL`; `$table->index(['tenant_id', 'child_id'])`; `$table->index(['tenant_id', 'screening_status_id'])`.

**`screening_answer`** — 'Answer of one item in one screening; color resolved server-side'
`screening_id` uuid idx NOT NULL · `screening_item_id` uuid idx NOT NULL · `screening_item_option_id` uuid NOT NULL · `result_color_id` uuid NOT NULL (snapshot, D4) · `is_auto_derived` boolean NOT NULL default false.
`UNIQUE (screening_id, screening_item_id) WHERE deleted_at IS NULL`.

**`screening_area_result`** — 'Per-area engine result with pooled counts and applied thresholds'
`screening_id` uuid idx NOT NULL · `development_area_id` uuid idx NOT NULL · `result_color_id` uuid NOT NULL · `red_count`/`yellow_count`/`green_count` smallint NOT NULL · `answered_own_count`/`answered_alternate_count` smallint NOT NULL · `red_threshold` smallint NOT NULL · `green_threshold` smallint nullable · `allows_yellow` boolean NOT NULL.
`UNIQUE (screening_id, development_area_id) WHERE deleted_at IS NULL`.

**`screening_risk_factor_answer`** — 'FRB answer (manual or auto-derived) of one screening'
`screening_id` uuid idx NOT NULL · `risk_factor_id` uuid idx NOT NULL · `is_present` boolean NOT NULL · `is_auto_derived` boolean NOT NULL default false.
`UNIQUE (screening_id, risk_factor_id) WHERE deleted_at IS NULL`.

**`screening_finding`** — 'Clinical trace finding from the engine (closed vocabulary)'
`screening_id` uuid idx NOT NULL · `finding_type_id` uuid idx NOT NULL · `context` jsonb nullable (typed context array from the trace).
Index `(screening_id)`.

**`screening_score_run`** — 'Immutable history of every engine execution over a screening'
`screening_id` uuid idx NOT NULL · `instrument_version_id` uuid idx NOT NULL (== screening's when authoritative, D8) · `is_authoritative` boolean NOT NULL · `trigger` string NOT NULL ('initial'\|'rescore'\|'simulation') · `reason` text nullable (required by request rules for rescore/simulation) · `engine_version` string(20) NOT NULL · `rules_fingerprint` char(64) NOT NULL · `input_payload` jsonb NOT NULL (natural-key ScoringInput serialization) · `result_payload` jsonb NOT NULL (`ScoringResult::toArray()`) · `result_color_id` uuid NOT NULL (computed global color of this run).
Index `(screening_id, created_at)`. Rows are never updated or deleted.

**`confirmatory_test`** — 'Confirmatory test (e.g. Battelle) after a red result'
`screening_id` uuid idx NOT NULL · `child_id` uuid idx NOT NULL (denormalized) · `test_name` string NOT NULL (UI data, e.g. 'Battelle') · `applied_at` date nullable · `confirmatory_test_result_id` uuid idx nullable · `notes` text nullable.
`UNIQUE (screening_id) WHERE deleted_at IS NULL`.

### Central catalogs (fixed UUIDs, `CatalogSeeder::upsertCatalog`)

| Table | Rows | Columns beyond base | Constants prefix |
|---|---|---|---|
| `screening_status` | 4 | `key` unique (draft/scored/completed/invalidated), `name` (Borrador/Calificado/Completado/Invalidado), `sort_order` | `ScreeningStatus::DRAFT = ed300001-0000-4000-8000-000000000001` … `INVALIDATED = …0004` |
| `screening_situation` | 7 | `key` unique (= `SituationKey` constants), `name` (Spanish UI), `sis_code` smallint (1/2/3/4/5/6/6), `sort_order` | `ScreeningSituation::FIRST_TIME_GREEN = ed300002-…0001` … `WORSENED = …0007` |
| `finding_type` | 5 | `key` unique (= `ScoringFinding` keys), `name` (Spanish UI), `sort_order` | `FindingType::AGE_GROUP_SUBSTITUTED… = ed300003-…0001` … |
| `confirmatory_test_result` | 3 | `key` unique (delay_confirmed/delay_ruled_out/inconclusive), `name` (Retraso confirmado/Retraso descartado/No concluyente), `sort_order` | `ConfirmatoryTestResult::DELAY_CONFIRMED = ed300004-…0001` … |

A Feature test asserts `screening_situation.key` ≡ the 7 `SituationKey` constants and `finding_type.key` ≡ the 5 `ScoringFinding` keys (drift tripwire in BOTH directions).

## 4. Backend surface

```
Modules/Screening/app/
├── Models/            Screening, ScreeningAnswer, ScreeningAreaResult, ScreeningRiskFactorAnswer,
│                      ScreeningFinding, ScreeningScoreRun, ConfirmatoryTest,
│                      ScreeningStatus, ScreeningSituation, FindingType, ConfirmatoryTestResult
├── Http/Controllers/  ScreeningController (Orion + custom actions), ConfirmatoryTestController
├── Http/Requests/     ScreeningCreateRequest, ScreeningUpdateRequest, ScreeningAnswerRequest,
│                      ScreeningRescoreRequest, ScreeningInvalidateRequest, ConfirmatoryTest{Create,Update}Request
└── Services/
    ├── RuleSetProvider.php            # memoized DatabaseRuleSetLoader (D18)
    ├── ScreeningApplicationService.php# create draft, resolve version/ages/groups/previous-display, item bank
    ├── ScreeningAnswerService.php     # batch upsert, validation, staleness reset (D14)
    ├── ScreeningScoreService.php      # gates, input build (D6/D12), engine run, transactional persist (D7), ENGINE_VERSION
    └── ScreeningLifecycleService.php  # complete, rescore (D8/D9), invalidate (D15)
```

Routes (`Modules/Screening/routes/api.php`, under `['auth:sanctum','initializeTenancy']` + `throttle:api`):

| Route | Action | Guard |
|---|---|---|
| `Orion::resource('screening', ...)->only(['index','show','update'])` | read + somatometry/notes update | `$keyForPermission = 'screening'` |
| `POST screening` (Orion store, custom response) | create draft + item bank | `create.screening` |
| `GET screening/{id}/items` | item bank re-fetch (resume, post-422) | `read.screening` |
| `PUT screening/{id}/answer` | batch idempotent answers | `update.screening` |
| `POST screening/{id}/score` | gates + engine + persist | `update.screening` |
| `POST screening/{id}/complete` | freeze | `update.screening` |
| `POST screening/{id}/rescore` | run history / authoritative update | `rescore.screening` |
| `POST screening/{id}/invalidate` | retire with reason | `delete.screening` |
| `GET screening/{id}/document` | FUA PDF (phase 6, severable) | `read.screening` |
| `Orion::resource('confirmatory-test', ...)->only(['index','show','store','update'])` | confirmatory capture | `$keyForPermission = 'confirmatory_test'` |

Domain error codes: `SCREENING_NOT_APPLICABLE`, `VISIT_ALREADY_SCREENED`, `INCOMPLETE_ANSWERS`, `ALTERNATE_ITEMS_REQUIRED`, `SCREENING_FROZEN` (409), `INVALID_STATUS_TRANSITION` (409), `VERSION_PINNED`, `RULES_FINGERPRINT_MISMATCH` (409), `CONFIRMATORY_NOT_APPLICABLE`. Messages in Spanish (UI data), codes stable for the UI change.

## 5. Score pipeline (authoritative path)

```
1. lockForUpdate(screening) + status guard (draft|scored)                (D19)
2. Completeness gate: all own-group items answered, else 422 INCOMPLETE_ANSWERS
3. Alternate gate: all-yellow development axes need full alternate area answers,
   else 422 ALTERNATE_ITEMS_REQUIRED (+ missing items payload)           (D11)
4. Build ScoringInput: forDates (initial, tripwire) | forGroup (rescore) (D6)
   — perinatal from birth_record through the plausibility filter         (D12)
   — previous color: frozen, or resolved now on first score              (D16)
5. engine->score(input, RuleSetProvider->for(screening version))
6. Transaction: header + area results + effective answers (+is_auto_derived)
   + FRB answers + findings + authoritative run; status → scored         (D7)
```

## 6. Module registration (exact wiring)

- `modules.csv`: `ed000003-0000-4000-8000-000000000001,Tamizajes,Evaluación` · `ed000003-0000-4000-8000-000000000002,Pruebas Confirmatorias,Evaluación`.
- `permissions.csv`: 6 rows per module (entities `screening`, `confirmatory_test`) + 1 extra row `rescore.screening` = 13 rows, existing description style.
- `module_system.csv`: both rows → Evaluación system `b266838b-4cb0-4eb1-aa76-242cf96df41a` (sort 23 and 24, group Expediente).
- `SystemTableSeeder`: `seedModulePermissionEntities()` `'Tamizajes' => 'screening'`, `'Pruebas Confirmatorias' => 'confirmatory_test'`; `seedModuleRoutes()` `'Tamizajes' => '/screening'`; `seedModuleVisibility()` — BOTH in `$hidden` (D21; `edi-screening-ui` unhides Tamizajes); `seedModuleIcons()` `'Tamizajes' => 'IconReportMedical'`, `'Pruebas Confirmatorias' => 'IconChecklist'` (icon existence in `iconMap.ts` is settled by the UI change — hidden rows render nowhere meanwhile).
- `RolePermissionTableSeeder`: append the 13 permission names to `$commonPermissions`; append the 4 catalog entities to `$centralCatalogs` (mutations become super-admin exclusives).
- `CatalogSeeder`: `seedScreeningStatuses()`, `seedScreeningSituations()`, `seedFindingTypes()`, `seedConfirmatoryTestResults()`.
- NO `AngularController::$mapperSelects` / `useCatalogs.ts::CatalogType` entries (D21) — leave a code comment pointing to `edi-screening-ui`.
- `modules_statuses.json`: unchanged (`Screening` already true).

## 7. Testing plan

| Layer | Coverage |
|---|---|
| Feature (`Modules/Screening/tests/Feature/`) | `ScreeningCreateTest` (resolution, substitution, not-applicable, visit uniqueness, item bank shape + locked flags), `ForgedResultIsIgnoredTest` (REQ-SCR-003 — the named mandatory test), `ScreeningAnswerTest` (idempotency, validation, staleness reset, frozen 409), `ScreeningScoreTest` (gates order, alternate 422 with zero persistence, transactional persist, determinism, trace fidelity vs `screening_score_run`), `AutoDerivedAnswerTest` (locked markers, override + finding, D-14 NULL), `PlausibilityTest` (garbage birth weight → NULL semantics; 422 somatometry), `ScreeningLifecycleTest` (complete freeze, rescore permission/reason/version-pinning/fingerprint, invalidate, FSM matrix), `SituationPersistenceTest` (frozen previous, sis_code exposure, invalidated exclusion), `ConfirmatoryTestTest`, `CatalogAlignmentTest` (keys ≡ engine constants), `TenantIsolationTest`, `MenuHiddenTest` |
| Feature (registration) | permissions 403 matrix, `GET /api/menu` unchanged for tenant roles |
| Unit | request rule objects (plausibility ranges), input serializer for `input_payload` |

Backend baseline 696 tests must stay green; frontend untouched at 221.

## 8. Open decisions

| Q | Question | Default encoded in this design |
|---|---|---|
| Q1 | Completeness gate strictness — may a screening score with unanswered own items (engine tolerates, legacy allowed gaps)? | No: 422 `INCOMPLETE_ANSWERS`; FRB may stay unanswered (counts zero). Relax later via a config flag if field reality demands it |
| Q2 | One screening per visit with invalidation not freeing the visit (D17) | Yes — redo = new visit/folio; revisit only with field feedback |
| Q3 | Rescore default `authoritative = false` | Yes — mutations must be opt-in and explicit |
| Q4 | Previous-screening freeze at FIRST score, `completed`-only basis (D16) | Yes; drafts/scored/invalidated never count as history |
| Q5 | Somatometry plausibility ranges (D12) | As listed; confirm against clinical guidance before apply |
| Q6 | FUA document layout scope | Minimal official layout from stored data; pixel fidelity to the legacy form only if the checklist demands it — severable phase either way |
| Q7 | Should `rescore.screening` be restricted to admin roles instead of `$commonPermissions`? | Common for now (tenant roles receive it); tighten in a permissions pass with product input |

## 9. Explicitly unchanged

`Modules/Screening/app/Services/Scoring/**` and `RuleSetLoading/**` (engine + loaders + purity/oracle tests), all Instrument tables/content/seeds/verify, all Clinic/ChildRecord tables, `TenantCatalogSeeder::CATALOGS`, quota binding, `REPORT_MODEL_MAP`, notification resolvers, `$mapperSelects`/`CatalogType`, every frontend file. No `alter` migration touches an existing table.
