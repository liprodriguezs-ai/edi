# Plan 071: Bound `valid_from` so a retroactive charge policy cannot generate an unbounded overdue backlog

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> ```
> git -C backend diff --stat 46f8a07..HEAD -- \
>   Modules/Collections/app/Http/Requests/ChargePolicyCreateRequest.php \
>   Modules/Collections/app/Http/Requests/ChargePolicyUpdateRequest.php \
>   Modules/Collections/app/Models/Periodicity.php \
>   database/seeders/CatalogSeeder.php
> ```
> If any of those changed since this plan was written, compare the "Current
> state" excerpts against the live code before proceeding; on a mismatch, treat
> it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: monorepo `48f55be`, backend submodule `46f8a07`, 2026-08-04

## ⚠️ Read this before you start — what this plan is NOT

An audit initially flagged that `Periodicity::QA_ONLY` is defined but referenced
nowhere, and that the `CatalogSeeder` comment promises it is "gated out of
catalog serving, policy validation and the cron in production". That much is
factually true.

**The conclusion was wrong, and you must not act on it.** The gate used to exist
and was **deliberately removed** by the repository owner:

```
commit 459ed18  refactor(periodicity): remove QA-only cadence restrictions in production
Author: Pedro Rodriguez   Date: Fri Jul 24 17:01:20 2026

  Updated the handling of QA-only periodicities, specifically the EVERY_TWO_DAYS
  cadence, by removing restrictions that prevented their use in production
  environments. Adjusted the GenerateMonthlyChargesCommand,
  ChargePolicyCreateRequest, and ChargePolicyUpdateRequest to allow these
  periodicities...
```

and the model records the decision explicitly:

```php
// backend/Modules/Collections/app/Models/Periodicity.php:33-41
    /**
     * Compressed QA cadences. Currently NOT gated anywhere: the rows are seeded and
     * offered in every environment, production included, so the 2-day cycle can be
     * exercised against real data. Kept as the source list for re-gating later.
     *
     * @var array<int, string>
     */
    public const QA_ONLY = [self::EVERY_TWO_DAYS];
```

**Do NOT re-add the `Rule::notIn(Periodicity::QA_ONLY)` guard.** Doing so would
revert an intentional decision.

What remains genuinely broken, and what this plan fixes, is a **separate**
problem that the audit found alongside it: `valid_from` accepts any date in
history with no lower bound. Plus two pieces of now-stale text that made the
audit reach the wrong conclusion in the first place.

## Why this matters

`valid_from` is validated as `'date'` and nothing else. `2015-01-01` is accepted.

When a policy is created with a retroactive `valid_from`, the period chain
materializes every period from that date forward: up to 12 inline at creation
(`config('collections.catchup_max_periods', 12)`), then the daily cron drains
another batch every day until it catches up to today. Each period produces a
`charge_batch`, and each batch produces one `charge` per unit — **all of them
with a `due_date` in the past, so all of them land `OVERDUE` immediately**.

Since the delinquency-restriction feature shipped, `OVERDUE` is not cosmetic:
it blocks amenities, visitor QR codes and package pickup for the whole cluster.

And there is no clean way out. `ChargePolicyService::delete()` refuses to delete
a policy that already has live (non-cancelled) billing:

```php
// backend/Modules/Collections/app/Services/ChargePolicyService.php:372-401 (behavior)
// delete() checks for live billing with whereDoesntHave('batch', CANCELLED)
// and aborts 422 when any exists; it only cascades over templates and
// already-cancelled batches.
```

So a single mistyped year in a date field turns into manual cleanup of hundreds
of batches. The compressed 2-day QA cadence makes it worse (≈182 periods per
retroactive year instead of 12), but the exposure exists on the monthly cadence
too — this is not a QA-cadence problem.

## Current state

### The unbounded rule — create

```php
// backend/Modules/Collections/app/Http/Requests/ChargePolicyCreateRequest.php:56-58
            // periodic: window required. direct (EVENTUAL): window prohibited.
            'valid_from' => [Rule::requiredIf(fn (): bool => ! $isDirect), 'prohibited_if:periodicity_id,'.Periodicity::EVENTUAL, 'date'],
            'valid_to' => [Rule::requiredIf(fn (): bool => ! $isDirect), 'prohibited_if:periodicity_id,'.Periodicity::EVENTUAL, 'date', 'after_or_equal:valid_from'],
```

Note `valid_to` correctly constrains itself relative to `valid_from`
(`after_or_equal`), but nothing constrains `valid_from` in absolute terms.

### The unbounded rule — update

```php
// backend/Modules/Collections/app/Http/Requests/ChargePolicyUpdateRequest.php:63-64
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
```

