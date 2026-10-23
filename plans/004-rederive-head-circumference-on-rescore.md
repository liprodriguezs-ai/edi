# Plan 004: Re-derive the head-circumference answer when a screening is scored again after the measurement changes

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`. This plan touches the clinical scoring pipeline:
> favor STOPPING over guessing at every ambiguity.
>
> **Drift check (run first)**: the Screening module is currently UNTRACKED
> (uncommitted clinical core), so `git diff dbe3682..HEAD` cannot see it.
> Instead, compare every "Current state" excerpt below against the live
> files before starting; on any mismatch, STOP.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED (scoring pipeline; mitigated by an unusually strong safety net — see Test plan)
- **Depends on**: none technically; run after 001 so the suite commands are wrapped, and before any production scoring traffic
- **Category**: bug (clinical correctness)
- **Planned at**: commit `dbe3682`, 2026-08-25

## Why this matters

The EDI screening derives one answer automatically: the exploración-neurológica item backed by the head-circumference percentile band (needs the measurement + the child's sex + **chronological age in days**). On the FIRST score this works. But a screening that already has a score run takes the *re-score input path*, which carries the frozen age-group code and **no dates** — so the deriver receives `chronologicalAgeDays = null` and derives nothing. The answer that gets scored is then the **stale auto-derived row persisted by the first score**.

Concretely: a clinician corrects a mistyped head circumference on a scored screening (the update endpoint properly reverts it to draft), scores again — and the EN axis, potentially the global color and the follow-up conducts, are computed from the OLD measurement, silently, with no finding recorded. Clearing the measurement has the same defect: the stale derived answer keeps being scored. `AGENTS.md` names lossy head-circumference handling as a bug class this rewrite exists to eliminate.

Two changes fix it:
- **(a)** A repeat score against the screening's own version uses the dates-path input (which resolves ages and lets the deriver run). Group-freezing is preserved by an existing tripwire that throws if the date-resolved group differs from the stored one.
- **(b)** When `head_circumference_mm` changes (including to null), delete the stale `is_auto_derived` answer rows so a removed measurement doesn't leave a ghost answer.

## Current state

All paths relative to `backend/Modules/Screening/`.

- `app/Services/ScreeningScoreService.php:105-109` — path selection:

```php
            $isInitial = ScreeningScoreRun::where('screening_id', $locked->id)->doesntExist();

            $input = $isInitial
                ? $this->buildInitialInput($locked, $ruleSet, $appliedGroupCode, $answers, $groupCodeById, $version)
                : $this->buildRescoreInput($locked, $ruleSet, $appliedGroupCode, $answers, $groupCodeById, $version);
```

- `buildInitialInput` (ends ~:324-336) uses `ScoringInput::forDates(birthDate, testDate, ...)` and contains the group tripwire at `:315-320`:

```php
        if ($groupResolution['applied_group_code'] !== $appliedGroupCode) {
            throw new \RuntimeException(
                "Tripwire failed: date-resolved applied group '...' does not match ...");
        }
```

- `buildRescoreInput` (`:342-365`) uses `ScoringInput::forGroup(ageGroupCode: $appliedGroupCode, ...)` — no dates. It reads the frozen previous color directly (`previous_result_color_id`), which is correct for re-scores.
- `app/Services/Scoring/ScoringEngine.php:40-76` — on the `forGroup` branch `$chronologicalAgeDays` stays `null`; the deriver is called at `:73` with it.
- `app/Services/Scoring/AutoAnswerDeriver.php:145-150`:

```php
        if ($chronologicalAgeDays === null || $input->sexKey === null) {
            // No resolved age or sex to look a band up with (e.g. the
            // forGroup/replay path never carries a measurement anyway --
            // design D15). Nothing safe to derive.
            return [null, [], []];
        }
```

  NOTE the comment's assumption ("the forGroup path never carries a measurement") is false for the app's own repeat-score path — `buildRescoreInput` passes `headCircumferenceMm: $screening->head_circumference_mm` (`:360`).
- `ScoringEngine::mergeAnswers` (`:113-128`) — a derived answer REPLACES any input answer for the same item, so on the dates path a stale persisted row is overridden by the fresh derivation. This is why change (a) alone heals the "measurement changed" case; change (b) is needed for the "measurement cleared" case only.
- `app/Http/Controllers/ScreeningController.php:81-104` — `performUpdate` reverts a scored screening to draft when `head_circumference_mm` changes:

```php
        $entity->fill($validated);
        $headCircumferenceChanged = $entity->isDirty('head_circumference_mm');

        $entity->save();

        if ($headCircumferenceChanged && $entity->screening_status_id === ScreeningStatus::SCORED) {
            $entity->forceFill([
                'screening_status_id' => ScreeningStatus::DRAFT,
                ...
            ])->save();
        }
