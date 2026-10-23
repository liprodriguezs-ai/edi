# Spec: EDI Scoring Engine

Change: `edi-scoring-engine`
Capabilities: `scoring-engine` (new)

> RFC 2119 keywords (MUST, MUST NOT, SHALL, SHOULD, MAY) are normative.
> "RuleSet" = the immutable value object holding one `instrument_version`'s scoring content, keyed by NATURAL keys (group codes, area codes, color keys) — never UUIDs.
> "Model A" = the empirically validated alternate-evaluation model of `edi-instrument-catalog/design.md` §14 (which CORRECTS §13 and prevails).
> Color keys are `green` | `yellow` | `red` (the `result_color` catalog keys). Severity order: green < yellow < red.
> On archive, REQ-SCO-* merge into `openspec/specs/scoring-engine/spec.md`.

---

## ADDED Requirements — capability `scoring-engine`

### REQ-SCO-001 — Pure, deterministic engine

`Modules\Screening\Services\Scoring\ScoringEngine` SHALL be a pure function: `score(ScoringInput $input, RuleSet $rules): ScoringResult`. Code under the `Modules\Screening\Services\Scoring` namespace MUST NOT use Eloquent/query builder, `now()`/`Carbon::now()`, `request()`, `config()`, `env()`, the container, randomness, or wall-clock time — all facts enter as data on the input or the RuleSet. Given identical `(input, rules)`, the engine MUST produce an identical `ScoringResult`, including the trace. `result_color`, counters, and situation are NEVER accepted from a caller — they are outputs only (domain invariant 1).

#### Scenario: determinism is byte-stable
- WHEN the same `ScoringInput` and `RuleSet` are scored twice
- THEN the serialized (`toArray()`) results are identical, including area counts, matched rule sequences, findings, and trace.

#### Scenario: purity is enforced by an architecture test
- WHEN the test suite scans every file under `Modules/Screening/app/Services/Scoring/`
- THEN no forbidden token (`Illuminate\Database`, `Eloquent`, `DB::`, `now(`, `Carbon::now`, `request(`, `config(`, `env(`, `app(`, `rand`, `uniqid`) appears, and the test fails naming file and token if one does.

### REQ-SCO-002 — RuleSet construction and loader parity

