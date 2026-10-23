# Spec: EDI Screening Application

Change: `edi-screening-application`
Capabilities: `screening-application` (new)

> RFC 2119 keywords (MUST, MUST NOT, SHALL, SHOULD, MAY) are normative.
> Color keys are `green` | `yellow` | `red` (`result_color` catalog); severity green < yellow < red.
> "Engine" = `Modules\Screening\Services\Scoring\ScoringEngine` (REQ-SCO-001..012, delivered, MUST NOT be modified).
> "Development axis" = `development_area.is_development_axis = true` (MG, MF, LE, SO, CO).
> Error envelopes for domain errors: `{ "message": string (Spanish UI), "code": UPPER_SNAKE constant, ...context }`.
> On archive, REQ-SCR-* merge into `openspec/specs/screening-application/spec.md`.

---

## ADDED Requirements — capability `screening-application`

### REQ-SCR-001 — Persistence model and catalogs

The system SHALL create 7 tenant-scoped tables (`BaseModel::columns($table, true)` + `BelongsToTenant`): `screening`, `screening_answer`, `screening_area_result`, `screening_risk_factor_answer`, `screening_finding`, `screening_score_run`, `confirmatory_test`; and 4 CENTRAL catalogs (`BaseModel::columns($table, false)`, fixed UUID constants, `CatalogSeeder`, read-only for tenant roles): `screening_status` (keys `draft`, `scored`, `completed`, `invalidated`), `screening_situation` (7 rows whose `key` values equal the engine's `SituationKey` constants, with `sis_code` smallint — 1/2/3/4/5/6/6), `finding_type` (5 rows whose `key` values equal the engine's `ScoringFinding` closed keys), `confirmatory_test_result` (keys `delay_confirmed`, `delay_ruled_out`, `inconclusive`). Every own migration column MUST carry `->comment()`; every table comments itself inside the closure. Exact columns and indexes per `design.md` §3.

#### Scenario: catalogs seed with engine-aligned keys
- WHEN `migrate:fresh --seed` runs
- THEN `screening_situation` has exactly 7 rows whose `key` set equals the 7 `SituationKey` constants and whose `sis_code` values are 1,2,3,4,5,6,6, and `finding_type` has exactly the 5 `ScoringFinding` keys.

#### Scenario: catalogs are central and frozen for tenants
- WHEN a tenant-role user calls create/update/delete on any of the 4 catalogs
- THEN the request is rejected by permissions (403), and the rows carry no `tenant_id`.

### REQ-SCR-002 — Draft creation resolves everything server-side

`POST /api/screening` SHALL accept ONLY `visit_id`, optional somatometry (`weight_grams`, `height_mm`, `head_circumference_mm`), and optional `notes`. The server MUST: resolve the child from the visit (same tenant); freeze `instrument_version_id` to the published `is_default` version; compute chronological/corrected ages from `child.birth_date` and `visit.visit_date` via the engine's `AgeResolver`/`AgeGroupResolver` with that version's RuleSet; store `age_group_id` (chronological) and `applied_age_group_id` (corrected-age substitution when it applies); set status `draft`; and return HTTP 201 with the screening plus the item bank of REQ-SCR-007. A chronological age outside 30–2159 days (or corrected age below the applicable range) MUST return 422 `SCREENING_NOT_APPLICABLE` and persist nothing. At most ONE non-deleted screening per visit (partial unique); a second create returns 422 `VISIT_ALREADY_SCREENED`. Invalidation does NOT free the visit.

#### Scenario: premature substitution is stored, not client-chosen
- GIVEN a child aged 100 days with `birth_record.gestational_age_weeks = 32`
- WHEN the draft is created
- THEN `age_group_id` resolves to G3, `applied_age_group_id` to G1, both ages are stored in days, and no request field could have influenced either.

