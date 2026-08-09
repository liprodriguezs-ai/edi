# Plan 076: Serialize the plan unit-limit check so concurrent creates cannot exceed the band

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> ```
> git -C backend diff --stat 46f8a07..HEAD -- \
>   Modules/SubscriptionPlan/app/Services/UnitLimitService.php \
>   Modules/Housing/app/Observers/UnitObserver.php \
>   Modules/Housing/app/Http/Controllers/UnitController.php
> ```
> On any change, compare against the "Current state" excerpts; on a mismatch,
> treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S–M
- **Risk**: MED — introduces a lock on a hot path (bulk unit import)
- **Depends on**: none
- **Category**: bug / billing integrity
- **Planned at**: monorepo `48f55be`, backend submodule `46f8a07`, 2026-08-04

## Why this matters

The plan's unit band is enforced by reading the current active unit count and
comparing `count + delta` against the limit — then inserting. Nothing serializes
the read against the insert, and no database constraint backs the band.

Two concurrent unit creations (two admins, two browser tabs, or two chunks of the
bulk importer) both read the same count, both pass the check, and both insert.
The tenant ends up above the band they pay for.

Per incident the overshoot is small — one or a few units. What makes it worth
fixing is that it is **silent and permanent**: the guard only runs at creation
time, so nothing ever detects or corrects the overshoot afterwards. Revenue
leakage that no report surfaces is worse than a loud failure.

## Current state

### The check-then-act

```php
// backend/Modules/SubscriptionPlan/app/Services/UnitLimitService.php:96-105
    /**
     * The tenant's active unit count. `Unit::count()` is scoped by both
     * `BelongsToTenant` and the default SoftDeletes scope, so this is exactly
     * `deleted_at IS NULL` for the current tenant — the same liveness rule
     * `unit_identity_unique` uses (REQ-06).
     */
    public function activeUnitCount(): int
    {
        return Unit::count();
    }
```

```php
// backend/Modules/SubscriptionPlan/app/Services/UnitLimitService.php:129-142
        $band = $this->resolveBand();
        $activeUnits = $this->activeUnitCount();
        ...
        $projected = $activeUnits + $delta;
        $allowed = $projected <= $band['limit'];
```

No `lockForUpdate()`, no transaction wrapping the read and the subsequent write.

### The choke points

```php
// backend/Modules/Housing/app/Observers/UnitObserver.php:28-34
    /**
     * unit-limit-enforcement: block a new unit BEFORE it is inserted when it
     * would push the tenant's active unit count above its plan's cap.
     */
    public function creating(Unit $unit): void
    {
        app(UnitLimitService::class)->assertCanIncrease(1);
    }
```

```php
// backend/Modules/Housing/app/Observers/UnitObserver.php:57-65
    public function restoring(Unit $unit): void
    {
        if (! $unit->trashed()) {
            return;
        }

        app(UnitLimitService::class)->assertCanIncrease(1);
    }
```

Plus a batch pre-flight at
`backend/Modules/Housing/app/Http/Controllers/UnitController.php:94`
(`assertCanIncrease(count($resources))`), which is also unlocked.

`creating` fires before the INSERT but inside whatever transaction the caller
opened — which for Orion CRUD is Orion's own transaction, and for the importer is
the chunk job's.

### There is no constraint behind it

`unit_identity_unique` enforces identity (`tenant_id, type, tower_id, floor,
number`), not a count. Nothing at the database level caps units per tenant.

### The fail-open branches (deliberate, keep them)

`checkIncrease()` returns `allowed: true` when tenancy is not initialized
(Decision 7) or the band is unresolvable (Decision 4). Both are documented
decisions. Your lock must not change either branch — in particular, do not try to
take a tenant lock when tenancy is not initialized.

### Repo conventions that apply

- Services in `Modules/<Module>/app/Services/`, resolved via `app(...)` from
  observers.
- Postgres is the database; `lockForUpdate()` maps to `SELECT ... FOR UPDATE`.
- Tenancy is single-database and row-level; the `tenant` table has one row per
  cluster, which makes it the natural lock target.
- Existing tests: `backend/Modules/SubscriptionPlan/tests/Unit/UnitLimitServiceTest.php`
  and `backend/Modules/SubscriptionPlan/tests/Feature/UnitLimitEnforcementTest.php`,
  plus `backend/Modules/DataImport/tests/Feature/ImportUnitLimitTest.php`.
  **Read all three before changing the service** — they are the behavior contract.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| No other test process | `pgrep -f "artisan test"` | no output |