```

  It does NOT delete the auto-derived answer row. (Also note the revert only fires when status is SCORED — a draft-with-prior-runs screening that edits HC also needs the stale-row cleanup, which is why change (b) keys on "HC changed", not on status.)
- The stale rows are written by `ScreeningScoreService::persistDerivedAnswers` (called at `:486`) with `is_auto_derived = true`.
- Who else scores: `ScreeningLifecycleService::rescore` re-scores against a **chosen, possibly different** instrument version — that path must KEEP using `buildRescoreInput` (recomputing the group under another version would violate design D8/D10). `MigrateLegacyCommand` and the oracle replay score via the normal `score()` first-time path or pure engine `forGroup` inputs — first-time = unaffected; pure-engine replay carries no measurement (design D15) = unaffected.

## Commands you will need

Run from `backend/`, dev stack up (`./start.sh`); env prefix is mandatory:

```bash
ENVP="DB_HOST=127.0.0.1 DB_PORT=5451 DB_USERNAME=$USER DB_PASSWORD=password"
```

| Purpose | Command | Expected |
|---|---|---|
| Focused test file | `eval $ENVP php artisan test --compact Modules/Screening/tests/Feature/AutoDerivedAnswerTest.php` | pass |
| Oracle replay (behavior pin) | `eval $ENVP php artisan test --compact Modules/Screening/tests/Unit/OracleReplayTest.php` | pass, unchanged discrepancy count |
| Screening suite | `eval $ENVP php artisan test --compact Modules/Screening/tests` | all pass |
| Format | `./vendor/bin/pint --dirty` | clean |

## Scope

**In scope**:
- `backend/Modules/Screening/app/Services/ScreeningScoreService.php`
- `backend/Modules/Screening/app/Http/Controllers/ScreeningController.php` (performUpdate only)
- `backend/Modules/Screening/tests/Feature/AutoDerivedAnswerTest.php` (extend — this existing file is your test pattern AND home for the new cases)
- `plans/README.md` (status row)

**Out of scope** (do NOT touch):
- `app/Services/Scoring/**` — the pure engine (`ScoringEngine`, `AutoAnswerDeriver`, `mergeAnswers`, `ScoringInput`) stays byte-identical. The fix routes better inputs INTO the engine; it does not change the engine. (Exception: you may fix the stale half of the `AutoAnswerDeriver.php:146-148` comment — comment only.)
- `ScreeningLifecycleService::rescore` and `buildRescoreInput` itself — the different-version path keeps frozen-group semantics.
- `MigrateLegacyCommand`, `OracleReplayTest`, the known-discrepancy ledger, CSV loaders.
- `ScreeningAnswerService` (manual answers are unaffected).

## Git workflow

Working tree holds ~218 uncommitted files of unrelated work. No `git add`/`commit`/`stash`/branches. Leave edits uncommitted; report file list.

## Steps

### Step 1: Reproduce with failing tests (TDD)

Open `Modules/Screening/tests/Feature/AutoDerivedAnswerTest.php`, learn its setup (how it creates a child/visit/screening with a head circumference and asserts the derived answer/EN result). Add two cases:

1. `test_changing_head_circumference_after_scoring_rederives_the_answer`: create + score a screening whose HC lands in one percentile band; `PUT /api/screening/{id}` with an HC that lands in a DIFFERENT band (produces a different option/color for the derived item — pick values from the reference table the existing tests use); assert status reverted to draft; `POST .../score` again; assert the auto-derived `screening_answer` row's option (and the EN area result color, if the band difference implies it) reflects the NEW measurement.
2. `test_clearing_head_circumference_after_scoring_removes_the_derived_answer`: same setup; update HC to `null`; score again; assert NO `screening_answer` row with `is_auto_derived = true` exists for the screening and the result matches a never-measured screening (mirror whatever the existing "no measurement" case asserts).

**Verify**: run the file → the two new cases FAIL against current code (case 1 shows the stale option; case 2 shows the ghost row). Existing cases still pass. If a new case passes, STOP.

### Step 2: Change (a) — repeat scores on the screening's own version take the dates path

In `ScreeningScoreService` around `:105-109`, the repeat-score branch currently calls `buildRescoreInput(..., $version)`. In the `score()` flow, `$version` IS the screening's own `instrument_version` (confirm by reading how `$version` is resolved earlier in `score()` — STOP if it can differ there). Change the selection so `score()` always builds the dates-path input:

```php
            $isInitial = ScreeningScoreRun::where('screening_id', $locked->id)->doesntExist();

            // Both initial and repeat scores use the dates path so the
            // head-circumference answer is re-derived from the CURRENT
            // measurement (the tripwire keeps the applied group frozen).
            // buildRescoreInput remains for ScreeningLifecycleService::rescore,
            // where the target version may differ and the group must not be
            // re-resolved (D8/D10).
            $input = $this->buildInitialInput($locked, $ruleSet, $appliedGroupCode, $answers, $groupCodeById, $version);
```

Keep `$isInitial` — it still selects the run trigger (`TRIGGER_INITIAL` vs `TRIGGER_RESCORE`) at `:122`. Check `buildInitialInput` for first-time-only side effects: `resolveAndFreezePreviousColor` (`:409-437`) already short-circuits to the frozen value when `previous_screening_id`/`previous_result_color_id` is set — verify that guard exists; STOP if not. If `buildRescoreInput` is now referenced ONLY by the lifecycle rescore path, leave it (do not delete).

**Verify**: new case 1 passes. `eval $ENVP php artisan test --compact Modules/Screening/tests/Feature/ScreeningScoreTest.php` → all pass (determinism + gate ordering intact).

### Step 3: Change (b) — purge stale auto-derived rows when the measurement changes

In `ScreeningController::performUpdate`, inside the `if ($headCircumferenceChanged ...)` handling — but keyed on the change itself, not on the SCORED status — delete the stale rows:

```php
        if ($headCircumferenceChanged) {
            $entity->answers()->where('is_auto_derived', true)->delete();
        }
```

Place it so it runs for BOTH the scored→draft revert case and the still-draft-with-prior-runs case. Use the model relation the controller/model already expose (`Screening::answers()` — verify the relation name in `app/Models/Screening.php`; use `ScreeningAnswer::where('screening_id', ...)` if there is no relation).

**Verify**: new case 2 passes. Full file `AutoDerivedAnswerTest.php` → all pass.

### Step 4: Prove no behavioral drift anywhere else

**Verify** (in order):
1. `eval $ENVP php artisan test --compact Modules/Screening/tests/Unit/OracleReplayTest.php` → passes with the SAME registered-discrepancy count as before (2). Any change in oracle output = STOP.
2. `eval $ENVP php artisan test --compact Modules/Screening/tests` → all pass (includes `RuleSetParityTest`, `ForgedResultIsIgnoredTest`, lifecycle, rescore-version tests).
3. `./vendor/bin/pint --dirty` → clean.

## Test plan

- Two new Feature cases in `AutoDerivedAnswerTest.php` (Step 1) — the regression this plan fixes, both directions (changed / cleared).
- Behavior pin: `OracleReplayTest` (1,836 real screenings) and `ScreeningScoreTest::test_scoring_twice_without_changes_is_deterministic` must pass unchanged — they are the proof that (a) did not alter any result computed from unchanged inputs.

## Done criteria

- [ ] Both new tests exist in `AutoDerivedAnswerTest.php` and pass; report includes the red-run output from Step 1
- [ ] `OracleReplayTest` passes with an unchanged ledger (2 discrepancies)
- [ ] Full `Modules/Screening/tests` suite passes serially
- [ ] `git diff --stat` (working tree) shows only the two source files + one test file changed beyond pre-existing unrelated changes
- [ ] No file under `app/Services/Scoring/` modified except (optionally) the stale comment in `AutoAnswerDeriver.php`
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- Any "Current state" excerpt doesn't match the live file.
- In `score()`, `$version` can be an instrument version other than the screening's own (would make Step 2 unsafe).
- `resolveAndFreezePreviousColor` lacks the frozen-value short-circuit described above.
- The tripwire fires during tests after Step 2 (date-resolution disagrees with the stored group — that means real data exists where the frozen group can't be reproduced from dates; this plan's approach is then wrong and needs the alternative design: extend `ScoringInput::forGroup` to carry `chronologicalAgeDays`).
- `OracleReplayTest` output changes in any direction.
- A new test requires touching `ScoringEngine`/`AutoAnswerDeriver` beyond a comment.

## Maintenance notes

- Reviewer scrutiny: Step 2's core claim — "in `score()` the version is always the screening's own, so dates-path + tripwire preserves the frozen group" — deserves an explicit review pass; the STOP conditions encode its failure modes.
- Future interaction: if a `/rescore` against the SAME version is ever added to the lifecycle service, it inherits the stale-derivation bug unless it, too, routes through the dates path; leave a pointer comment on `buildRescoreInput`.
- Deferred: `CORRECTNESS-02` (silent `?? null` catalog lookups in `persistAuthoritativeResult`) is adjacent but separate — see plans/README.md backlog.