#### Scenario: out-of-range child persists nothing
- GIVEN a child aged 2200 days at `visit_date`
- WHEN `POST /api/screening` runs
- THEN the response is 422 `SCREENING_NOT_APPLICABLE` and no `screening` row exists.

### REQ-SCR-003 — The result NEVER comes from the client

All write FormRequests SHALL whitelist exclusively: create/update → `visit_id` + somatometry + `notes`; answers → `answers[]{screening_item_id, screening_item_option_id}` and `risk_factor_answers[]{risk_factor_id, is_present}`. The `Screening` model's `$fillable` MUST contain only client-settable fields; `result_color_id`, `situation_id`, `alert_sign_count`, `risk_factor_count`, `global_result_message_id`, `scored_at`, `engine_version`, `rules_fingerprint`, `applied_age_group_id`, `age_group_id`, `instrument_version_id`, `screening_status_id`, `previous_screening_id`, `previous_result_color_id` MUST be outside `$fillable` and assigned only by services. `screening_area_result` and `screening_answer.result_color_id` SHALL have NO write endpoint: area results are engine output only, and each answer's color is resolved server-side from the selected option. A Feature test named `ForgedResultIsIgnoredTest` MUST exist and pass.

#### Scenario: ForgedResultIsIgnoredTest
- GIVEN a draft whose answers produce a red global result
- WHEN the client injects `result_color_id` (green), `situation_id`, counters, and `scored_at` into the create, answer, and score payloads
- THEN every injected field is ignored, and after `POST /score` the stored and returned `result_color_id` is red.

### REQ-SCR-004 — Batch idempotent answers with staleness reset

`PUT /api/screening/{id}/answer` SHALL upsert answers in one transaction, keyed by the partial unique `(screening_id, screening_item_id)`. Validation: every item MUST belong to the applied group OR its alternate group of the screening's version (else 422 listing offending ids); every option MUST belong to its item; every risk factor MUST belong to the screening's version. Each stored answer snapshots `result_color_id` from the selected option, server-side. The endpoint is idempotent: replaying the same payload yields the same final rows and 200. Allowed in statuses `draft` and `scored`; on `completed` or `invalidated` it returns 409 `SCREENING_FROZEN`. When the screening is `scored` and the batch (or a somatometry update) CHANGES any effective input, the status MUST revert to `draft` and the header result fields (`result_color_id`, `situation_id`, counters, `global_result_message_id`, `scored_at`) MUST be cleared — score runs are never deleted. A no-op replay while `scored` stays `scored`.

#### Scenario: idempotent replay
- WHEN the same 10-answer batch is sent twice
- THEN both responses are 200 and exactly 10 `screening_answer` rows exist with identical content.

#### Scenario: mutation invalidates a stale score
- GIVEN a `scored` screening
- WHEN one answer changes to a different option
- THEN status is `draft`, header result fields are NULL, and the prior `screening_score_run` rows remain.

### REQ-SCR-005 — Alternate evaluation is an API contract

`POST /api/screening/{id}/score` SHALL evaluate the alternate trigger BEFORE persisting anything: for each development axis of the applied group where ALL own items are answered AND every answered own item's color is yellow, all items of the SAME area in the alternate group MUST be answered. If any triggered area lacks alternate answers, the endpoint returns 422 `ALTERNATE_ITEMS_REQUIRED` with, per area, the missing alternate items (id, code, question, options without colors) — and MUST NOT persist any score artifact (no header change, no area results, no run, no findings). G1 (no alternate group) never triggers. Alternate answers are accepted at any time by REQ-SCR-004, whether or not the trigger fired.

#### Scenario: all-yellow axis demands the alternate round
- GIVEN a G10 screening whose MF own answers are {yellow, yellow, yellow} and no MF alternate answers
- WHEN `POST /score` runs
- THEN the response is 422 `ALTERNATE_ITEMS_REQUIRED` naming the G9A MF items, status remains `draft`, and zero `screening_area_result`/`screening_score_run` rows exist.