| Unit-limit tests | `cd backend && php artisan test --filter=UnitLimit` | all pass |
| Import tests | `cd backend && php artisan test --filter=ImportUnitLimit` | all pass |
| Housing | `cd backend && php artisan test --filter=Housing` | all pass |
| Syntax | `cd backend && php -l <file>` | "No syntax errors detected" |
| Formatting | `cd backend && vendor/bin/pint --dirty` | exit 0 |

## Scope

**In scope**:
- `backend/Modules/SubscriptionPlan/app/Services/UnitLimitService.php`
- `backend/Modules/SubscriptionPlan/tests/Feature/UnitLimitConcurrencyTest.php` (create)

**Out of scope** (do NOT touch, even though they look related):
- `UnitObserver` — the choke points are in the right place; only the service's
  internals change.
- `UnitController::store` / batch store — the pre-flight stays as it is.
- `assertPlanFits()` / the downgrade guard — that is the *exit* door and a
  different code path. Not this plan.
- The fail-open branches for uninitialized tenancy and unresolvable band.
- Any migration adding a database-level count constraint. Postgres cannot express
  "at most N rows per tenant" without a trigger or a counter table; both are
  larger changes with their own failure modes.
- `DataImport`'s chunking strategy.

## Git workflow

- Branch: `advisor/076-unit-limit-race`
- Suggested commit: `fix(billing): serialize the unit-limit check against concurrent creates`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Take a per-tenant row lock around the count

In `UnitLimitService::checkIncrease()`, after the two fail-open guards and
**before** `activeUnitCount()`, acquire a row lock on the current tenant:

```php
        // Serialize concurrent increases for this tenant. Without it the count
        // read below and the caller's subsequent INSERT are a check-then-act:
        // two requests (two admins, two importer chunks) both read the same
        // count, both pass, and both insert, leaving the tenant above the band
        // it pays for — silently and permanently, since the guard only runs at
        // creation time. Postgres holds this row lock until the caller's
        // transaction commits, which is exactly the window that must be
        // serialized.
        Tenant::whereKey(tenant('id'))->lockForUpdate()->first();
```

Two things to get right:

- **The lock is only meaningful inside a transaction.** `creating` fires inside
  the caller's transaction (Orion wraps its CRUD; the importer wraps its chunk),
  so in practice there is one. But a bare `Unit::create()` from a command or
  tinker would have none, and the lock would be released immediately. Add a
  guard: if `DB::transactionLevel() === 0`, wrap nothing and **log a warning**
  rather than silently providing no protection — or document explicitly that the
  guarantee only holds for transactional callers. Choose one and say which in
  your report.
- **Lock ordering.** This lock is taken on the `tenant` row while the caller may
  already hold locks on `unit` rows. Check the other services that lock `tenant`
  (`grep -rn "Tenant::.*lockForUpdate" backend/`) and confirm none of them takes
  a `unit` lock first, which would create a deadlock cycle. Report what you find.

**Verify**:
```
cd backend && php -l Modules/SubscriptionPlan/app/Services/UnitLimitService.php
grep -n "lockForUpdate" backend/Modules/SubscriptionPlan/app/Services/UnitLimitService.php
```
→ no syntax errors; the lock appears once, after the fail-open guards.

### Step 2: Confirm the existing contract still holds

The three existing test files encode the intended behavior in detail. Run them
before writing anything new:

```
pgrep -f "artisan test"
cd backend && php artisan test --filter=UnitLimit
cd backend && php artisan test --filter=ImportUnitLimit
```

**Verify**: both pass, with the same counts as before your change. A failure here
means the lock changed observable behavior, which it should not.

### Step 3: Write the concurrency test

True concurrency is hard to test in PHPUnit against a shared database. Do **not**
attempt to fork processes. Instead assert the *mechanism*:

Create `backend/Modules/SubscriptionPlan/tests/Feature/UnitLimitConcurrencyTest.php`,
modelled on `backend/Modules/SubscriptionPlan/tests/Feature/UnitLimitEnforcementTest.php`.

Cases:

1. **The lock is actually issued.** Use `DB::listen()` to capture queries during
   a `Unit::create()` inside a transaction, and assert that a statement matching
   `/select .* from "tenant" .* for update/i` was executed **before** the unit
   count query. This is the assertion that would fail if someone removes the lock.
2. **A tenant at the band limit still rejects one more unit** (the pre-existing
   behavior, re-asserted here so this file is self-contained).
3. **A tenant below the limit still creates successfully** — the control case
   proving the lock did not turn into a blanket denial.
4. **Uninitialized tenancy still fails open and takes no lock.** Call
   `checkIncrease()` without initializing tenancy; assert `allowed === true` and
   that no `for update` query was issued. This protects Decision 7.