Same gap. `ChargePolicyUpdateRequest`'s header docblock (`:23-26`) records that
`periodicity_id`, `target_type`, `target_selector` and `issued_at` are locked
once the policy has generated charges, while `name`, amounts, surcharge rules,
`valid_from` and `valid_to` stay editable — so a retroactive `valid_from` can
also be introduced by *editing* an existing policy, not only at creation.

### The chain that consumes it

```php
// backend/Modules/Collections/app/Services/ChargePolicyService.php:142-148
    private function resolveFirstRun(ChargePolicy $policy): PeriodRun
    {
        $anchor = $policy->valid_from
            ? Carbon::parse($policy->valid_from)
            : Carbon::today();
```

```php
// backend/Modules/Collections/app/Services/ChargePolicyService.php:182-191
        $runs = [
            $firstRun,
            ...$this->periodChain->runsFor(
                $policy,
                $firstRun->dueDate->copy()->addDay(),
                Carbon::today(),
                $cap - 1,
                $this->generationDay(),
            ),
        ];
```

There is precedent for exactly this kind of defensive bound in the cron, which
is the shape to mirror:

```php
// backend/Modules/Collections/app/Console/Commands/GenerateMonthlyChargesCommand.php:73-77
    /**
     * Defensive cap on the --from catch-up authorization: a typo like --from=1970-01-01
     * would otherwise declare an unbounded backlog depth.
     */
    private const MAX_CATCHUP_DAYS = 400;
```

The cron already defends against the operator typo. The HTTP path does not.

### The two stale texts to correct

**(a)** The seeder comment now contradicts the code:

```php
// backend/database/seeders/CatalogSeeder.php:278-281
            // QA-only compressed cadence: seeded everywhere (central-catalog contract) but
            // gated out of catalog serving, policy validation and the cron in production.
            ['id' => Periodicity::EVERY_TWO_DAYS, 'name' => 'Cada 2 días (pruebas)', 'order' => 6, 'is_active' => true, 'is_automatic' => true],
```

Nothing is gated any more. This comment is what made an auditor conclude there
was a missing guard.

**(b)** Both request classes still carry a validation message for a rule that no
longer exists and can never fire:

```php
// backend/Modules/Collections/app/Http/Requests/ChargePolicyCreateRequest.php:110
            'periodicity_id.not_in' => 'Esta periodicidad es de pruebas y no está disponible en producción.',
```
```php
// backend/Modules/Collections/app/Http/Requests/ChargePolicyUpdateRequest.php:117
            'periodicity_id.not_in' => 'Esta periodicidad es de pruebas y no está disponible en producción.',
```

### Repo conventions that apply

- FormRequest rules in Collections use the **array** style. Keep it.
- Validation messages are Spanish, in a `messages()` method.
- Money is `bigInteger` minor units — not relevant here, but do not "helpfully"
  touch the money rules while you are in these files.
- Tests live in `backend/tests/Feature/Collections/`.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| No other test process | `pgrep -f "artisan test"` | no output |
| Targeted tests | `cd backend && php artisan test --filter=ChargePolicy` | all pass |
| Full module | `cd backend && php artisan test --filter=Collections` | all pass |
| Syntax | `cd backend && php -l <file>` | "No syntax errors detected" |
| Formatting | `cd backend && vendor/bin/pint --dirty` | exit 0 |

## Scope

**In scope** (the only files you should modify or create):
- `backend/Modules/Collections/app/Http/Requests/ChargePolicyCreateRequest.php`
- `backend/Modules/Collections/app/Http/Requests/ChargePolicyUpdateRequest.php`
- `backend/database/seeders/CatalogSeeder.php` (comment text only — see Step 4)
- `backend/tests/Feature/Collections/ChargePolicyValidFromBoundTest.php` (create)

**Out of scope** (do NOT touch, even though they look related):
- `backend/Modules/Collections/app/Models/Periodicity.php` — the `QA_ONLY`
  constant stays exactly as it is, docblock included. It is the documented
  source list for a possible future re-gating.
- **Any re-introduction of `Rule::notIn(Periodicity::QA_ONLY)`** anywhere. See
  the warning at the top of this plan.
- `ChargePolicyService`, `PeriodChainService`, `PeriodicityScheduleResolver` —
  the chain logic is correct; this plan constrains its *input*.
- `GenerateMonthlyChargesCommand` — its `MAX_CATCHUP_DAYS` guard already works.
- `ChargePolicyService::delete()` — making deletion of a mis-created policy
  easier is a real idea but a different, riskier change (it would mean cascading
  over live billing). Not here.

## Git workflow

- Branch: `advisor/071-valid-from-floor`
- Suggested commits:
  - `fix(collections): bound charge policy valid_from to prevent retroactive backlogs`
  - `docs(collections): correct stale QA-cadence gating comments`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Decide the bound and record it as a constant

