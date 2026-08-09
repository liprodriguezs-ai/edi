# Plan 075: Stop double-JSON-encoding `old_values` / `new_values` on every audited write inside a transaction

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C backend diff --stat 46f8a07..HEAD -- core/src/Models/Audit.php Modules/Audit/app/Http/Resources/AuditResource.php`
> If either changed since this plan was written, compare the "Current state"
> excerpts against the live code before proceeding; on a mismatch, treat it as a
> STOP condition.

## Status

- **Priority**: P2
- **Effort**: S (fix) / M (with historical repair)
- **Risk**: MED — touches a core model used by every audited model platform-wide
- **Depends on**: none
- **Category**: bug
- **Planned at**: monorepo `48f55be`, backend submodule `46f8a07`, 2026-08-04

## Why this matters

Every audited write in this codebase that happens inside a `DB::transaction()`
stores its audit diff **double-JSON-encoded**. Since essentially all mutations go
through a service that opens a transaction — and Orion wraps its own CRUD in one
too — this is the normal path, not an edge case. The `audits` table's compliance
trail is therefore corrupted platform-wide.

The bug is already known to two separate consumers, each of which carries its own
defensive re-decode to work around it. That is the real cost: the data is only
readable by code that knows the secret, and every new reader has to rediscover
it. The Complaint module's workaround docblock says so explicitly, and reports
that the root cause was deliberately left for a separate change — this plan.

## Current state

### The root cause

```php
// backend/core/src/Models/Audit.php:28-55
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function save(array $options = []): bool
    {
        $this->stampTenantId();

        if (DB::transactionLevel() > 0) {
            $attributes = $this->getAttributes();
            $connectionName = $this->getConnectionName() ?? DB::getDefaultConnection();
            DB::afterCommit(function () use ($attributes, $connectionName): void {
                $model = new static;
                $model->setConnection($connectionName);
                $model->forceFill($attributes)->saveQuietly();
            });
            $this->exists = true;

            return true;
        }

        return parent::save($options);
    }
```

The sequence:

1. `getAttributes()` returns the **raw** attribute array. Because `old_values`
   and `new_values` have an `'array'` cast, their raw values are already JSON
   **strings** at this point — the cast's setter encoded them when they were
   assigned.
2. Inside the deferred closure, `forceFill($attributes)` goes through
   `setAttribute()` for each key, which runs the `'array'` cast **again** on a
   value that is already a JSON string.
3. `saveQuietly()` persists the double-encoded result.

Outside a transaction (`transactionLevel() === 0`) the code takes
`parent::save()` and the values are correct — which is why the bug is invisible
in a simple test that does not wrap its mutation.

### Workaround #1 — the generic audit endpoint

```php
// backend/Modules/Audit/app/Http/Resources/AuditResource.php:20-21,30
        $data['old_values'] = $this->decodeJsonAttribute($data['old_values'] ?? null);
        $data['new_values'] = $this->decodeJsonAttribute($data['new_values'] ?? null);
    ...
    private function decodeJsonAttribute($value): array
```

### Workaround #2 — the Complaint history, which documents the whole thing

```php
// backend/Modules/Complaint/app/Services/ComplaintHistoryService.php:205-222
    /**
     * old_values/new_values SHOULD already be PHP arrays after the model's
     * 'array' cast — but a pre-existing bug in QCH\Core\Models\Audit::save()
     * DOUBLE-JSON-ENCODES them whenever the audited mutation happens inside a
     * DB::transaction() (the deferred DB::afterCommit() path re-serializes an
     * attribute that was already serialized once by the cast), which is the
     * norm across this codebase's service layer — every ComplaintService
     * mutation is an example. When that happens, the cast leaves a JSON
     * STRING sitting where an array was expected. This mirrors the exact
     * same defensive re-decode `AuditResource::decodeJsonAttribute()` already
     * applies for the generic /api/audit endpoint (not a new workaround, an
     * established one) — the root cause was reported separately rather than
     * fixed here (touches a shared core model used by every audited model
     * platform-wide, out of scope for this feature).
     *
     * @return array<string, mixed>
     */
    private function decodeValues(mixed $values): array
