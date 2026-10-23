# Tasks: EDI Scoring Engine

Change: `edi-scoring-engine`

## Delivery forecast (review workload guard)

Decision needed before apply: Yes
Chained PRs recommended: Yes
400-line budget risk: High

Roughly ~1,800 authored lines of engine/VO/loader code plus a comparable volume of unit tests and the harness. The 1,836-line oracle fixture and the ledger are derived data (excluded from the authored-risk count, included in snapshot identity). Recommended chain (each slice autonomous, verifiable, revertible): **PR1** = Phases 0–1 (module skeleton + VOs + RuleSet loaders + parity), **PR2** = Phases 2–3 (pure collaborators + unit tests), **PR3** = Phases 4–6 (engine composition + oracle harness + closure). Resolve open decisions Q1–Q8 (`design.md` §9) before Phase 3 — `AgeResolver` and `SituationResolver` encode them.

All paths relative to `/Users/rodriguez/code/edi-app/`. Backend tests run from `backend/` with the host command in `openspec/config.yaml`. Do NOT create git commits from this pipeline.

---

## Phase 0 — Module skeleton (code-only)

- [x] 0.1 Scaffold `backend/Modules/Screening/` cloning the `Instrument` module layout WITHOUT database/routes surface: `module.json`, `composer.json`, provider, `app/Services/{Scoring,RuleSetLoading}/`, `tests/{Unit,Feature,Fixtures}/`. No migrations, no models, no controllers, no `routes/api.php` resources (empty or absent per module conventions).
- [x] 0.2 Register `"Screening": true` in `backend/modules_statuses.json`. Verify the provider boots (`php artisan about` or module list) and that `route:list` and `GET /api/menu` are unchanged (spec REQ-SCO-012 scenarios).

## Phase 1 — Value objects + RuleSet loaders

- [x] 1.1 `app/Services/Scoring/Values/`: `ScoringInput` (named constructors `forGroup`/`forDates`, XOR-enforced — design D5), `ItemAnswer`, `RuleSet` (+ nested read models per design §3), `ScoringResult`, `AreaResult`, `ScoringFinding` (closed keys, D14), `SituationOutcome`, key constant classes `ResultColorKey`/`SituationKey` (D11). All `final readonly`, zero framework imports.
- [x] 1.2 `app/Services/Scoring/Exceptions/`: `ScreeningNotApplicableException`, `UnknownConditionOperatorException`, `InvalidScoringInputException` (unknown group/item/label, answers outside the applied group pair), `IncompleteRuleSetException`.
- [x] 1.3 `app/Services/RuleSetLoading/CsvRuleSetLoader.php`: build a RuleSet from `backend/Modules/Instrument/database/seeders/csv/{version-key}/` with `fopen`/`fgetcsv` (quoted cells embed newlines); resolve the head-circumference item per group via the EN `sort_order = 3` convention (D10); build legacy-id lookup maps; run build-time validations (design §6).
- [x] 1.4 `app/Services/RuleSetLoading/DatabaseRuleSetLoader.php`: same RuleSet from the seeded Instrument models, natural-key resolution mirroring `InstrumentContentHasher`.
- [x] 1.5 Unit test: `CsvRuleSetLoader` builds `edi-legacy-2013` with NO database connection and the exact counts (15 official groups, 29 total, 451 items, 92 official area rules, 59 global rules, 7 risk factors, 118 bands); D10 designations exist exactly for G1–G14 and each designated item's question matches `/perímetro cefálico/i` (test-only tripwire).
- [x] 1.6 Feature test `tests/Feature/RuleSetParityTest.php`: CSV-built ≡ DB-built serialized RuleSets for BOTH versions (REQ-SCO-002).

## Phase 2 — Pure scorers (rule mechanics)

- [x] 2.1 `app/Services/Scoring/ConditionEvaluator.php`: closed 6-operator interpreter over the fact set (D13); typed exceptions on anything outside the grammar; exactly one operator key per node.
- [x] 2.2 `app/Services/Scoring/AreaScorer.php`: Model A pooling (own + same-area alternate answered items) + the 4-branch contract using the OFFICIAL group's rule; alternate `area_scoring_rule` rows never consulted; ALE excluded; `unanswered_item` findings (REQ-SCO-005).
- [x] 2.3 `app/Services/Scoring/CounterScorer.php`: `alert_signs` (yellow-colored ALE selections) + `risk_factors` (yes after derivation) (REQ-SCO-006).
- [x] 2.4 `app/Services/Scoring/GlobalScorer.php`: ascending-sequence first match, returning color key, message key, and matched sequence; throws if nothing matches (REQ-SCO-007).
- [x] 2.5 Unit tests: `ConditionEvaluatorTest` (all 6 operators, nesting, empty `all`/`any` semantics, unknown operator/set/color throws), `AreaScorerTest` (4-branch matrix; the §14 refutation case — own {Y,Y,Y} + alternate {Y,Y,R} under G10 → YELLOW; pooled 2 reds → RED; no-alternate case; green_threshold NULL branch; ALE exclusion), `CounterScorerTest`, `GlobalScorerTest` (G1, G2–G4, G5–G12, G13–G15 shapes; ALE+FRB ignored from G5 on).

## Phase 3 — Age, derivation, situation