#### Scenario: answered alternates unlock scoring
- GIVEN the same screening after answering the 3 G9A MF items
- WHEN `POST /score` runs
- THEN scoring proceeds and MF is scored by pooling own + alternate answers (Model A, REQ-SCO-005).

### REQ-SCR-006 — Scoring persists the engine's truth transactionally

`POST /api/screening/{id}/score` (statuses `draft`/`scored`) SHALL: require every own-group item answered (else 422 `INCOMPLETE_ANSWERS` listing missing item ids; unanswered risk factors are allowed and count zero); build `ScoringInput` per design D6 (initial score `forDates`, with a tripwire that the resolved applied group equals the stored one); run the engine with the RuleSet of the screening's OWN `instrument_version`; and persist in ONE transaction: header (`result_color_id`, `global_result_message_id`, `situation_id`, `alert_sign_count`, `risk_factor_count`, `engine_version`, `rules_fingerprint` = the version's `content_hash`, `scored_at`, status `scored`), one `screening_area_result` per trace area (color, pooled counts, answered own/alternate counts, applied thresholds), effective answers (auto-derived overrides written with `is_auto_derived = true`), `screening_risk_factor_answer` rows for answered/derived factors, `screening_finding` rows for every trace finding, and ONE authoritative `screening_score_run` carrying `input_payload` (natural-key serialized input) and `result_payload` = `ScoringResult::toArray()`. Scoring twice without input changes MUST be deterministic (same result, one more run).

#### Scenario: the trace is persisted whole
- WHEN a screening scores yellow with matched global sequence 30
- THEN its `screening_score_run.result_payload` contains `matched_global_rule_sequence = 30`, per-area pooled counts and thresholds, and the header counters equal the trace's `alert_sign_count`/`risk_factor_count`.

#### Scenario: nothing persists on engine failure
- GIVEN an artificially corrupted answer set that makes the engine throw
- WHEN `POST /score` runs
- THEN the response is a 4xx/5xx typed error and the screening is byte-identical to before (no partial rows).

### REQ-SCR-007 — Auto-derived answers arrive locked and override

The item bank (returned by `POST /api/screening` and `GET /api/screening/{id}/items`) SHALL include per area the own items and the alternate-group items (flagged `is_alternate`), options WITHOUT colors, and MUST flag as locked: the head-circumference EN item when the screening's `head_circumference_mm` is present (plausible), and FRB3/FRB4/FRB7 when the corresponding `birth_record` value is known (plausible) — each with a structured `locked_reason` (`auto_derivation_key`, source field, value). A NULL/implausible source leaves the item answerable (D-14: unknown is never "no"). If the client submits an answer for a derivable item anyway, REQ-SCR-004 accepts it, and scoring OVERRIDES it: the persisted answer carries the derived option with `is_auto_derived = true`, and the engine's `auto_answer_overrode_manual` finding is persisted.

#### Scenario: known measurement locks and overrides
- GIVEN `head_circumference_mm = 360` (in band) and a client answer selecting the red option of the HC item
- WHEN the bank is fetched and the screening is scored
- THEN the bank flags the HC item locked with its `locked_reason`, the stored answer is the green option with `is_auto_derived = true`, and a finding with `finding_type.key = 'auto_answer_overrode_manual'` exists.

#### Scenario: unknown input stays manual
- GIVEN `birth_record.birth_weight_grams` is NULL
- THEN FRB4 is NOT locked, a manual FRB4 answer stands after scoring, and a `unknown_perinatal_input` finding is persisted.

### REQ-SCR-008 — Plausibility: out-of-range input is absent, never zero

When building `ScoringInput`, perinatal values read from `birth_record` MUST pass a plausibility filter (design D12); an out-of-range value is treated as NULL ("se ignora") — deriving nothing, leaving the factor manually answerable, and yielding the engine's `unknown_perinatal_input` finding. It MUST NOT be coerced to zero or to "no". Somatometry captured on the screening itself SHALL be range-validated in FormRequests (422 on implausible values, field-level messages in Spanish).

