# Anchored billing periods — `periodFor()` collapsed to ONE formula (2026-07-25)

## The product rule (owner, non-negotiable)
**"La fecha de inicio es la fecha de inicio."** No cadence may open its first period BEFORE
the `valid_from` the admin chose. This overrode the earlier "calendar-absolute grid"
architecture argument (ISO-week-style shared grids) that the implementation had accepted.

## What changed in `Modules/Collections/app/Services/PeriodicityScheduleResolver.php`
`periodFor($periodicityId, $periodStart, $generationDay)` is now literally one formula for all
six automatic cadences:

```
start = $periodStart->startOfDay()
end   = (start + interval) − 1 day
```

The `match` only supplies the interval + the informational `cycle`:

| Periodicity | interval | cycle |
|---|---|---|
| MONTHLY | `addMonthsNoOverflow(1)` | null |
| BIMONTHLY | `addMonthsNoOverflow(2)` | null |
| YEARLY | `addYearsNoOverflow(1)` | null |
| WEEKLY | `addDays(7)` | `isoWeek` |
| BIWEEKLY | `addDays(15)` | `start->day` |
| EVERY_TWO_DAYS | `addDays(2)` | `start->day` |
| EVENTUAL / unknown | — | returns `null` |

## DELETED (do not resurrect)
Four private helpers, gone with zero remaining callers:
`biweeklyPeriodBounds`, `everyTwoDaysPeriodBounds`, `biweeklyBounds`, `everyTwoDaysBounds`.

- **EVERY_TWO_DAYS epoch grid** (windows anchored on 1970-01-01, `k = [epoch+2k, epoch+2k+1]`):
  with `valid_from = 2026-07-01` (odd epoch parity) it opened the period on **2026-06-30** —
  a day before validity. That was the concrete violation.
- **BIWEEKLY calendar halves** (day 1→15, day 16→EOM): a `valid_from` on day 20 would have
  snapped back to day 16.

Frontend mirror `frontend/src/shared/utils/chargeLifecycle.ts::anchoredPeriodBounds` was
collapsed identically (its `biweeklyHalfContaining`, `everyTwoDaysWindowContaining`,
`daysSinceEpoch`, `MS_PER_DAY` and the `endOfMonth` import were removed — TS `noUnusedLocals`
would have failed the build otherwise).

## BIWEEKLY = 15 days — the reasoning, and its cost
"Quincenal" literally means a 15-day span, so `+15 days` is the honest anchored reading.
**An anchored period CANNOT reproduce the Mexican semi-monthly shape** (1→15 / 16→EOM) —
that shape is inherently calendar-bound and always risks starting before the anchor. A
formula that alternates `+15d / +1month−15d` would hit exactly 24 periods/year but needs the
period INDEX (parity), which `periodFor` does not receive: it only gets a start date.

Accepted cost: **24.33 periods per year instead of exactly 24** (calendar drift). Precedent:
WEEKLY already drifts (52 or 53 per year) and nobody objected. Low stakes today — BIWEEKLY has
zero live policies, is hidden in `PeriodicitySelector`, and its `CatalogSeeder` row is
currently commented out.

## Why the cutover is SAFE for the 2 live EVERY_TWO_DAYS QA policies (verified, not assumed)
The chain seed comes from the DB, not from a grid:
`GenerateMonthlyChargesCommand.php:251-253` → `seed = MAX(due_date) + 1 day` over non-template,
non-deleted, ACTIVE|CANCELLED batches; only a virgin policy falls back to `valid_from`.

For an already-chained EVERY_TWO_DAYS policy the prior period ended on an ODD epoch day, so
`seed = end + 1` has EVEN parity = a real grid boundary → old grid and new formula return the
**same** period. Pinned by
`PeriodicityScheduleResolverTest::test_every_two_days_chained_seeds_match_the_deleted_epoch_grid`.
Only the FIRST period of a brand-new policy changes.

Residual (harmless): a policy created BEFORE the change with a still-future `valid_from` has a
template batch whose `issued_at`/`due_date` were computed on the old grid; `cloneInitialBatch`
overwrites dates from the run, and the template is excluded from `MAX(due_date)`, so nothing
double-bills — the template's displayed dates are just stale by a day until it generates.

## Contract notes worth keeping
- `cycle` is **informational only** since the 2026-07-25 migration: the idempotency key is
  `charge_batch.period_start` (`charge_batch_period_start_unique` on
  `(tenant_id, charge_policy_id, period_start) WHERE deleted_at IS NULL AND period_start IS NOT NULL`).
  The old `charge_batch_period_unique` (year/month/cycle) was dropped.
- The FE `periodLabel.ts` reconstructs the EVERY_TWO_DAYS window from `year/month/cycle` where
  `cycle` = start day-of-month. Keeping `cycle = $start->day` for that cadence is what keeps
  the FE label correct — do not switch it to something else.