- [x] 3.1 `app/Services/Scoring/AgeResolver.php`: both `age_calculation_mode` arithmetics, corrected-age formula and its three gates (design D6; open Q1–Q2 defaults).
- [x] 3.2 `app/Services/Scoring/AgeGroupResolver.php`: range lookup, corrected-age substitution + finding, out-of-range and corrected-below-30 throws (D7, Q3).
- [x] 3.3 `app/Services/Scoring/AutoAnswerDeriver.php`: the three `auto_derivation_key` factors (thresholds `< 34`, `≤ 1500`, `< 16`, deriving BOTH yes and no), head-circumference band lookup with option-by-color selection (D9/D10), D-14 NULL semantics, override findings, `missing_percentile_reference` (REQ-SCO-004).
- [x] 3.4 `app/Services/Scoring/SituationResolver.php`: 7 states, sis_code map 1/2/3/4/5/6/6, severity order (D12, Q5–Q6).
- [x] 3.5 Unit tests: `AgeResolverTest` (mode table incl. borrow cases and gate boundaries 59/720/37), `AgeGroupResolverTest` (all 15 ranges, substitution G3→G1 premature case, throws), `AutoAnswerDeriverTest` (threshold edges 34/1500/16, NULL never derives + finding, override + finding, HC in/out/no-band by sex and month), `SituationResolverTest` (full matrix: 3 first-time + 4 transition states × sis codes; red→yellow = recovered_from_red).

## Phase 4 — Engine composition

- [x] 4.1 `app/Services/Scoring/ScoringEngine.php`: pipeline per design §4, constructor-injected collaborators with `new ScoringEngine()` default wiring; assembles `ScoringResult` with trace and ordered findings.
- [x] 4.2 Unit test `ScoringEngineTest`: end-to-end on hand-built RuleSet fixtures (both input paths `forGroup`/`forDates`); determinism scenario (identical serialized results on repeat, REQ-SCO-001); trace explainability (area counts + thresholds + matched sequence present, REQ-SCO-009); result serialization contains no UUIDs/timestamps/legacy ids.
- [x] 4.3 Unit test `ScoringPurityTest`: architecture scan of `app/Services/Scoring/` for the forbidden-token list (design §7) — fails naming file and token.

## Phase 5 — Oracle harness (the acceptance gate)

- [x] 5.1 Copy `openspec/changes/edi-instrument-catalog/_source/edi_oracle.jsonl` VERBATIM to `backend/Modules/Screening/tests/Fixtures/edi_oracle.jsonl` (assert 1,836 lines; no transformation of any kind).
- [x] 5.2 Author `backend/Modules/Screening/tests/Fixtures/oracle_known_discrepancies.json` with EXACTLY the two entries (`legacy_id` 791 and 2452, `G10`, `global_color`, expected `red`, computed `yellow`, reason `legacy_unexplained`) — compute each `signature` per design §5 and cite instrument design §14 in `notes`.
- [x] 5.3 `tests/Unit/OracleReplayTest.php` extending `PHPUnit\Framework\TestCase` (no Laravel boot, no DB): RuleSet via `CsvRuleSetLoader('edi-legacy-2013')`; input construction per design D15 (group by `group_legacy_id`, answers by item `legacy_id` + label, risk factors by legacy id, NO perinatal fields); per-answer `answer_colors` tripwire; then the assert battery of REQ-SCO-010: (1) unregistered color mismatches fail with a diff-friendly list; (2) ledger liveness both directions + signature + orphan checks (REQ-SCO-011); (3) quotas — G1–G4 spotless, overall ≥ 99.8% counting ledger entries as mismatches; (4) `expected_ale`/`expected_frb` exact on every row; (5) first-time `expected_situation` ∈ {1,2,3} through `SituationResolver(previous = null)`.
- [x] 5.4 Harness self-tests (can live in the same test class with synthetic in-memory fixtures): a synthetic unregistered mismatch fails; a ledger entry whose row now matches fails; a tampered signature fails.
- [x] 5.5 Run the replay and record the real numbers in the test output (expected: 1,834/1,836 = 99.89%, 228/228 in G15, zero mismatches in G1–G4). Any OTHER mismatch found during apply is investigated first (input construction bug vs engine bug) — the ledger is NOT the dumping ground for implementation errors.

## Phase 6 — Verification & closure

- [x] 6.1 Backend: `./vendor/bin/pint --dirty` clean; full suite `DB_HOST=127.0.0.1 DB_PORT=5451 DB_USERNAME=rodriguez DB_PASSWORD=password php artisan test --parallel --processes=10` green (reproduce any flaky failure in series before believing it — known tmpfs/parallel flakiness, AGENTS.md §5). The oracle replay and purity tests run inside this suite: that is the CI gate.
- [x] 6.2 Confirm module boundaries: `route:list` has no screening routes; `GET /api/menu` unchanged; no new rows in any seeder CSV; `git status` shows no frontend changes.
- [x] 6.3 Update `.project-context/modules.md`: `Screening` exists as code-only scoring engine (no tables yet), with a pointer to the next change for persistence/application.
- [x] 6.4 Handoff notes for the next change (screening application): consume `DatabaseRuleSetLoader` + `ScoringEngine`; create `screening` tables + `situation` central catalog (keys from `SituationKey`, `sis_code` column); persist `ScoringResult::toArray()` as the trace; wire module registration (menu/permissions) then.