#### Scenario: legacy-style garbage weight derives nothing
- GIVEN `birth_record.birth_weight_grams = 10` (below the plausible floor)
- WHEN the screening is scored
- THEN FRB4 is not auto-derived, `risk_factor_count` does not count it unless manually answered yes, and `unknown_perinatal_input` is recorded for FRB4.

#### Scenario: implausible capture is rejected at the door
- WHEN `POST /api/screening` carries `weight_grams = 100`
- THEN the response is a 422 validation error on `weight_grams` and no screening is created.

### REQ-SCR-009 — `completed` freezes the screening

`POST /api/screening/{id}/complete` SHALL transition `scored` → `completed` (setting `completed_at`); any other origin status returns 409 `INVALID_STATUS_TRANSITION`. On a `completed` screening: `PUT /answer`, somatometry updates, `POST /score`, and `POST /complete` MUST all return 409 `SCREENING_FROZEN`. Only REQ-SCR-010 (rescore) and REQ-SCR-011 (invalidate) remain available, plus reads.

#### Scenario: completed rejects late answers
- GIVEN a `completed` screening
- WHEN `PUT /api/screening/{id}/answer` arrives with any payload
- THEN the response is 409 `SCREENING_FROZEN` and no row changed.

### REQ-SCR-010 — Rescore is auditable, never silent, and version-pinned

`POST /api/screening/{id}/rescore` (statuses `scored`/`completed`) SHALL require the dedicated permission `rescore.screening` and a non-empty `reason`. Every invocation creates a NEW `screening_score_run` (never updates one). With `authoritative = true`: the run MUST use the screening's OWN `instrument_version` (a request naming another version is 422 `VERSION_PINNED`), MUST verify the stored `rules_fingerprint` equals that version's current `content_hash` (else 409 `RULES_FINGERPRINT_MISMATCH`, nothing persisted), and updates the header result/area results/findings exactly as REQ-SCR-006 — status is NOT changed by a rescore. With `authoritative = false` (the default): the run MAY name any published `instrument_version_key` (what-if simulation), records that version and its hash on the run, and MUST NOT modify the screening header or any result row. Migrating a screening to another version is impossible: no endpoint writes `screening.instrument_version_id` after create.

#### Scenario: non-authoritative what-if leaves the record intact
- GIVEN a `completed` screening on `edi-legacy-2013`
- WHEN a rescore with `authoritative = false` and `instrument_version_key = 'edi-2022-2ed'` runs
- THEN a run row exists with that version and `is_authoritative = false`, and the screening header is byte-identical.

#### Scenario: authoritative rescore demands permission, reason, and own version
- WHEN a user without `rescore.screening` calls rescore → 403; with permission but no `reason` → 422; with `authoritative = true` and a foreign version key → 422 `VERSION_PINNED`
- THEN in all three cases no run row is created.

### REQ-SCR-011 — Invalidate retires without deleting

`POST /api/screening/{id}/invalidate` SHALL require permission `delete.screening` and a non-empty `reason` (stored in `invalidation_reason`), transition any status except `invalidated` to `invalidated`, and freeze the record completely (every mutating endpoint, including rescore, returns 409; reads keep working). Invalidated screenings MUST be excluded from previous-screening resolution (REQ-SCR-012). Orion `destroy` is NOT exposed for `screening`.

#### Scenario: invalidated is terminal and inert
- GIVEN an invalidated screening
- WHEN answer/score/complete/rescore are called
- THEN each returns 409, and a new screening for the same child resolves its previous result ignoring the invalidated one.

### REQ-SCR-012 — Situation is computed against a frozen previous result