Add a class constant to `ChargePolicyCreateRequest` (and reference it from the
update request):

```php
    /**
     * Lower bound for valid_from. A policy anchored before this many days in the
     * past would make the period chain materialize a backlog of already-overdue
     * batches — one charge per unit per period, every one of them OVERDUE on
     * arrival, which (since the delinquency restrictions shipped) locks the whole
     * cluster out of amenities, visitor QR and package pickup. Mirrors the cron's
     * MAX_CATCHUP_DAYS defensive cap for the operator --from flag.
     */
    public const MAX_RETROACTIVE_DAYS = 400;
```

`400` is chosen to match `GenerateMonthlyChargesCommand::MAX_CATCHUP_DAYS`, so
the HTTP path and the CLI path agree on how far back a backlog may legitimately
reach. Do not pick a different number without saying why in your report.

**Verify**: `cd backend && php -l Modules/Collections/app/Http/Requests/ChargePolicyCreateRequest.php`
→ "No syntax errors detected"

### Step 2: Apply the bound in the create request

Extend the `valid_from` rule at `ChargePolicyCreateRequest.php:57` with an
`after_or_equal` bound computed from the constant. Keep every existing rule in
the array exactly as-is and append the new one:

```php
            'valid_from' => [
                Rule::requiredIf(fn (): bool => ! $isDirect),
                'prohibited_if:periodicity_id,'.Periodicity::EVENTUAL,
                'date',
                'after_or_equal:'.now()->subDays(self::MAX_RETROACTIVE_DAYS)->toDateString(),
            ],
```

Add the Spanish message:

```php
            'valid_from.after_or_equal' => 'La fecha de inicio no puede ser tan antigua: generaría un historial de cobros vencidos. Usa una fecha dentro de los últimos '.self::MAX_RETROACTIVE_DAYS.' días.',
```

**Verify**: `grep -n "after_or_equal" backend/Modules/Collections/app/Http/Requests/ChargePolicyCreateRequest.php`
→ shows both the new `valid_from` bound and the pre-existing `valid_to`
`after_or_equal:valid_from`.

### Step 3: Apply the same bound in the update request

Extend `ChargePolicyUpdateRequest.php:63` the same way, referencing
`ChargePolicyCreateRequest::MAX_RETROACTIVE_DAYS` so there is one source of
truth. Add the matching Spanish message.

Be careful not to disturb the conditional logic further down that file
(`withValidator`-style checks around `valid_from`/`valid_to` nullability at
`:140-160` and the periodicity lock at `:173-186`). Read those before editing so
your rule does not contradict them — in particular, `valid_from` may legitimately
be sent as `null`, and `after_or_equal` must not fire on null. The existing
`'nullable'` in the rule array handles that; confirm it stays **before** your new
rule.

**Verify**: `cd backend && php -l Modules/Collections/app/Http/Requests/ChargePolicyUpdateRequest.php`
→ "No syntax errors detected"

### Step 4: Correct the two stale texts

**(a)** In `backend/database/seeders/CatalogSeeder.php:278-279`, replace the
comment with one that matches reality:

```php
            // Compressed QA cadence, intentionally available in every environment
            // including production (see commit 459ed18 and the QA_ONLY docblock on
            // the Periodicity model) so the 2-day cycle can be exercised against
            // real data. NOT gated anywhere — do not re-add a production guard
            // without checking that decision first.
```

Do not change the seeded row itself.

**(b)** Delete the dead `'periodicity_id.not_in' => ...` message from **both**
`ChargePolicyCreateRequest.php:110` and `ChargePolicyUpdateRequest.php:117`. No
rule can produce a `not_in` failure on that field any more, so the message is
unreachable and misleads the next reader into thinking a guard exists.

**Verify**: `grep -rn "periodicity_id.not_in" backend/Modules/Collections`
→ no matches.
And `grep -n "gated out of catalog serving" backend/database/seeders/CatalogSeeder.php`
→ no matches.

### Step 5: Write the tests

Create `backend/tests/Feature/Collections/ChargePolicyValidFromBoundTest.php`,
modelled on `backend/tests/Feature/Collections/ChargePolicyScopeUpdateTest.php`
(read it first for the tenant/user/concept fixture and the authenticated request
helper it uses).

Cover:

1. **Create with a far-retroactive `valid_from` is rejected.** POST a valid
   periodic policy payload with `valid_from` set to 3 years ago → **422**, with
   the error keyed on `valid_from`. Assert `charge_policy` has no new row.
2. **Create with a `valid_from` just inside the bound is accepted.** Same payload
   with `valid_from = now()->subDays(30)` → 2xx, policy created. This is the
   control case: it proves the bound did not break legitimate mid-year policy
   creation, which is a normal operation.
