# Design: EDI Scoring Engine

Change: `edi-scoring-engine`

## 1. Context

The Instrument module delivers the rulebook: both versions seeded, published, hashed, `instrument:verify` green in CI. The scoring MODEL is no longer a hypothesis — it was validated by replaying all 1,836 production screenings against the seeded rules (`edi-instrument-catalog/design.md` §13–§14; **§14 corrects §13 and is authoritative**): pooled-alternate scoring (Model A) reproduces 1,834/1,836 = 99.89%, G15 is confirmed 228/228 under the G9–G14 rule, and exactly two cases (`legacy_id` 791, 2452) remain unexplained. This change TRANSCRIBES that model into a pure PHP engine and freezes the evidence as a permanent CI gate. It must not re-derive, "improve", or re-litigate the rules.

House patterns followed: plain constructor-injected service classes as in `Modules/Instrument/app/Services/` (`InstrumentContentHasher` is the tone reference: one responsibility, natural-key oriented, heavily doc-commented); statuses as string keys + class constants, never PHP enums (hard rule 4); English identifiers everywhere.

## 2. Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | New nwidart module `Screening`, **code-only skeleton**: `module.json`, provider, `app/Services/`, `tests/`. No migrations, models, controllers, routes, or menu/permission registration | The task location is `Modules/Screening/app/Services/Scoring/ScoringEngine.php` and the next change adds persistence to this same module. Registering menu/permissions now would advertise a module with no surface; the registration checklist runs in the application change |
| D2 | Purity contract for namespace `Modules\Screening\Services\Scoring`: no Eloquent, no `now()`, no `request()`, no `config()`/`env()`/container, no randomness. Enforced by an ARCHITECTURE TEST scanning the directory for forbidden tokens | "Pure function" must be a checked property, not an intention. The engine's determinism is what makes the oracle a valid acceptance test and future recomputation trustworthy |
| D3 | `RuleSet` is an immutable VO keyed by NATURAL keys (group codes, area codes, color keys, message keys, FRB codes) with `legacy_id` lookup maps for replay. Two loaders OUTSIDE the pure namespace: `CsvRuleSetLoader` (seed CSVs, zero DB) and `DatabaseRuleSetLoader` (Eloquent). A Feature parity test asserts CSV ≡ DB for both versions | The engine must run without infrastructure so the oracle test is a fast unit test; the parity test guarantees the unit-tested rules are byte-equal to what production will load. Natural keys keep the CSV path primary, mirror the content-hash philosophy, and keep UUIDs out of the pure core |
| D4 | **Model A pooling** (instrument §14): each semaphore area of the applied official group counts its own answered items PLUS the answered items of the same area in the group's alternate, and applies the OFFICIAL group's `area_scoring_rule`. The 59 alternate `area_scoring_rule` rows are loaded into `RuleSet::alternateAreaRules` as record but NEVER consulted by `AreaScorer` | 1,834/1,836 vs 97.22% for the alternate-alone model. §14 is explicit: the alternate rows stay in data as a faithful record of content, and "the scoring engine MUST implement Model A and MUST NOT consume them as a separate rule" |
| D5 | `ScoringInput` has two named constructors: `forGroup(groupCode, ...)` (explicit applied group; oracle replay and future recompute-from-stored) and `forDates(birthDate, testDate, ...)` (normal application). XOR-enforced in the constructor | Recomputing a historical screening must use its STORED applied group, never re-resolve dates (invariant 2's spirit: recompute with THAT version and THAT context). The oracle carries no dates, so the explicit path is first-class, not a test hack |
| D6 | `AgeResolver` per `age_calculation_mode`: `calendar` = exact day diff; `thirty_day_month` = decompose the calendar difference into (years, months, days) with day-borrow of 30, then `y×360 + m×30 + d`. Corrected age `= days − (40 − weeks) × 7` gated by age < 2 years AND weeks < 37 (the legacy days > 59 gate was removed 2026-10-21: not in the Manual EDI 2021 nor the DGIS instructivo). Two-years gate: `days < 720` in `thirty_day_month`; `test_date < birth_date + 24 calendar months` in `calendar` | The mode column exists precisely because legacy day counts differ from calendar diffs. The gates transcribe `.project-context/domain.md`. The oracle cannot validate any of this (it has no dates) — unit tests pin OUR definition and Q1/Q2 flag the unverified details |
| D7 | `AgeGroupResolver`: chronological days select the official group; if corrected age exists and lands in a DIFFERENT official group, substitute + finding. Chronological age outside 30–2159 → typed `ScreeningNotApplicableException`. Corrected age below 30 days → same exception (open Q3) | Substitution-with-finding is the documented domain rule. Out-of-range is a caller/product error the engine must refuse loudly, never clamp silently |
| D8 | `AutoAnswerDeriver` implements clinic D-14: NULL input derives NOTHING and is NOT "no" — the answer stays manual and a `unknown_perinatal_input` finding is recorded. Known input DERIVES BOTH WAYS (yes when past the threshold, no when not) and OVERRIDES a provided manual answer (finding `auto_answer_overrode_manual` when it actually changed one) | "Si el cliente los manda, se sobrescriben" (domain.md) + "never treat unknown as no" (clinic D-14). Deriving "no" from a known-safe value matters as much as deriving "yes" |
| D9 | Derived answers select the target option **by COLOR**, not label: head circumference in band → the EN item's GREEN option; out of band → its RED option; derived FRB answers are yes/no booleans (risk factors have no option rows) | Labels are verbatim data (`SI`/`NO`, could vary across versions); colors are the closed semantic vocabulary. EN items are verde-rojo by catalog invariant, so the mapping is total |
| D10 | Head-circumference TARGET ITEM: the RuleSet loader designates, per official group, the EN item with `sort_order = 3` (absent in G15, which has 2 EN items — consistent with its missing percentile coverage, instrument D19). Load-time validation: the designated item must be EN with green/red options; a TEST-ONLY tripwire asserts its question matches `/perímetro cefálico/i` in both versions | The catalog has no item-level derivation marker (only `risk_factor.auto_derivation_key` exists). Parsing item codes is banned (instrument D10); a hardcoded per-group code map would put instrument knowledge in engine code. The positional convention is data-driven, self-limiting (G15 naturally yields none), and the text tripwire makes it empirically safe. Q7 recommends a real `auto_derivation_key` column in a FUTURE instrument version |
| D11 | Color and situation values are string KEYS + class constants (`ResultColorKey::GREEN = 'green'`, `SituationKey::FIRST_TIME_RED = 'first_time_red'`), never PHP native enums. The `situation` CENTRAL catalog table (fixed UUIDs, seeded, `sis_code` column) is DEFERRED to the persistence change, which maps keys → rows | Hard rule 4 bans PHP enums; a catalog table now would violate this change's no-tables boundary. Keys are the stable contract between engine and future catalog (same pattern as `result_color.key`) |
| D12 | Situation model: 7 states (`first_time_green/yellow/red`, `recovered_from_yellow`, `recovered_from_red`, `unchanged`, `worsened`) with `sis_code` map 1/2/3/4/5/6/6 and severity order green < yellow < red. `recovered_from_red` covers red → yellow AND red → green | The legacy conflated "igual" and "empeoró" under code 6; the model separates them and keeps `sis_code` only for the official export. Codes 1–6 all occur in the oracle (`expected_situation`), and 1/2/3 are empirically checkable (first-time rows); 4/5 assignment and the red → yellow reading are Q5/Q6 |
| D13 | `ConditionEvaluator`: a closed interpreter over the 6-operator grammar (instrument §5.4), facts = {red_areas, yellow_areas, green_areas, alert_signs, risk_factors, per-area colors}. Unknown operator/set/color key → typed exception. No `eval`, no user input, exactly one operator key per node | Defense in depth: `instrument:verify` already validates seeded conditions statically, but the engine must fail loudly on anything outside the grammar, never evaluate to a silent false |
| D14 | Findings are a CLOSED list of keys on `ScoringFinding` (constants): `age_group_substituted_by_corrected_age`, `unknown_perinatal_input`, `auto_answer_overrode_manual`, `unanswered_item`, `missing_percentile_reference`, each with a small typed context array | Findings are clinical trace, not logs: closed vocabulary keeps them renderable, translatable, and assertable. Anything else (unknown item code, bad label, wrong group) is a caller ERROR → typed exception, not a finding |
| D15 | Oracle replay input construction: applied group from `group_legacy_id` (RuleSet legacy lookup); answers from `answers` via item `legacy_id` + label; risk factors from `risk_factors` (legacy id 1–7 → FRB1–7, SI/NO → yes/no); **perinatal fields NOT passed** (all NULL → deriver inert per D8, findings ignored by the harness). Per-answer tripwire: resolved option color must equal the row's `answer_colors` value | Production captured those answers WITH the legacy's own auto-fill already applied; feeding recorded answers untouched reproduces capture-time truth, which is what the 99.89% replay did. Passing perinatal values would let OUR deriver disagree with what production stored and contaminate the oracle. The tripwire re-proves the 0/42,897 seed result on every CI run |
| D16 | The oracle fixture is COPIED VERBATIM into `Modules/Screening/tests/Fixtures/edi_oracle.jsonl` (1,836 lines, PII-free). The harness asserts the line count | The current home (`openspec/changes/edi-instrument-catalog/_source/`) is an archive-bound change folder; tests must not reach into `openspec/`. The module is the durable owner. Note: the fixture's `birth_weight_grams` is MIXED-SCALE garbage (mostly grams×1000, some raw grams) — harmless because D15 never uses perinatal fields, but documented so nobody trusts it raw |
| D17 | Known-discrepancies ledger: JSON array, closed reason enum (initial sole member `legacy_unexplained`), per-entry signature = first 16 hex of SHA-256 over `"{legacy_id}|{field}|{expected}|{computed}|{reason}"`. Asserts BOTH ways: unregistered mismatch fails; registered entry that matches again, computes differently, is orphaned, or fails its signature also fails | The file must never become a cemetery: an entry is a live claim ("the engine computes X, production stored Y, and we know why we accept that") that CI re-verifies every run. Ships with exactly 791 and 2452 (G10, expected red, computed yellow — reproducible only via the G2–G4 escalation rule, per §14) |
| D18 | Quotas: groups 1–4 = 100% (zero mismatches AND zero ledger entries there); overall matches ≥ 99.8% of 1,836 with ledger entries COUNTING AS mismatches (expected: 1,834 = 99.89%). Counters `expected_ale`/`expected_frb` = 100%, no ledger tolerance. First-time rows (`expected_situation` ∈ {1,2,3}) must map through `SituationResolver(previous = null)` to the exact code | G1–4 are where ALE+FRB escalation lives — the most rule-dense strata must be perfect. Counters are deterministic counts; tolerating a counter mismatch would hide seed or mapping regressions. The first-time check is the only situation slice the fixture can validate (rows with codes 4–6 don't carry the previous color) |
| D19 | No trace table. `ScoringResult` (and its `toArray()`) IS the trace; the persistence change stores it as jsonb on `screening` | The task allows a minimal trace table only if justified — it is not: nothing consumes a persisted trace before the screening row itself exists, and adding tables here would break the code-only boundary |
| D20 | Oracle replays `edi-legacy-2013` ONLY. `edi-2022-2ed` is covered by the loader parity test (its rules are content-identical today; its `calendar` age mode is untouched by the oracle, which carries no dates) | The oracle is evidence about the LEGACY system. Binding it to the 2022 version would freeze that version to bug-compatibility forever; when 2022 rules legitimately diverge (Q2 of the instrument change), the oracle must not block them |

## 3. Value objects (all `final readonly`, in `app/Services/Scoring/Values/`)

| VO | Shape (essentials) |
|---|---|
| `ScoringInput` | `ageGroupCode?` XOR (`birthDate`, `testDate`); `sexKey?`; `gestationalAgeWeeks?`, `birthWeightGrams?`, `motherAgeAtBirthYears?`, `headCircumferenceMm?` (all `?int`, NULL = se ignora); `answers: ItemAnswer[]` (`ageGroupCode`, `itemCode`, `selectedLabel`); `riskFactorAnswers: array<string, bool|null>` keyed by FRB code; `previousResultColorKey?` (NULL = first screening) |
| `RuleSet` | `versionKey`, `ageCalculationMode`; `officialGroups` (code → min/max days, alternate code); `items` ((groupCode, itemCode) → area code, options label→color, `legacyId`); `areaRules` ((official group, area) → itemCount, redThreshold, greenThreshold?, allowsYellow); `alternateAreaRules` (record only, D4); `globalRules` (official group → ordered [sequence, condition array, colorKey, messageKey]); `riskFactors` (code → autoDerivationKey?, legacyId); `headCircumferenceBands` ((sexKey, ageMonths) → minMm, maxMm); `headCircumferenceItemByGroup` (D10); legacy lookups: `groupCodeByLegacyId`, `itemByLegacyId`, `riskFactorCodeByLegacyId` |
| `ScoringResult` | `appliedAgeGroupCode`, `ageGroupSubstituted`, `chronologicalAgeDays?`, `correctedAgeDays?`; `areaResults: AreaResult[]`; `alertSignCount`, `riskFactorCount`; `derivedAnswers[]`; `globalColorKey`, `matchedGlobalRuleSequence`, `messageKey`; `situation?` (`key`, `sisCode`); `findings: ScoringFinding[]`; `toArray(): array` (no UUIDs, no timestamps, no legacy ids) |
| `AreaResult` | `areaCode`, `colorKey`, `reds`, `yellows`, `greens`, `answeredOwn`, `answeredAlternate`, applied thresholds (`redThreshold`, `greenThreshold?`, `allowsYellow`) |
| `ScoringFinding` | `key` (closed, D14) + typed `context` array |
| Support | `ResultColorKey`, `SituationKey`, `SituationOutcome` (key + sisCode) — string constants, no enums (D11) |

## 4. Pipeline (`ScoringEngine::score`)

```
1. AgeResolver          dates → chronological/corrected days        (skipped on forGroup path)
2. AgeGroupResolver     days → applied official group (+ substitution finding)
3. AutoAnswerDeriver    perinatal input + RuleSet → derived FRB answers + HC item answer,
                        merged over manual answers (D8/D9/D10; findings)
4. AreaScorer           per semaphore area with items: pooled own+alternate counts →
                        4-branch contract with the OFFICIAL group's rule (D4)
5. CounterScorer        alert_signs (yellow ALE answers) + risk_factors (yes after derivation)
6. GlobalScorer         first-match over global rules by sequence, via ConditionEvaluator (D13)
7. SituationResolver    previousResultColorKey + global color → SituationOutcome (D12)
8. assemble             ScoringResult with trace + findings
```

Every collaborator is a stateless class in the pure namespace with a single public method taking values and returning values. `ScoringEngine` composes them via constructor injection (default-instantiable with `new ScoringEngine()` — no container needed in tests).

## 5. Oracle harness (definitive)

```
backend/Modules/Screening/tests/
├── Fixtures/
│   ├── edi_oracle.jsonl                  # 1,836 lines, verbatim copy (D16)
│   └── oracle_known_discrepancies.json   # exactly 2 entries at ship time (D17)
├── Unit/
│   ├── OracleReplayTest.php              # extends PHPUnit\Framework\TestCase — no Laravel boot, no DB
│   ├── ScoringPurityTest.php             # architecture scan (D2)
│   └── ... per-collaborator unit tests
└── Feature/
    └── RuleSetParityTest.php             # CSV loader ≡ DB loader, both versions (needs seeded DB)
```

Ledger entry shape (both shipped entries follow it):

```json
{
  "legacy_id": 791,
  "group_code": "G10",
  "field": "global_color",
  "expected": "red",
  "computed": "yellow",
  "reason": "legacy_unexplained",
  "signature": "<sha256_16('791|global_color|red|yellow|legacy_unexplained')>",
  "notes": "Reproducible only under the G2-G4 ALE+FRB escalation rule, which G9-G15 are confirmed to ignore. Pending a clinician's reading. See edi-instrument-catalog/design.md §14."
}
```

Harness flow per row: build `ScoringInput::forGroup` (D15) → `score()` → collect (computed color, ale, frb) → compare. Failures accumulate and report as a single diff-friendly list (legacy_id, group, field, expected, computed), then the quota asserts run (D18). The signature guard runs before replay so a tampered ledger fails fast.

## 6. RuleSet loaders

- `app/Services/RuleSetLoading/CsvRuleSetLoader.php`: reads `backend/Modules/Instrument/database/seeders/csv/{version-key}/` (`age_group.csv`, `screening_item.csv`, `area_scoring_rule.csv`, `global_scoring_rule.csv`, `risk_factor.csv`, `head_circumference_reference.csv`) with `fopen`/`fgetcsv` (quoted cells embed newlines — same gotcha the Instrument seeder hit). All columns are already natural keys; conditions decode with `json_decode(..., true)`. Test-only path coupling to the Instrument module's committed seed data is accepted (Q8): it is the single source of truth, and the parity test guards equivalence.
- `app/Services/RuleSetLoading/DatabaseRuleSetLoader.php`: Eloquent over the Instrument models, resolving UUIDs to keys exactly like `InstrumentContentHasher` does (color/area/position by relation, groups by code). Production callers (next change) use this one, memoized per version key.
- Both validate on build: every official group has a catch-all global rule, every area rule references an area with items, `headCircumferenceItemByGroup` designations satisfy D10. Violations throw — a RuleSet is either complete or absent.

## 7. Purity enforcement

`ScoringPurityTest` walks `Modules/Screening/app/Services/Scoring/` and fails on any of: `Illuminate\Database`, `Eloquent`, `DB::`, `now(`, `Carbon::now`, `CarbonImmutable::now`, `request(`, `config(`, `env(`, `app(`, `resolve(`, `rand(`, `mt_rand`, `random_`, `uniqid`, `time()`, `date(`. The RuleSet LOADERS live in `Services/RuleSetLoading/` precisely so the scan stays absolute (no exemption list). `DateTimeImmutable` values are allowed as INPUTS — constructing "now" is not.

## 8. Testing plan

| Layer | Coverage |
|---|---|
| Unit (pure, no boot) | `AgeResolverTest` (both modes, borrow cases, all three gates, boundary table), `AgeGroupResolverTest` (ranges, substitution, out-of-range throws), `AutoAnswerDeriverTest` (thresholds incl. edges 34/1500/16, NULL semantics, override findings, HC in/out/no-band, option-by-color), `AreaScorerTest` (4-branch matrix, Model A pooling incl. the §14 refutation case, alternate rules never consulted, ALE excluded), `CounterScorerTest`, `ConditionEvaluatorTest` (6 operators, nesting, closed-grammar throws), `GlobalScorerTest` (sequence order, first match, G1/G2–4/G5–15 shapes), `SituationResolverTest` (full 7-state × sis_code matrix), `ScoringEngineTest` (composition + determinism + trace shape), `ScoringPurityTest`, `OracleReplayTest` |
| Feature (DB) | `RuleSetParityTest` (both versions), module boot smoke |
| Harness self-tests | Assert directions: inject a synthetic mismatch → fails; mutate a ledger signature → fails; ledger entry for a matching row → fails |

## 9. Open decisions

| Q | Question | Default encoded in this design |
|---|---|---|
| Q1 | Exact `thirty_day_month` decomposition (borrow 30 vs real month lengths) — the oracle carries no dates, so this is untestable against production | Calendar decomposition with day-borrow of 30 (D6); confirm against the legacy age code when its source is available |
| Q2 | "< 2 years" gate boundary | `days < 720` (thirty_day_month) / `test_date < birth_date + 24 months` (calendar); confirm with the manual |
| Q3 | Corrected age below 30 days (extreme prematurity cases) | Typed `ScreeningNotApplicableException`, same as chronological out-of-range — never clamp to G1 silently |
| Q4 | Head-circumference month basis: chronological or corrected months? | Chronological months of the resolved age; flag for clinical confirmation |
| Q5 | `sis_code` 4/5 numeric assignment (recovered_from_yellow = 4, recovered_from_red = 5) — only codes 1–3 are oracle-checkable | Keep as designed (D12); confirm against the SIS export spec before the export change |
| Q6 | previous red → current yellow: `recovered_from_red` or still-rezago? | `recovered_from_red` (any improvement from red counts as recovery) |
| Q7 | The head-circumference item designation (D10) is a positional convention, not data | Accept for both frozen versions (tripwire-guarded); recommend `screening_item.auto_derivation_key` as NEW content in a FUTURE instrument version (`edi-2024`) |
| Q8 | `CsvRuleSetLoader` reads another module's seeder CSVs (cross-module test coupling) | Accepted: single source of truth + parity test; revisit only if the CSVs ever move |

## 10. Explicitly unchanged

Instrument tables, content, CSVs, seeder, verify command, hashes. All clinic/child tables. `modules.csv`, `permissions.csv`, `module_system.csv`, `SystemTableSeeder`, `RolePermissionTableSeeder`, `$mapperSelects`, `CatalogType`, `App.tsx` — zero registration rows (D1). No frontend code. The only touched existing file is `backend/modules_statuses.json` (+ `"Screening": true`).