**Verify**: `cd backend && php artisan test --filter=UnitLimitConcurrency`
→ all pass.

### Step 4: Check the importer for lock contention

The bulk importer creates units in chunks. A per-tenant lock held for a whole
chunk serializes chunks against each other — which is correct, but could slow a
large import or, worse, hit a lock timeout.

Read `backend/Modules/DataImport/app/Jobs/` (find the chunk job with
`grep -rln "chunk" backend/Modules/DataImport/app/Jobs`) and determine how long a
chunk's transaction stays open. Report:

- the chunk size,
- whether the whole chunk runs in one transaction,
- whether chunk jobs can run concurrently for the same tenant.

If chunks for one tenant are already serialized by the queue, contention is a
non-issue. If they can run in parallel, say so — the lock is still correct, but
the operator should know import throughput may change.

**Verify**: `cd backend && php artisan test --filter=ImportUnitLimit` still passes,
and your report answers the three questions above.

### Step 5: Regression check

```
pgrep -f "artisan test"
cd backend && php artisan test --filter=Housing
cd backend && php artisan test --filter=Billing
```

One at a time.

**Verify**: both pass; report the counts.

## Test plan

- **New tests**: `UnitLimitConcurrencyTest` — 4 cases (lock is issued and
  ordered before the count; at-limit still rejects; below-limit still creates;
  uninitialized tenancy fails open with no lock).
- **Structural pattern**:
  `backend/Modules/SubscriptionPlan/tests/Feature/UnitLimitEnforcementTest.php`.
- **Honest limitation to state in your report**: these tests assert the lock is
  *taken*, not that two real concurrent requests are serialized. Proving the
  latter needs two connections and is out of scope for this suite. Say so; do
  not imply you proved the race is closed end-to-end.
- **Verification**: `--filter=UnitLimit`, `--filter=ImportUnitLimit`,
  `--filter=Housing`, `--filter=Billing`, each run separately.

## Done criteria

ALL must hold:

- [ ] `grep -c "lockForUpdate" backend/Modules/SubscriptionPlan/app/Services/UnitLimitService.php` returns `1`
- [ ] The lock is placed after both fail-open guards (verify by reading)
- [ ] The `DB::transactionLevel() === 0` decision is implemented and documented in the code
- [ ] Step 1's lock-ordering check result is in the report
- [ ] `cd backend && php artisan test --filter=UnitLimitConcurrency` passes
- [ ] `cd backend && php artisan test --filter=UnitLimit` passes
- [ ] `cd backend && php artisan test --filter=ImportUnitLimit` passes
- [ ] `cd backend && php artisan test --filter=Housing` passes
- [ ] Step 4's three importer questions are answered in the report
- [ ] `cd backend && vendor/bin/pint --dirty` exits 0
- [ ] `git -C backend status --porcelain` lists only the 2 files in Scope
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- **Any existing `UnitLimit` or `ImportUnitLimit` test fails after Step 1.** The
  lock must be behavior-neutral for a single request. A failure means it is not.
- **You find another service that locks a `unit` row and then a `tenant` row.**
  That is the opposite order from this plan's lock and creates a deadlock cycle.
  Report the file and stop — the fix would be to agree on a global lock order,
  which is a design decision.
- **The importer holds one transaction across an entire large chunk AND chunks
  can run concurrently for the same tenant.** A tenant-wide lock held that long
  could produce lock-wait timeouts on real imports. Report the numbers and stop
  before shipping; the answer may be to lock at a finer grain or to accept
  eventual reconciliation instead.
- **Tests fail with `SQLSTATE 42P01` / `42703` or catalog duplicate-key errors** —
  known shared-database / seeder problems per `AGENTS.md`. `pgrep`, kill, re-run
  once.
- **You are tempted to add a database trigger or counter table.** Out of scope;
  report the idea instead.

## Maintenance notes

- **This closes the create-time race, not the whole problem.** Because the guard
  only ever runs at creation, a tenant that somehow ends up over its band stays
  over it forever. A periodic reconciliation job that compares
  `activeUnitCount()` against the band and reports (not blocks) overshoot would
  catch drift from any source, including data fixes and restores. Recommend it;
  it is deliberately not in this plan.
- **What a reviewer should scrutinize**: lock placement relative to the fail-open
  guards. Taking the lock before them would attempt a `tenant` query with no
  tenancy initialized.
- **If lock contention becomes real**, the alternative is an advisory lock
  (`pg_advisory_xact_lock`) keyed on a hash of the tenant id, which does not
  touch a real row and is cheaper. Same semantics, less blocking of unrelated
  `tenant`-row writers. Worth switching to if the `tenant` row turns out to be
  written often.
