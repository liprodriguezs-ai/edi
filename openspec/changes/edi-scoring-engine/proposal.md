# Proposal: EDI Scoring Engine

## Intent

The instrument catalog ships the rules as data and `instrument:verify` proves the content, but nothing can SCORE a screening: given a child's answers, no code produces the semaphore. This change builds the scoring engine as a **pure, deterministic, traceable function** — and its validation against the 1,836-screening production oracle. Engine and oracle harness are ONE delivery: the oracle IS the engine's acceptance test. The scoring model was already validated empirically (`edi-instrument-catalog/design.md` §13–§14, where **§14 corrects §13 and prevails**); this change implements that model, it does NOT re-derive it.

## Why now

- Next acyclic dependency: the screening application (persistence, endpoints, UI) cannot exist without the engine.
- The rule model was just validated at 1,834/1,836 (99.89%); implementing now converts that evidence into a permanent CI gate before the knowledge decays.
- Domain invariant 1 (result is ALWAYS computed server-side) has no implementation until this lands.

## Scope

### In Scope
- New nwidart module `Screening` as a **code-only skeleton**: no tables, no routes, no permissions, no menu rows.
- `Modules/Screening/app/Services/Scoring/ScoringEngine.php` (pure: no Eloquent, no `now()`, no `request()`) + collaborators: `AgeResolver`, `AgeGroupResolver`, `AutoAnswerDeriver`, `AreaScorer`, `CounterScorer`, `GlobalScorer`, `SituationResolver`, `ConditionEvaluator`; immutable VOs `ScoringInput`, `RuleSet`, `ScoringResult` (with trace).
- RuleSet loaders: `CsvRuleSetLoader` (reads the committed Instrument seed CSVs, DB-free) and `DatabaseRuleSetLoader` (Eloquent), plus a parity test proving CSV ≡ DB for both versions.
- Oracle replay harness: fixture copied verbatim into the module, known-discrepancies ledger (closed reason enum + signature), CI-breaking asserts in BOTH directions, quotas 100% (G1–G4) and ≥ 99.8% global.

### Out of Scope
- `screening` tables, endpoints, UI, application flow (next change).
- Any change to Instrument content, tables, or seeds.
- Historical import, `edi-2024` content, recalification workflow.

## Capabilities

### New Capabilities
- `scoring-engine`: server-side pure scoring of an EDI screening (areas, counters, global, situation, findings, trace) from raw answers + a version's `RuleSet`, validated by production-oracle replay.

### Modified Capabilities
- None.

## Approach

Implement **Model A** exactly as validated: alternate items pooled with the area's own items under the OWN group's thresholds; global result by first-match over `global_scoring_rule` sequences; a ~50-line interpreter for the closed 6-operator jsonb grammar. Rules enter through an immutable `RuleSet` VO so the oracle test runs without a database; a DB parity test keeps the CSV path honest. The discrepancy ledger holds exactly `legacy_id` 791 and 2452 with reason `legacy_unexplained`.

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Re-deriving rules instead of implementing the validated model | Low | Spec pins Model A + the §14 correction; oracle quota gates CI |
| Purity erosion (IO sneaking into the engine) | Med | Architecture test forbids Eloquent/`now()`/`request()` in the Scoring namespace |
| Discrepancy ledger becomes a cemetery | Med | Stale-entry assert breaks CI when a registered discrepancy stops reproducing |
| Situation `sis_code` semantics partially inferred (codes 4/5) | Med | 7-state model separated from export code; oracle first-time check (codes 1–3); open decision Q5 |

## Rollback Plan

Code-only module + test fixtures; no migrations, no seeder rows, no existing file altered beyond one `modules_statuses.json` line. Revert the PR(s); nothing consumes the engine yet.

## Dependencies

- `edi-instrument-catalog` delivered: both versions seeded, `instrument:verify` green (rules and CSVs are this engine's input).
- `edi-clinic-and-child-registry` delivered: D-14 (NULL perinatal = "se ignora") governs auto-derivation.
- Oracle fixture at `openspec/changes/edi-instrument-catalog/_source/edi_oracle.jsonl` (copied into the module by this change — change folders are archived, the module is the durable home).

## Success Criteria

- [ ] Oracle replay passes inside `php artisan test` with NO database: ≥ 1,834/1,836 color matches, exactly 2 ledger entries, 100% in groups 1–4, `expected_ale`/`expected_frb` equal on every row.
- [ ] Both harness asserts demonstrably fire: an injected new mismatch fails CI, and deleting/satisfying a ledger entry fails CI.
- [ ] `RuleSet` built from CSVs equals `RuleSet` built from the seeded DB for both versions.
- [ ] Purity architecture test green; zero new tables, routes, or menu entries.

## Proposal question round

Interactive round unavailable in this executor context — defaults are encoded in `design.md` §Open decisions; correct them before `sdd-apply`.

1. **Age arithmetic details**: exact `thirty_day_month` decomposition (borrow rules) and the "< 2 years" gate boundary are defined by us, unverifiable by the oracle (it carries no dates). Defaults: Q1/Q2.
2. **Corrected age out of range** (corrected < 30 days): typed "not applicable" exception vs clamping to G1. Default: exception (Q3).
3. **Head-circumference month basis**: chronological vs corrected months for the percentile lookup. Default: chronological (Q4).
4. **`sis_code` 4/5 assignment** (recovered-from-yellow = 4, recovered-from-red = 5) is inferred; only codes 1–3 are oracle-checkable. Default: keep (Q5).
5. **`previous red → current yellow`**: classified as `recovered_from_red` (improvement) — or unchanged rezago? Default: recovered (Q6).