3. **Update to a far-retroactive `valid_from` is rejected.** Create a valid
   policy, then PATCH it with a 3-years-ago `valid_from` → **422**, and assert
   the persisted `valid_from` is unchanged.
4. **A null `valid_from` on update still behaves as before.** PATCH with
   `valid_from = null` and assert the response is whatever the pre-existing
   nullability logic produces — read `ChargePolicyUpdateRequest`'s validator
   hooks first and assert their documented behavior, not a guess. The point of
   this case is to prove the new rule did not start firing on null.

**Verify**: `cd backend && php artisan test --filter=ChargePolicyValidFromBound`
→ all pass.

### Step 6: Confirm no regressions

```
pgrep -f "artisan test"    # must print nothing
cd backend && php artisan test --filter=Collections
```

**Verify**: passes, with the same counts as before plus your 4 new tests.

## Test plan

- **New tests**: `ChargePolicyValidFromBoundTest` — 4 cases (reject far-past on
  create, accept recent-past on create, reject far-past on update, null on
  update unchanged).
- **Structural pattern**: `backend/tests/Feature/Collections/ChargePolicyScopeUpdateTest.php`.
- **Why case 2 and case 4 matter as much as 1 and 3**: a bound that is too tight
  breaks legitimate policy creation, and an `after_or_equal` that fires on null
  breaks every update that clears the window. Both failure modes are invisible
  without an explicit control case.
- **Verification**: `php artisan test --filter=Collections` — all pass.

## Done criteria

ALL must hold:

- [ ] `grep -c "MAX_RETROACTIVE_DAYS" backend/Modules/Collections/app/Http/Requests/ChargePolicyCreateRequest.php` returns ≥ `3` (constant, rule, message)
- [ ] `grep -c "MAX_RETROACTIVE_DAYS" backend/Modules/Collections/app/Http/Requests/ChargePolicyUpdateRequest.php` returns ≥ `2`
- [ ] `grep -rn "periodicity_id.not_in" backend/Modules/Collections` returns no matches
- [ ] `grep -rn "Rule::notIn(Periodicity::QA_ONLY)\|notIn(Periodicity::QA_ONLY)" backend/` returns **no matches** (you must NOT have re-added the guard)
- [ ] `git -C backend diff -- Modules/Collections/app/Models/Periodicity.php` is **empty** (that file is untouched)
- [ ] `cd backend && php artisan test --filter=ChargePolicyValidFromBound` passes
- [ ] `cd backend && php artisan test --filter=Collections` passes
- [ ] `cd backend && vendor/bin/pint --dirty` exits 0
- [ ] `git -C backend status --porcelain` lists only the 4 files in Scope
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- **You conclude the `QA_ONLY` guard should be restored.** It is an intentional
  product decision (commit `459ed18`). Report your reasoning to the maintainer
  and stop; do not implement it.
- **An existing test fails asserting that a retroactive `valid_from` is
  accepted.** That would mean some flow legitimately backfills history — a
  migration path, an import, a seeder. Report which test; do not weaken it.
- **A production/staging policy already exists with a `valid_from` older than the
  bound.** The new rule only gates writes, so existing rows keep working — but
  any future *edit* of such a policy would now 422 even if the operator is not
  touching the date, because `sometimes` only skips absent keys. If the frontend
  sends the full object on update, this is a real breakage. **Check how
  `ChargePolicyFormModal` submits updates before shipping Step 3**, and report if
  it sends `valid_from` unconditionally.
- **Tests fail with `SQLSTATE 42P01` / `42703` or catalog duplicate-key errors.**
  Known shared-database / seeder-idempotency problems per `AGENTS.md`, not your
  regression. `pgrep -f "artisan test"`, kill stragglers, re-run once.

## Maintenance notes

- **The real remaining hole this plan does not close**: there is no way to undo a
  mis-created policy that already generated live billing —
  `ChargePolicyService::delete()` aborts 422 by design. If a bad policy does get
  through, cleanup is manual. A "cancel policy and all its batches" operation is
  a reasonable future feature and would pair well with this guard; it is
  deliberately out of scope because cascading over live billing is a much riskier
  change than validating an input.
- **What a reviewer should scrutinize**: the STOP condition about the frontend
  sending `valid_from` on every update. That is the one way this low-risk change
  could break a working screen.
- **If the 400-day bound proves wrong in practice**, change the constant in one
  place — that is why it is a constant and why the update request references the
  create request's copy rather than redeclaring it.
- **Do not let the `QA_ONLY` constant get deleted as "dead code"** in a future
  cleanup sweep. Its docblock explains that it is retained deliberately as the
  source list for re-gating. The comment added in Step 4(a) is the second
  breadcrumb.