- Contiguity `start(n+1) = end(n) + 1` holds for ALL six cadences now, and is the invariant the
  tests assert instead of the deleted grid parity.

---

# Creation-time backlog: the chain is now SHARED with the cron (2026-07-26)

## The gap that was closed
The owner's rule "a RETROACTIVE `valid_from` generates every missing period, capped" had been
implemented ONLY in `GenerateMonthlyChargesCommand`. `ChargePolicyService::create` still
materialized exactly ONE period (`ChargeGenerationService::generatePeriodicInitial` = template +
one `cloneInitialBatch` + `activateBatch`, no loop). Real consequence: an every-2-days rule
starting 1-jul created on 26-jul showed ONE period, and draining the rest by hand meant running
the cron, which walks EVERY tenant and EVERY policy and generated unrelated policies' periods.

## What was extracted
NEW `Modules/Collections/app/Services/PeriodChainService.php` — the ONE chain implementation:
- `runsFor(policy, seed, today, cap, generationDay): PeriodRun[]` — PURE walk (was the command's
  private `chainRunsForPolicy`). Stops on: cap, `valid_to < run->issuedAt`, `today < issuedAt`.
  `cap <= 0` ⇒ empty array (that's how creation asks for "periods AFTER the one I hold").
- `materialize(policy, template, runs, ?Closure $log): ChargeBatch[]` — clone+activate loop with
  the `UniqueConstraintViolation` → lookup-by-`period_start` recovery, and the consolidated
  notification rule (`count($runs) > 1` ⇒ `activateBatch(notifyResidents: false)` for all +
  ONE `notifyConsolidatedChargesIssued`). Was the command's `generateChainForPolicy`.
- `generate(...)` = runsFor + materialize (the cron's entry point).
`$log` is an optional `Closure(string)`; the command passes
`fn ($m) => $this->info("[{$tenantLabel}] {$m}")`, creation passes nothing. **PHP has no
`$closure?->()`** — a nullsafe invoke is a parse error; the service defaults `$log` to a no-op.

DELETED: `ChargeGenerationService::generatePeriodicInitial()` (zero callers left). The command
lost its 2 private methods and now only owns SELECTION (tenants/policies/seed/cap).

## Creation flow now (`ChargePolicyService`)
`createInitialTemplate` runs in BOTH branches; only materialization differs.
- future `valid_from` → template only (unchanged).
- else → `materializeInitialPeriods()`: `runs = [$firstRun, ...runsFor(policy, firstRun.dueDate+1,
  today, cap-1, generationDay)]` then `materialize()`.
**The FIRST period stays UNCONDITIONAL** on purpose — do not "simplify" it into the chain. Two
real cases would silently stop billing: (a) `charge_generation_day = 10` + `valid_from = today`
⇒ `issuedAt` is in the future ⇒ chain returns `[]`; (b) `valid_to` earlier than the first
`issuedAt`. Both generate a period today; regressing that is invisible until a cluster complains.

## The cap: CHARGES, not periods (decided 2026-07-26)
Creation runs inside an HTTP request + Orion's transaction; the cron is CLI with no timeout. So:
`cap = max(1, min(config('collections.catchup_max_periods'=12), intdiv(config('collections.creation_max_charges'=600), unitCount)))`
`unitCount` = `TargetResolver::resolve(target)->count()` (new dep on `ChargePolicyService`).
⇒ ≤50 units: full 12 periods; 300 units: 2; 1000 units: 1 (floor keeps the first period).
Leftovers are NOT lost: the cron seeds from `MAX(due_date)+1` and drains contiguously with its
own larger cap. Queueing was rejected — this deployment has NO queue worker running, a queued
backlog would never materialize.
NEW config key `collections.creation_max_charges` (Modules/Collections/config/config.php).

## Tests
NEW `tests/Feature/Collections/ChargePolicyCreationBacklogTest.php` (13): completeness+contiguity,
2-day cadence stops at today, valid_from=today/future unchanged, eventual untouched, period
ceiling + cron continues without gap, charge budget shrinks cap, budget floor = 1 period,
valid_to stops, ISOLATION (other policy + other tenant unchanged — uses a low
`catchup_max_periods` so the neighbour keeps a KNOWN pending backlog; a global run would move it),
one consolidated notification for 6 periods, singular template for 1.
`ChargePolicyFirstRunTest::periodBatch` → `firstPeriodBatch` + `orderBy('period_start')`: it used
an UNORDERED `first()`, which after this change can match 6 rows and asserted by luck.
Suite: `Modules/Collections/tests` + `tests/Feature/Collections` = **356 passed / 0 failed**
(was 343; +13).