```

It is used at `ComplaintHistoryService.php:120`, `:162` and `:163`.

### Repo conventions that apply

- `backend/core/` is the `qch/core` package, included as a path repository inside
  the backend submodule — it is editable in place, and a change there affects
  every module.
- `BaseModel` implements `Auditable` and uses `OwenIt\Auditing\Auditable`; the
  `audits` table is shared (single-database tenancy) with a nullable `tenant_id`
  stamped by `stampTenantId()`.
- Tests: `backend/tests/Unit/` for pure model behavior,
  `backend/tests/Feature/` for anything hitting the database.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| No other test process | `pgrep -f "artisan test"` | no output |
| New test | `cd backend && php artisan test --filter=AuditTransactionEncoding` | all pass |
| Audit module | `cd backend && php artisan test --filter=Audit` | all pass |
| Complaint (consumer) | `cd backend && php artisan test --filter=Complaint` | all pass |
| Syntax | `cd backend && php -l <file>` | "No syntax errors detected" |
| Formatting | `cd backend && vendor/bin/pint --dirty` | exit 0 |

## Scope

**In scope**:
- `backend/core/src/Models/Audit.php`
- `backend/tests/Feature/Audit/AuditTransactionEncodingTest.php` (create — check
  whether `backend/tests/Feature/Audit/` exists; if not, create the directory)

**Out of scope** (do NOT touch, even though they look related):
- `backend/Modules/Audit/app/Http/Resources/AuditResource.php` — its
  `decodeJsonAttribute()` is defensive (`is_array()` first), so it keeps working
  correctly after the fix and is still needed for **historical** rows. Removing
  it would break reading every audit row written before this deploy.
- `backend/Modules/Complaint/app/Services/ComplaintHistoryService.php` — same
  reasoning; `decodeValues()` stays.
- **Any data-repair migration over the `audits` table.** Repairing history is a
  separate decision (volume, reversibility, whether stale diffs matter) and is
  explicitly deferred — see Maintenance notes.
- `stampTenantId()` and the tenancy behavior of this model.
- The `GlobalObserver` and `BaseModel`.

## Git workflow

- Branch: `advisor/075-audit-double-json`
- Suggested commit: `fix(core): stop double-encoding audit diffs written inside a transaction`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Write the failing test FIRST

This is a subtle encoding bug; a test that passes for the wrong reason is easy to
write. Establish the failure before touching the fix.

Create `backend/tests/Feature/Audit/AuditTransactionEncodingTest.php`. Find an
existing feature test that mutates an audited model inside a transaction to copy
its fixture — `grep -rln "audits\|Audit::" backend/tests/Feature | head` will
locate candidates.

Cases:

1. **Inside a transaction** (the bug). Wrap an update of any audited model in
   `DB::transaction(fn () => $model->update([...]))`. Then read the resulting
   `audits` row **through the model** and assert `is_array($audit->new_values)`
   and that a known key holds its literal value (not a JSON string).
   → This must **FAIL** before Step 2.
2. **Outside a transaction** (the control). Same update without a transaction.
   Assert the same things. → This must **PASS** before Step 2, proving the test
   itself is sound and the bug is transaction-specific.
3. **Raw column check.** Read the row with the query builder
   (`DB::table('audits')->find(...)`) and assert `json_decode($row->new_values, true)`
   yields the array directly — i.e. one level of encoding, not two.

**Verify**: `cd backend && php artisan test --filter=AuditTransactionEncoding`
→ case 1 FAILS, cases 2 and 3 behave as described. **Record the failure output
in your report** — it is the evidence the bug is real in this checkout.

### Step 2: Bypass the cast in the deferred write

In `Audit::save()`, replace `forceFill($attributes)` with
`setRawAttributes($attributes)`. `setRawAttributes()` assigns the array
directly to the model's attribute bag without running mutators or casts, which
is exactly right: `$attributes` came from `getAttributes()` and is already in
its persisted representation.

Add a docblock on `save()` explaining the constraint, so nobody "modernizes" it
back:

```php
    /**
     * Audit rows written inside a transaction are deferred to afterCommit so a
     * rolled-back mutation leaves no audit trail.
     *
     * The deferred write MUST use setRawAttributes(), not forceFill(): the
     * attributes captured by getAttributes() are already in their persisted
     * form, and old_values/new_values carry an 'array' cast. forceFill() would
     * run that cast a second time over an already-encoded JSON string, storing
     * a double-encoded diff. Two consumers still carry defensive re-decoders for
     * rows written before this was fixed (AuditResource::decodeJsonAttribute and
     * ComplaintHistoryService::decodeValues).
     */