The system SHALL provide two RuleSet builders OUTSIDE the pure namespace: `CsvRuleSetLoader` (builds a RuleSet from a version's committed seed CSV directory `backend/Modules/Instrument/database/seeders/csv/{version-key}/`, with zero database access) and `DatabaseRuleSetLoader` (builds it from the seeded Instrument models). Both MUST resolve every UUID reference to natural keys at load time (color keys, area codes, group codes, message keys) and MUST retain `legacy_id` lookups (group, item, risk factor) for oracle replay — in-memory only, never exposed through any API. The two loaders MUST produce equal RuleSets for the same version.

#### Scenario: CSV path needs no database
- WHEN `CsvRuleSetLoader` builds `edi-legacy-2013`
- THEN no database connection is opened and the RuleSet contains 15 official groups, 29 groups total, 451 items, 92 official-group area rules, 59 global rules, 7 risk factors, and 118 head-circumference bands.

#### Scenario: parity across sources
- GIVEN `migrate:fresh --seed` has run
- WHEN both loaders build `edi-legacy-2013` and `edi-2022-2ed`
- THEN for each version the two RuleSets serialize identically.

### REQ-SCO-003 — Age resolution, corrected age, and group substitution

`AgeResolver` SHALL compute chronological age in days from `birth_date` and `test_date` according to the RuleSet's `age_calculation_mode`: `calendar` = exact day difference; `thirty_day_month` = legacy arithmetic where a month counts 30 days and a year 360 (calendar decomposition into years/months/days, then `y×360 + m×30 + d`). Corrected age SHALL be computed as `chronological_days − (40 − gestational_age_weeks) × 7` ONLY when both: chronological age < 2 years and `gestational_age_weeks < 37`, with no minimum age (Manual EDI 2021 p.9/p.12, DGIS instructivo p.3); otherwise corrected age is absent. The legacy `chronological_days > 59` gate was removed on 2026-10-21: no official source has it. `AgeGroupResolver` SHALL select the official group whose `[min_age_days, max_age_days]` contains the chronological days; when corrected age exists and falls in a DIFFERENT official group, that group substitutes the chronological one and a finding `age_group_substituted_by_corrected_age` MUST be recorded. A chronological age outside 30–2159 days MUST raise a typed not-applicable exception. When `ScoringInput` carries an explicit `age_group_code` (replay/recompute path), age resolution is skipped entirely.

#### Scenario: premature substitution
- GIVEN `thirty_day_month`, chronological age 100 days and `gestational_age_weeks = 32`
- WHEN ages are resolved
- THEN corrected age is 44 days, the applied group is `G1` (not `G3`), and the finding `age_group_substituted_by_corrected_age` is recorded with both group codes.

#### Scenario: gates block correction
- WHEN chronological age is 50 days (≤ 59), OR gestational age is 37 weeks or more, OR chronological age is 2 years or more
- THEN no corrected age is computed and the chronological group applies.

#### Scenario: explicit group bypasses dates
- WHEN input is built with `age_group_code = 'G10'` and no dates
- THEN the applied group is `G10` and no age fields appear in the result.

### REQ-SCO-004 — Auto-derived answers honor "se ignora"

`AutoAnswerDeriver` SHALL auto-derive, from `ScoringInput` perinatal values: risk factor answers for the three factors carrying `auto_derivation_key` (`gestational_age_lt_34_weeks`: weeks < 34 → yes, else no; `birth_weight_lte_1500_g`: grams ≤ 1500 → yes, else no; `mother_age_lt_16_years`: years < 16 → yes, else no) and the head-circumference item answer (measurement in integer mm compared against the RuleSet band for the child's sex and age in months: inside `[min, max]` → the item option whose color is GREEN, outside → the option whose color is RED — options selected by COLOR, never by label). A NULL input MUST NOT derive anything and MUST NOT be treated as "no": the answer stays manually answerable, and a finding `unknown_perinatal_input` is recorded (clinic change decision D-14). When a derivation is possible, it OVERRIDES any manually provided answer; if the override changed a provided answer, a finding `auto_answer_overrode_manual` is recorded. A measurement present but with no band row for that sex/month (e.g. months 60–71) records `missing_percentile_reference` and derives nothing.

#### Scenario: threshold edges
- WHEN `gestational_age_weeks = 34`, `birth_weight_grams = 1500`, `mother_age_at_birth_years = 16`
- THEN FRB3 derives no, FRB4 derives YES (≤ is inclusive), FRB7 derives no.

#### Scenario: NULL is unknown, never "no"
- GIVEN `birth_weight_grams = NULL` and a manual FRB4 answer of yes
- WHEN derivation runs
- THEN FRB4 remains yes (manual answer stands), and `unknown_perinatal_input` is recorded for FRB4.

#### Scenario: head circumference in range
- GIVEN a male, month-1 band `[355, 390]` mm and `head_circumference_mm = 360`
- THEN the group's head-circumference EN item is answered with its green option, overriding any manual answer for that item.

### REQ-SCO-005 — Area scoring with pooled alternates (Model A)

For every semaphore area (`MG`, `MF`, `LE`, `SO`, `CO`, `EN`, `ALA`) with items in the applied official group, `AreaScorer` SHALL count colors over the answered items of the group's OWN area items **plus** the answered items of the SAME area in the group's alternate group (pooled into one set), then apply the OFFICIAL group's `area_scoring_rule` with the 4-branch contract: `reds ≥ red_threshold → RED; else if green_threshold IS NOT NULL and greens ≥ green_threshold → GREEN; else if allows_yellow → YELLOW; else GREEN`. Alternate items MUST NOT be scored as a separate set, and the alternate groups' `area_scoring_rule` rows MUST NOT be consumed as a scoring path (they remain catalog record only — instrument design §14). `ALE` is a counter and MUST never be area-scored. Unanswered items are simply not counted; each records an `unanswered_item` finding.

#### Scenario: the §14 refutation case scores yellow
- GIVEN applied group `G10` (dev-axis rule red 2 / green 2 / yellow) with own MF answers {yellow, yellow, yellow} and alternate `G9A` MF answers {yellow, yellow, red}
- WHEN MF is scored
- THEN the pooled counts are reds 1, greens 0, yellows 5, and MF is YELLOW (one alternate red does NOT escalate — the discarded model B would have produced RED).

#### Scenario: pooled reds do escalate under the own threshold
- GIVEN the same group with own MF {red, yellow, yellow} and alternate MF {yellow, yellow, red}
- THEN reds = 2 ≥ 2 and MF is RED.

#### Scenario: no answered alternates means plain own-group scoring
- GIVEN own MG answers {green, green, yellow} in `G5` (red 1 / green 2 / yellow) and no alternate answers
- THEN greens = 2 ≥ 2 and MG is GREEN.

### REQ-SCO-006 — Counters: alert signs and biological risk factors

`CounterScorer` SHALL compute `alert_signs` = the number of answered `ALE` items (own group) whose selected option's color is yellow, and `risk_factors` = the number of risk factors answered yes (after auto-derivation). Both are integer counters, never semaphores, and both feed the global grammar vocabulary and the result payload.

#### Scenario: only ALE yellows count as alert signs
- GIVEN a `G14` screening whose answers contain 4 yellow-colored selections, of which 2 belong to ALE items
- THEN `alert_signs = 2`.

#### Scenario: unanswered risk factors count zero
- GIVEN FRB answers {FRB1: no, FRB2: NULL, FRB3: yes}
- THEN `risk_factors = 1`.

### REQ-SCO-007 — Global scoring: first match over a closed grammar

`GlobalScorer` SHALL evaluate the applied official group's `global_scoring_rule` rows in ascending `sequence` and return the FIRST rule whose condition matches (color key + message key + matched sequence in the trace). `ConditionEvaluator` SHALL interpret the closed 6-operator grammar (`all`, `any`, `not`, `count`, `sum`, `color_in`) over exactly these facts: `red_areas`/`yellow_areas`/`green_areas` (counts of semaphore areas by resulting color), `alert_signs`, `risk_factors`, and per-area colors for `color_in` (true if ANY listed area holds the color). It MUST NOT use `eval` or any dynamic code execution, and an unknown operator, set name, or color key MUST raise a typed exception — never a silent false. If no rule matches (impossible with the seeded catch-all, defensive anyway), the engine MUST throw, never default.

#### Scenario: legacy G1 escalation on ALE + FRB
- GIVEN applied group `G1` with no red area, `alert_signs = 1`, `risk_factors = 1`
- WHEN global scoring runs
- THEN sequence 20 matches (`sum(alert_signs, risk_factors) ≥ 2`), the global color is YELLOW with message key `yellow_social`, and the trace records sequence 20.

#### Scenario: groups ≥ 5 ignore ALE and FRB
- GIVEN applied group `G10` with all areas green, `alert_signs = 5`, `risk_factors = 3`
- THEN the catch-all matches and the global color is GREEN (the legacy scope bug is faithful behavior, instrument D15).

#### Scenario: grammar is closed
- WHEN a condition `{"xor": []}` reaches the evaluator
- THEN a typed unknown-operator exception is thrown.

### REQ-SCO-008 — Situation: seven states over the legacy six codes

`SituationResolver` SHALL classify the screening against the previous result color (nullable input; NULL = first screening) into EXACTLY seven states with this mapping to the legacy export code (`sis_code`):

| Situation key | Condition | sis_code |
|---|---|---|
| `first_time_green` | no previous, current green | 1 |
| `first_time_yellow` | no previous, current yellow | 2 |
| `first_time_red` | no previous, current red | 3 |
| `recovered_from_yellow` | previous yellow, current green | 4 |
| `recovered_from_red` | previous red, current yellow or green | 5 |
| `unchanged` | current equals previous | 6 |
| `worsened` | current worse than previous (severity order) | 6 |

The model MUST keep `unchanged` and `worsened` as distinct states (the legacy conflated them under code 6); `sis_code` is preserved ONLY for the official export. No PHP native enum: situation keys are string constants on the value object, and the `situation` catalog table is deferred to the persistence change.

#### Scenario: worsened and unchanged share the export code but not the state
- WHEN previous is green and current is red, and separately previous is red and current is red
- THEN the states are `worsened` and `unchanged` respectively, and BOTH carry `sis_code = 6`.

#### Scenario: recovery from red includes partial recovery
- WHEN previous is red and current is yellow
- THEN the state is `recovered_from_red` (`sis_code = 5`).

### REQ-SCO-009 — Result and trace are explainable and serializable

`ScoringResult` SHALL expose: applied group code (+ substitution flag and resolved ages when dates were provided); per-area results (color key, pooled counts reds/yellows/greens, answered own/alternate item counts, and the applied rule's thresholds); `alert_signs` and `risk_factors` counters; derived answers (which factor/item, derived value, source input); the global color key, matched rule sequence and message key; the situation (key + `sis_code`) when previous-result information was provided; and the ordered findings list (closed keys: `age_group_substituted_by_corrected_age`, `unknown_perinatal_input`, `auto_answer_overrode_manual`, `unanswered_item`, `missing_percentile_reference`). The result MUST serialize to an array/JSON stable enough for the next change to persist as the screening's trace. It MUST NOT contain UUIDs, timestamps, or `legacy_id` values.

#### Scenario: a clinician can reconstruct the outcome
- WHEN any oracle screening is scored
- THEN the result names, for every area, the pooled counts and thresholds that produced its color, and the exact global rule sequence that fired — enough to explain the semaphore without re-running the engine.

### REQ-SCO-010 — Oracle replay harness (CI gate)

The test suite SHALL replay all 1,836 oracle screenings (`edi_oracle.jsonl`, committed verbatim into `Modules/Screening/tests/Fixtures/`) against the `edi-legacy-2013` RuleSet built by `CsvRuleSetLoader`, with NO database, inside `php artisan test` (module Unit test extending plain `PHPUnit\Framework\TestCase`). Input construction per row: applied group from `group_legacy_id` via the RuleSet legacy lookup; answers from `answers` (item `legacy_id` → item, label → option; the resolved option color MUST equal the row's `answer_colors` value — a seed-integrity tripwire); risk factor answers from `risk_factors`; perinatal fields are NOT passed (all NULL → the deriver stays inert, so the recorded production answers stand exactly as captured). The harness MUST assert:

1. **Colors**: computed global color equals `expected_color` for every row NOT registered in the known-discrepancies ledger; any unregistered mismatch fails CI listing `legacy_id`, group, expected, computed.
2. **Ledger is alive, both directions**: every ledger entry must still reproduce EXACTLY as registered (same expected and computed values); an entry that now matches production, computes a different value, has a bad signature, or references a missing `legacy_id` fails CI naming the stale entry.
3. **Quotas**: zero mismatches AND zero ledger entries in groups 1–4 (100%); overall matches ≥ 99.8% of 1,836 (ledger entries count as mismatches for the quota).
4. **Counters**: computed `alert_signs`/`risk_factors` equal `expected_ale`/`expected_frb` on EVERY row (no ledger tolerance).
5. **First-time situations**: for rows with `expected_situation` ∈ {1, 2, 3}, `SituationResolver` with no previous result yields that exact `sis_code`.

#### Scenario: the shipped ledger is exactly the two unexplained cases
- WHEN the harness runs against the committed fixture and ledger
- THEN it passes with exactly 2 ledger entries — `legacy_id` 791 and 2452, group `G10`, expected red, computed yellow, reason `legacy_unexplained` — and 1,834/1,836 = 99.89% ≥ 99.8%.

#### Scenario: a new mismatch breaks CI
- GIVEN an engine regression that flips one G7 screening's color
- WHEN the harness runs
- THEN it fails naming that `legacy_id` with expected and computed colors.

#### Scenario: a healed discrepancy breaks CI too
- GIVEN a rule change that makes `legacy_id` 791 compute red (matching production)
- WHEN the harness runs
- THEN it fails demanding removal of the now-stale ledger entry.

### REQ-SCO-011 — Known-discrepancies ledger format

The ledger (`Modules/Screening/tests/Fixtures/oracle_known_discrepancies.json`) SHALL be a JSON array of entries with fields: `legacy_id` (int), `group_code`, `field` (`global_color` for now), `expected` (production value), `computed` (engine value), `reason` (member of a CLOSED enum defined as constants in the harness — sole initial member `legacy_unexplained`), `signature` (first 16 hex chars of SHA-256 over `"{legacy_id}|{field}|{expected}|{computed}|{reason}"`), `notes` (free text citing evidence). Adding a reason enum member or an entry is a reviewed code change; the harness MUST reject entries with unknown reasons or invalid signatures.

#### Scenario: tampering is rejected
- GIVEN a ledger entry whose `expected` was edited without recomputing `signature`
- WHEN the harness runs
- THEN it fails citing the signature mismatch.

### REQ-SCO-012 — Module boundaries: code-only skeleton

The `Screening` module SHALL be registered in `modules_statuses.json` and contain ONLY: the Scoring namespace (pure), the RuleSet loaders, exceptions, and tests/fixtures. It MUST NOT create migrations, models, controllers, routes, permissions, menu rows (`modules.csv`/`permissions.csv`/`module_system.csv`/`SystemTableSeeder`), `$mapperSelects` or `CatalogType` entries — all of that belongs to the screening-application change. No trace table is created: the trace lives on `ScoringResult` until the next change persists it.

#### Scenario: no runtime surface exists
- WHEN `php artisan route:list` runs after this change
- THEN no `screening` route exists, and `GET /api/menu` output is unchanged for every role.

#### Scenario: the module boots
- WHEN the test suite runs
- THEN the `Screening` module's provider loads and its Unit/Feature tests execute.
