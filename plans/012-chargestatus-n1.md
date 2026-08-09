# Plan 012: Eliminate the N+1 in per-unit charge-status recompute

> **Executor instructions**: Follow step by step; verify each step. On a "STOP
> condition", stop and report. Update this plan's row in `plans/README.md`.
>
> **Drift check (run first)**: from the repo root,
> `cd backend && git diff --stat 803df3a..HEAD -- Modules/Collections/app/Services/ChargeStatusService.php Modules/Collections/app/Services/ChargeGenerationService.php`
> Mismatch with "Current state" = STOP.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED
- **Depends on**: plan 011 (the payment-invariant test) SHOULD exist first as a safety net; not strictly required but strongly recommended
- **Category**: perf
- **Planned at**: backend commit `803df3a`, 2026-06-11

## Why this matters

When charge statuses are recomputed — the daily `collections:refresh-statuses`
cron and every batch activation — the code loops over each affected unit and, per
unit, runs `Unit::find($unitId)` plus a `Charge::query()->where('unit_id', ...)`
to recompute that unit's aggregate "worst-wins" status. For a cluster with N
units that have open charges, that's ~2N extra queries per run. On a large tenant
(300+ units) this adds seconds of latency and pressures the Octane connection
pool on a scheduled job. The fix is to compute all unit aggregates in a couple of
set-based queries instead of a query-per-unit. **Behavior must not change** — only
the query count.

## Current state

- `backend/Modules/Collections/app/Services/ChargeStatusService.php`:
  - After `chunkById(500)` over charges, a loop
    `foreach (array_keys($affectedUnitIds))` calls `recomputeUnitAggregate($unitId)`.
  - `recomputeUnitAggregate(...)` (~lines 81–95) does `Unit::find($unitId)` then
    `Charge::query()->where('unit_id', $unitId)->...` to pick the "worst" status
    and writes it back to `unit.charge_status_id`.
- `backend/Modules/Collections/app/Services/ChargeGenerationService.php` (~line 152):
  - `foreach ($units as $unit) { recomputeUnitAggregate($unit->id); }` inside the
    batch-activation transaction — same per-unit pattern, scales with batch size.
- "Worst-wins" ordering: read `recomputeUnitAggregate` to learn the exact
  precedence among `charge_status` values (e.g. overdue > partial > due_soon >
  pending > completed). You MUST preserve this ordering exactly.

## Commands you will need

| Purpose          | Command (from `backend/`)                                  | Expected      |
|------------------|------------------------------------------------------------|---------------|
| Run Collections  | `php artisan test tests/Feature/Collections`               | all pass      |
| Run the cron     | `php artisan collections:refresh-statuses`                 | completes OK  |
| Count queries (manual) | wrap a call in `DB::enableQueryLog()` in a tinker/test and read `count(DB::getQueryLog())` | far fewer after |
| Format           | `vendor/bin/pint --dirty`                                  | exit 0        |

## Scope

**In scope**:
- `backend/Modules/Collections/app/Services/ChargeStatusService.php`
- `backend/Modules/Collections/app/Services/ChargeGenerationService.php` (update the call site to use the batch method)
- `backend/tests/Feature/Collections/` — add a test asserting the aggregate is still correct AND a query-count guard (see Test plan)

**Out of scope**:
- The per-charge status FSM transitions (pending→due_soon→overdue) — that logic
  is correct; only the per-unit aggregate recompute changes.