```

**Verify**:
```
cd backend && php -l core/src/Models/Audit.php
grep -n "setRawAttributes" backend/core/src/Models/Audit.php
grep -c "forceFill" backend/core/src/Models/Audit.php
```
→ no syntax errors; `setRawAttributes` present; `forceFill` count is `0`.

### Step 3: Confirm the test now passes

```
cd backend && php artisan test --filter=AuditTransactionEncoding
```

**Verify**: all three cases pass, including case 1 which failed in Step 1.

### Step 4: Confirm the two workaround consumers still work

The re-decoders are defensive (`is_array()` first), so they should pass through
correctly-encoded values untouched. Prove it rather than assuming:

```
pgrep -f "artisan test"    # must print nothing
cd backend && php artisan test --filter=Audit
cd backend && php artisan test --filter=Complaint
```

Run one at a time.

**Verify**: both pass. If any Complaint history test asserts on the *shape* of a
decoded value, it should still pass — the decoder returns the same array either
way.

### Step 5: Broader regression check

Audit rows are written by every audited model, so the blast radius is wide even
though the change is two words.

```
pgrep -f "artisan test"
cd backend && php artisan test --filter=Collections
```

Then, one at a time, run at least two more areas that write audited models
heavily — e.g. `--filter=Housing` and `--filter=Billing`.

**Verify**: no new failures. Report the counts per area.

## Test plan

- **New tests**: `AuditTransactionEncodingTest` — 3 cases (in-transaction,
  out-of-transaction control, raw-column single-encoding check).
- **Structural pattern**: an existing feature test that touches audited models;
  locate with `grep -rln "audits\|Audit::" backend/tests/Feature`.
- **Why the raw-column case matters**: asserting only through the model would
  pass even with double encoding *if* a re-decoder ran somewhere in the path.
  Reading the column directly is what pins the actual storage format.
- **Why Step 1 must fail first**: without observing the failure, you cannot
  distinguish "fixed it" from "wrote a test that never exercised the bug".
- **Verification**: `php artisan test --filter=AuditTransactionEncoding`, then
  `--filter=Audit`, `--filter=Complaint`, `--filter=Collections`, each separately.

## Done criteria

ALL must hold:

- [ ] Step 1's failing-before output is recorded in the report
- [ ] `grep -c "forceFill" backend/core/src/Models/Audit.php` returns `0`
- [ ] `grep -c "setRawAttributes" backend/core/src/Models/Audit.php` returns `1`
- [ ] The explanatory docblock on `save()` is present
- [ ] `cd backend && php artisan test --filter=AuditTransactionEncoding` passes (all 3 cases)
- [ ] `cd backend && php artisan test --filter=Audit` passes
- [ ] `cd backend && php artisan test --filter=Complaint` passes
- [ ] `cd backend && php artisan test --filter=Collections` passes
- [ ] `AuditResource::decodeJsonAttribute` and `ComplaintHistoryService::decodeValues` are **unchanged** (`git -C backend diff --stat` shows neither file)
- [ ] `cd backend && vendor/bin/pint --dirty` exits 0
- [ ] `git -C backend status --porcelain` lists only the 2 files in Scope
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- **Step 1's case 1 PASSES before you make the fix.** That means the bug is not
  reproducible as described — either the code drifted, or your fixture does not
  actually run inside a transaction. Do not proceed to Step 2; report what you
  observed.
- **`setRawAttributes()` breaks `stampTenantId()` or the UUID assignment.**
  `setRawAttributes` bypasses mutators entirely; if the model relies on one for
  any attribute, that attribute would stop being set. Read the whole model
  before committing, and report anything that depends on a mutator.
- **Any test starts failing that asserts on `old_values`/`new_values` content.**
  Some test may have encoded the buggy double-encoded shape as its expectation.
  Report it — that is a real finding about what the tests were pinning — and do
  **not** revert the fix to make it pass.
- **You are tempted to also remove the two re-decoders.** Do not. Historical rows
  in any long-lived database are still double-encoded, and the decoders are what
  keeps them readable.
- **You are tempted to write a repair migration.** Out of scope — see Maintenance
  notes. Report the row count of the `audits` table if you want to inform that
  decision.
- **Tests fail with `SQLSTATE 42P01` / `42703` or catalog duplicate-key errors** —
  known shared-database / seeder problems per `AGENTS.md`. `pgrep -f "artisan
  test"`, kill stragglers, re-run once.

## Maintenance notes

- **Historical rows stay double-encoded.** This fix is forward-only. The two
  defensive decoders keep old rows readable, so nothing breaks — but any *new*
  consumer of the `audits` table must still tolerate both shapes until history is
  repaired or aged out. That is the argument for eventually writing a repair
  migration; it was deferred here because it needs a row count, a batching
  strategy, and idempotency, and because it is a data change rather than a code
  fix.
- **When history is repaired**, the two decoders can be deleted and the
  `ComplaintHistoryService::decodeValues` docblock removed. Until then, leave
  them and do not treat them as dead code.
- **What a reviewer should scrutinize**: that `setRawAttributes()` does not skip
  something else the model needs. It is a two-word change with a wide blast
  radius; the risk is not the encoding, it is what else `forceFill` was doing.
- **Why this was not fixed when it was found**: the Complaint module discovered
  it mid-feature and correctly chose not to touch a shared core model as a side
  effect of an unrelated change. That judgment was right; this plan is the
  follow-through it named.