At FIRST authoritative score, the system SHALL resolve the child's previous screening — the latest `completed`, non-invalidated screening of the same child (any center, same tenant) whose `visit.visit_date` precedes this screening's (tie-break `scored_at`) — and freeze `previous_screening_id` and `previous_result_color_id` on the header. The engine receives `previousResultColorKey` from that frozen color (NULL = first screening) and its `SituationOutcome.key` maps to `screening_situation.situation_id`. Rescores MUST reuse the frozen previous color, never re-resolve it. `sis_code` is exposed on reads solely for the official export.

#### Scenario: worsened and unchanged share sis_code 6, not the situation row
- GIVEN a child whose previous completed result is green and current computes red, and another whose previous is red and current computes red
- THEN their `situation_id` rows are `worsened` and `unchanged` respectively, both exposing `sis_code = 6`.

#### Scenario: previous is frozen at first score
- GIVEN a draft created when the child had no completed screening, and another screening completes before this one is scored
- WHEN this screening scores
- THEN `previous_screening_id` points to that completed screening (resolution happened at score time), and a later rescore does not change it.

### REQ-SCR-013 — Read API, registration, and tenant isolation

Orion resources under `['auth:sanctum', 'initializeTenancy']` + `throttle:api`: `screening` with `only(['index','show','update'])` (update = somatometry/notes only, REQ-SCR-003) plus the custom routes (create/items/answer/score/complete/rescore/invalidate), and `confirmatory_test` with `only(['index','show','store','update'])`. `includes()` allowlists: `answers`, `area_results`, `risk_factor_answers`, `findings`, `score_runs`, `confirmatory_test`, `visit`, `child` and their catalog relations. Module registration runs the full backend checklist (design §6) with `rescore.screening` as a 13th permission row; the two menu rows are seeded HIDDEN until `edi-screening-ui`; NO `$mapperSelects`/`CatalogType` entries are added. Tenant isolation: a user of tenant B can neither read nor mutate tenant A screenings (404 via `TenantScope`), and screenings cannot reference another tenant's visit/child.

#### Scenario: cross-tenant is invisible
- GIVEN screenings in tenant A
- WHEN tenant B lists or shows them, or posts answers to one
- THEN list excludes them and direct access is 404.

#### Scenario: menu is silent until the UI ships
- WHEN `GET /api/menu` runs for any tenant role after seeding
- THEN no Tamizajes entry appears, yet `screening.*` permissions exist and gate the API (403 without them).

### REQ-SCR-014 — Confirmatory test capture

`confirmatory_test` SHALL be creatable only for a `completed` screening whose `result_color_id` is red (else 422 `CONFIRMATORY_NOT_APPLICABLE`), at most one non-deleted row per screening (partial unique). Fields: `test_name` (required, e.g. 'Battelle'), `applied_at` (nullable date, not in the future), `confirmatory_test_result_id` (nullable until known, central catalog), `notes`. The follow-up workflow (when to prompt, referral tracking) belongs to `edi-followup-and-referral`.

#### Scenario: only red completed screenings admit a confirmatory test
- WHEN a confirmatory test is posted for a green `completed` screening
- THEN the response is 422 `CONFIRMATORY_NOT_APPLICABLE` and no row exists.

### REQ-SCR-015 — Official screening document (severable phase)

`GET /api/screening/{id}/document` SHALL return the official application document (FUA) as PDF (dompdf), only for `scored`/`completed` screenings (409 otherwise), gated by `read.screening`, rendered EXCLUSIVELY from stored data (header, somatometry, per-area colors with counts, counters, global message with referral bullets, situation, folio, center, practitioner) — never re-running the engine. Layout is a clean official format, not a pixel replica of the legacy form. This requirement is SEVERABLE: if the delivery budget trips, it ships as its own follow-up PR/change without touching the previous requirements.

#### Scenario: the document reflects storage, not recomputation
- GIVEN a scored screening whose rules were hypothetically re-seeded (fingerprint mismatch)
- WHEN the document is requested
- THEN it renders the STORED result and trace values without invoking `ScoringEngine`.