- The `charge_status` catalog or its UUID constants.
- Adding DB indexes (a possible follow-up — note it, don't do it here).

## Git workflow

- Branch: `advisor/012-chargestatus-n1`
- Conventional commit: `perf(collections): batch per-unit charge-status recompute (kill N+1)`
- No push/PR unless instructed. `backend/` is a submodule.

## Steps

### Step 1: Add a batch recompute method

In `ChargeStatusService`, add a method `recomputeUnitAggregates(array $unitIds): void`
that:
1. In ONE query, fetches the relevant `charge_status_id` per unit for all
   `$unitIds` — e.g. `Charge::query()->whereIn('unit_id', $unitIds)
   ->where('is_cancelled', false)->get(['unit_id', 'charge_status_id'])` (read the
   existing single-unit query to copy its exact filters — cancelled charges,
   etc.).
2. Groups in PHP by `unit_id` and computes the worst-wins status per unit using
   the SAME precedence as `recomputeUnitAggregate`.
3. Writes results back — prefer a single bulk update path. Options: a small number
   of `Unit::whereIn('unit_id', [...])->update(['charge_status_id' => $statusId])`
   calls grouped by resulting status (one UPDATE per distinct status value, ≤7
   total), or an `upsert`. Avoid one UPDATE per unit.

Keep `recomputeUnitAggregate($unitId)` working (delegate it to
`recomputeUnitAggregates([$unitId])`) so nothing else breaks.

### Step 2: Switch the two loops to the batch method

- In `ChargeStatusService`, replace the
  `foreach (array_keys($affectedUnitIds)) { recomputeUnitAggregate(...) }` loop
  with a single `recomputeUnitAggregates(array_keys($affectedUnitIds))`.
- In `ChargeGenerationService` (~line 152), replace the per-unit loop with
  `recomputeUnitAggregates($units->pluck('id')->all())`.

### Step 3: Verify behavior unchanged + query count down

- `php artisan test tests/Feature/Collections` → all pass (this is the behavior gate).
- The new query-count test (Test plan) shows the recompute issues a bounded
  number of queries regardless of unit count.

### Step 4: Format. `vendor/bin/pint --dirty` → exit 0.

## Test plan

Add `backend/tests/Feature/Collections/ChargeStatusBatchRecomputeTest.php`:
- **Correctness**: set up a tenant with 3 units, each with charges in different
  states (one overdue, one partial, one completed). Run the recompute. Assert each
  unit's `charge_status_id` equals the worst-wins expected value — IDENTICAL to
  what the old per-unit path would produce. (If feasible, assert it matches
  `recomputeUnitAggregate` called individually, to prove equivalence.)
- **Query budget**: wrap the batch recompute of those 3 units in
  `DB::enableQueryLog()` and assert `count(DB::getQueryLog())` is below a small
  constant (e.g. ≤ 10) and, critically, **does not grow with the number of
  units** (run with 3 units and with 6, assert the count is the same or within a
  fixed delta).

Model setup on existing Collections tests.

## Done criteria

ALL must hold (from `backend/`):

- [ ] `ChargeStatusService` has a `recomputeUnitAggregates(array)` that issues a bounded number of queries independent of unit count.
- [ ] Both former per-unit loops now call the batch method.
- [ ] `php artisan test tests/Feature/Collections` exits 0 (behavior preserved).
- [ ] The new test asserts worst-wins correctness AND a query count that doesn't scale with unit count.
- [ ] `php artisan collections:refresh-statuses` completes without error.
- [ ] `vendor/bin/pint --test` clean on in-scope files.
- [ ] No out-of-scope files modified.
- [ ] `plans/README.md` status row updated.

## STOP conditions

- The "Current state" excerpts don't match (drift).
- The worst-wins precedence in `recomputeUnitAggregate` is more complex than a
  simple status ordering (e.g. it depends on amounts or dates, not just status) —
  STOP and report; the batch version must replicate it exactly and you should
  confirm the rule before encoding it.
- The Collections suite goes red after the switch and the cause isn't an obvious
  query bug — revert to delegating the loop and report; correctness beats the perf win.

## Maintenance notes

- If a DB index on `charge(unit_id, charge_status_id, is_cancelled)` is missing,
  the batch SELECT benefits from one — flag for a follow-up index plan (don't add
  it here without schema review).
- A reviewer should diff the worst-wins result for a fixed fixture against the old
  per-unit path to confirm exact equivalence, and check the query-count test
  actually varies the unit count.
